<?php

namespace MediaWiki\Extension\SocialCards;

use RuntimeException;

class Store {
	public function __construct( private readonly string $directory ) {
	}

	public static function canonical( array $payload ): string {
		$sort = static function ( $value ) use ( &$sort ) {
			if ( !is_array( $value ) ) {
				return $value;
			}
			if ( !array_is_list( $value ) ) {
				ksort( $value, SORT_STRING );
			}
			return array_map( $sort, $value );
		};
		return json_encode( $sort( $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
	}

	public static function pngSize( string $bytes ): ?array {
		if ( strlen( $bytes ) > 8388608 || !str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) ) {
			return null;
		}
		$offset = 8;
		$hasData = false;
		while ( $offset + 12 <= strlen( $bytes ) ) {
			$length = unpack( 'N', substr( $bytes, $offset, 4 ) )[1];
			if ( $length > strlen( $bytes ) - $offset - 12 ) {
				return null;
			}
			$type = substr( $bytes, $offset + 4, 4 );
			$data = substr( $bytes, $offset + 4, $length + 4 );
			$crc = substr( $bytes, $offset + 8 + $length, 4 );
			if ( hash( 'crc32b', $data, true ) !== $crc ) {
				return null;
			}
			$offset += $length + 12;
			$hasData = $hasData || $type === 'IDAT';
			if ( $type === 'IEND' ) {
				if ( !$hasData || $offset !== strlen( $bytes ) ) {
					return null;
				}
				$size = getimagesizefromstring( $bytes );
				return $size && $size[0] > 0 && $size[1] > 0 ? [ $size[0], $size[1] ] : null;
			}
		}
		return null;
	}

	public function path( string $hash ): string {
		if ( !preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
			throw new RuntimeException( 'Invalid social card hash.' );
		}
		return "{$this->directory}/{$hash}.png";
	}

	public function manifest( int $pageId ): ?array {
		$path = "{$this->directory}/{$pageId}.json";
		if ( !is_file( $path ) ) {
			return null;
		}
		$manifest = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
		if ( !is_array( $manifest ) || !isset(
			$manifest['hash'], $manifest['revision'], $manifest['mode'],
			$manifest['width'], $manifest['height'], $manifest['title'], $manifest['description']
		) || !preg_match( '/^[a-f0-9]{64}$/D', $manifest['hash'] ) ) {
			throw new RuntimeException( 'Invalid social card manifest.' );
		}
		return $manifest;
	}

	public function cached( string $hash, int $width, int $height ): bool {
		$path = $this->path( $hash );
		return is_file( $path ) && filesize( $path ) <= 8388608 &&
			self::pngSize( file_get_contents( $path ) ) === [ $width, $height ];
	}

	public function publish( int $pageId, array $manifest, ?string $png ): void {
		if ( $png !== null ) {
			if ( self::pngSize( $png ) !== [ $manifest['width'], $manifest['height'] ] ) {
				throw new RuntimeException( 'Social card output is not a valid PNG of the expected size.' );
			}
			$this->atomicWrite( $this->path( $manifest['hash'] ), $png );
		}
		$this->atomicWrite( "{$this->directory}/{$pageId}.json", self::canonical( $manifest ) );
	}

	public function revoke( int $pageId ): void {
		$path = "{$this->directory}/{$pageId}.json";
		if ( is_file( $path ) && !unlink( $path ) ) {
			throw new RuntimeException( 'Unable to revoke social card publication.' );
		}
	}

	public function locked( int $pageId, callable $callback ) {
		if ( !is_dir( $this->directory ) && !mkdir( $this->directory, 0700, true ) && !is_dir( $this->directory ) ) {
			throw new RuntimeException( 'Unable to create social card storage.' );
		}
		$lock = fopen( "{$this->directory}/{$pageId}.lock", 'c' );
		if ( !$lock ) {
			throw new RuntimeException( 'Unable to open social card lock.' );
		}
		$deadline = microtime( true ) + 5;
		try {
			while ( !flock( $lock, LOCK_EX | LOCK_NB ) ) {
				if ( microtime( true ) >= $deadline ) {
					throw new RuntimeException( 'Social card lock timed out.' );
				}
				usleep( 10000 );
			}
			return $callback();
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function atomicWrite( string $path, string $bytes ): void {
		$temporary = tempnam( $this->directory, '.card-' );
		if ( $temporary === false ) {
			throw new RuntimeException( 'Unable to create social card temporary file.' );
		}
		try {
			if ( file_put_contents( $temporary, $bytes ) !== strlen( $bytes ) || !rename( $temporary, $path ) ) {
				throw new RuntimeException( 'Unable to persist social card.' );
			}
		} finally {
			if ( is_file( $temporary ) ) {
				unlink( $temporary );
			}
		}
	}
}
