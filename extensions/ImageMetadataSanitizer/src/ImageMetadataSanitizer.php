<?php

namespace MediaWiki\Extension\ImageMetadataSanitizer;

class ImageMetadataSanitizer {
	private const MAX_COMMAND_OUTPUT_BYTES = 65536;

	private const METADATA_ARGUMENTS = [
		'-EXIF:all',
		'-XMP:all',
		'-IPTC:all',
		'-MakerNotes:all',
		'-Comment',
		'-Author',
		'-Artist',
		'-Creator',
		'-OwnerName',
		'-SerialNumber',
		'-LensSerialNumber',
		'-UserComment',
		'-Description',
		'-Title',
		'-Subject',
		'-Keywords',
		'-ThumbnailImage',
		'-PreviewImage',
		'-JpgFromRaw',
	];

	public function __construct(
		private readonly string $exifToolPath,
		private readonly string $imageMagickPath,
		private readonly int $commandTimeout
	) {
	}

	public function sanitize( string $path, string $mime ): bool {
		if ( !str_starts_with( $mime, 'image/' ) || $mime === 'image/svg+xml' ) {
			return false;
		}

		if ( !is_file( $path ) || !is_readable( $path ) || !is_writable( $path ) ) {
			throw new SanitizationException( 'The upload temporary file is not accessible.' );
		}

		if ( !$this->hasPrivateMetadata( $path ) ) {
			return false;
		}

		$orientation = trim( $this->runCommand( [
			$this->exifToolPath,
			'-s3',
			'-n',
			'-EXIF:Orientation',
			$path,
		] ) );

		if ( $orientation !== '' && $orientation !== '1' ) {
			$this->runCommand( [
				$this->imageMagickPath,
				'-auto-orient',
				$path,
			] );
		}

		$this->runCommand( [
			$this->exifToolPath,
			'-overwrite_original',
			'-all=',
			'-tagsFromFile',
			'@',
			'-ICC_Profile',
			$path,
		] );

		clearstatcache( true, $path );

		if ( !is_file( $path ) || filesize( $path ) === 0 ) {
			throw new SanitizationException( 'Metadata removal produced an empty file.' );
		}

		$outputMime = trim( $this->runCommand( [
			$this->exifToolPath,
			'-s3',
			'-MIMEType',
			$path,
		] ) );

		if ( !str_starts_with( $outputMime, 'image/' ) ) {
			throw new SanitizationException( 'Metadata removal produced an invalid image.' );
		}

		if ( $this->hasPrivateMetadata( $path ) ) {
			throw new SanitizationException( 'Private metadata remained after sanitization.' );
		}

		return true;
	}

	private function hasPrivateMetadata( string $path ): bool {
		$output = $this->runCommand( array_merge(
			[
				$this->exifToolPath,
				'-json',
				'-groupNames',
				'-short',
			],
			self::METADATA_ARGUMENTS,
			[ $path ]
		) );

		$records = json_decode( $output, true );
		if ( !is_array( $records ) || !isset( $records[0] ) || !is_array( $records[0] ) ) {
			throw new SanitizationException( 'ExifTool returned invalid metadata output.' );
		}

		$metadata = $records[0];
		unset( $metadata['SourceFile'] );

		return $metadata !== [];
	}

	private function runCommand( array $command ): string {
		if ( $this->commandTimeout < 1 ) {
			throw new SanitizationException( 'The command timeout must be positive.' );
		}

		$descriptors = [
			0 => [ 'file', '/dev/null', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		];
		$process = proc_open( $command, $descriptors, $pipes );

		if ( !is_resource( $process ) ) {
			throw new SanitizationException( 'Unable to start image metadata tooling.' );
		}

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$stdout = '';
		$stderr = '';
		$deadline = microtime( true ) + $this->commandTimeout;
		$exitCode = null;

		try {
			do {
				$stdout = $this->appendOutput( $stdout, stream_get_contents( $pipes[1] ) );
				$stderr = $this->appendOutput( $stderr, stream_get_contents( $pipes[2] ) );
				$status = proc_get_status( $process );

				if ( !$status['running'] ) {
					$exitCode = $status['exitcode'];
					break;
				}

				if ( microtime( true ) >= $deadline ) {
					proc_terminate( $process, 9 );
					throw new SanitizationException( 'Image metadata processing timed out.' );
				}

				usleep( 10000 );
			} while ( true );

			$stdout = $this->appendOutput( $stdout, stream_get_contents( $pipes[1] ) );
			$stderr = $this->appendOutput( $stderr, stream_get_contents( $pipes[2] ) );
		} finally {
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$closeCode = proc_close( $process );
			if ( $exitCode === null || $exitCode === -1 ) {
				$exitCode = $closeCode;
			}
		}

		if ( $exitCode !== 0 ) {
			$details = trim( $stderr );
			throw new SanitizationException(
				$details === ''
					? 'Image metadata tooling failed.'
					: 'Image metadata tooling failed: ' . $details
			);
		}

		return $stdout;
	}

	private function appendOutput( string $current, string|false $additional ): string {
		if ( $additional === false || $additional === '' ) {
			return $current;
		}

		$output = $current . $additional;
		if ( strlen( $output ) > self::MAX_COMMAND_OUTPUT_BYTES ) {
			throw new SanitizationException( 'Image metadata tooling produced excessive output.' );
		}

		return $output;
	}
}
