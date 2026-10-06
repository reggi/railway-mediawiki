<?php

use MediaWiki\Extension\ImageMetadataSanitizer\ImageMetadataSanitizer;

require_once __DIR__ . '/../src/SanitizationException.php';
require_once __DIR__ . '/../src/ImageMetadataSanitizer.php';

$temporaryDirectory = sys_get_temp_dir() . '/image-metadata-sanitizer-' . bin2hex( random_bytes( 8 ) );
$imagePath = $temporaryDirectory . '/oriented.jpg';

if ( !mkdir( $temporaryDirectory, 0700 ) && !is_dir( $temporaryDirectory ) ) {
	throw new RuntimeException( 'Unable to create the test directory.' );
}

try {
	run( [
		'/usr/bin/convert',
		'-size',
		'2x3',
		'xc:red',
		$imagePath,
	] );
	run( [
		'/usr/bin/exiftool',
		'-overwrite_original',
		'-Artist=Private Person',
		'-GPSLatitude=40.7128',
		'-GPSLatitudeRef=N',
		'-GPSLongitude=74.0060',
		'-GPSLongitudeRef=W',
		'-Orientation#=6',
		'-XMP-dc:Creator=Private Person',
		'-IPTC:Keywords=private',
		$imagePath,
	] );

	$sanitizer = new ImageMetadataSanitizer( '/usr/bin/exiftool', '/usr/bin/mogrify', 30 );

	if ( !$sanitizer->sanitize( $imagePath, 'image/jpeg' ) ) {
		throw new RuntimeException( 'The test image was not sanitized.' );
	}

	$metadata = run( [
		'/usr/bin/exiftool',
		'-json',
		'-EXIF:all',
		'-XMP:all',
		'-IPTC:all',
		$imagePath,
	] );
	$records = json_decode( $metadata, true, 512, JSON_THROW_ON_ERROR );
	$remaining = $records[0];
	unset( $remaining['SourceFile'] );

	if ( $remaining !== [] ) {
		throw new RuntimeException( 'Private metadata remained after sanitization.' );
	}

	$imageSize = getimagesize( $imagePath );
	if ( $imageSize === false || $imageSize[0] !== 3 || $imageSize[1] !== 2 ) {
		throw new RuntimeException( 'The image orientation was not normalized.' );
	}

	run( [
		'/usr/bin/exiftool',
		'-overwrite_original',
		'-Artist=Private Person',
		$imagePath,
	] );

	try {
		( new ImageMetadataSanitizer( '/bin/false', '/usr/bin/mogrify', 30 ) )
			->sanitize( $imagePath, 'image/jpeg' );
		throw new RuntimeException( 'A tooling failure did not reject the image.' );
	} catch ( MediaWiki\Extension\ImageMetadataSanitizer\SanitizationException ) {
	}

	echo "Image metadata sanitizer integration test passed.\n";
} finally {
	if ( is_file( $imagePath ) ) {
		unlink( $imagePath );
	}
	if ( is_dir( $temporaryDirectory ) ) {
		rmdir( $temporaryDirectory );
	}
}

function run( array $command ): string {
	$descriptors = [
		0 => [ 'file', '/dev/null', 'r' ],
		1 => [ 'pipe', 'w' ],
		2 => [ 'pipe', 'w' ],
	];
	$process = proc_open( $command, $descriptors, $pipes );

	if ( !is_resource( $process ) ) {
		throw new RuntimeException( 'Unable to start test command.' );
	}

	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exitCode = proc_close( $process );

	if ( $exitCode !== 0 ) {
		throw new RuntimeException( trim( $stderr ) ?: 'Test command failed.' );
	}

	return $stdout;
}
