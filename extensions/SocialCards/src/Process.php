<?php

namespace MediaWiki\Extension\SocialCards;

use RuntimeException;

class Process {
	public function __construct( private readonly int $timeout = 15 ) {
	}

	public function run( array $command, string $input = '', int $maxOutput = 8388608 ): string {
		if ( strlen( $input ) > 4194304 || $this->timeout < 1 ) {
			throw new RuntimeException( 'Social card process input or timeout is invalid.' );
		}
		$process = proc_open( $command, [
			0 => [ 'pipe', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		], $pipes );
		if ( !is_resource( $process ) ) {
			throw new RuntimeException( 'Unable to start social card process.' );
		}
		foreach ( $pipes as $pipe ) {
			stream_set_blocking( $pipe, false );
		}
		$stdout = '';
		$stderr = '';
		$offset = 0;
		$stdinOpen = true;
		$exitCode = -1;
		$deadline = microtime( true ) + $this->timeout;
		try {
			do {
				if ( $stdinOpen ) {
					if ( $offset < strlen( $input ) ) {
						$written = fwrite( $pipes[0], substr( $input, $offset, 65536 ) );
						if ( $written === false ) {
							throw new RuntimeException( 'Unable to write social card payload.' );
						}
						$offset += $written;
					} else {
						fclose( $pipes[0] );
						$stdinOpen = false;
					}
				}
				$stdout .= stream_get_contents( $pipes[1] );
				$stderr .= stream_get_contents( $pipes[2] );
				if ( strlen( $stdout ) > $maxOutput || strlen( $stderr ) > 65536 ) {
					throw new RuntimeException( 'Social card process output exceeded its limit.' );
				}
				$status = proc_get_status( $process );
				if ( !$status['running'] ) {
					$exitCode = $status['exitcode'];
					break;
				}
				if ( microtime( true ) >= $deadline ) {
					throw new RuntimeException( 'Social card process timed out.' );
				}
				usleep( 1000 );
			} while ( true );
			$stdout .= stream_get_contents( $pipes[1] );
			$stderr .= stream_get_contents( $pipes[2] );
			if ( strlen( $stdout ) > $maxOutput || strlen( $stderr ) > 65536 ) {
				throw new RuntimeException( 'Social card process output exceeded its limit.' );
			}
		} finally {
			if ( proc_get_status( $process )['running'] ) {
				proc_terminate( $process, 9 );
			}
			foreach ( $pipes as $pipe ) {
				if ( is_resource( $pipe ) ) {
					fclose( $pipe );
				}
			}
			$closed = proc_close( $process );
			if ( $exitCode === -1 ) {
				$exitCode = $closed;
			}
		}
		if ( $exitCode !== 0 ) {
			throw new RuntimeException( "Social card process exited with status {$exitCode}." );
		}
		return $stdout;
	}
}
