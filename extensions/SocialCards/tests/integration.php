<?php

use MediaWiki\Extension\SocialCards\Extractor;
use MediaWiki\Extension\SocialCards\Process;
use MediaWiki\Extension\SocialCards\Store;

require_once __DIR__ . '/../src/Extractor.php';
require_once __DIR__ . '/../src/Process.php';
require_once __DIR__ . '/../src/Store.php';

$check = static function ( bool $condition, string $message ): void {
	if ( !$condition ) {
		throw new RuntimeException( $message );
	}
};
$fails = static function ( callable $callback ) use ( $check ): void {
	$failed = false;
	try {
		$callback();
	} catch ( RuntimeException $error ) {
		$failed = true;
	}
	$check( $failed, 'Expected an explicit failure.' );
};

$html = '<div class="mw-parser-output"><table class="wikitable" style="float: right; width: 300px">'
	. '<tr><td><a class="mw-file-description" href="/wiki/File:Example_image.png"><img src="/images/example.png"></a></td></tr>'
	. '<tr><td><p>Image caption, not the introduction.</p></td></tr><tr><th>Type</th><td>Demonstration</td></tr></table>'
	. '<p><b>Example subject</b> is an article.<sup>[1]</sup></p><p>More lead text.</p>'
	. '<h2>Background</h2><p>Body content.</p><img src="/images/unrelated.png"></div>';
$extractor = new Extractor();
$expected = [
	'description' => 'Example subject is an article.',
	'label' => 'Demonstration',
	'image' => 'File:Example_image.png',
];
$check( $extractor->extract( $html ) === $expected, 'Legacy infobox/lead extraction failed.' );
$check(
	$extractor->extract( str_replace( 'class="wikitable"', 'class="infobox"', $html ) ) === $expected,
	'Standard infobox extraction failed.'
);
$check(
	$extractor->extract( '<p>Über café &amp; résumé.</p><h2>Body</h2><p>Later.</p>' ) === [
		'description' => 'Über café & résumé.', 'label' => 'Article', 'image' => null,
	],
	'No-image Unicode extraction failed.'
);
$check( $extractor->extract( '<h2>Body</h2><p>Not an intro.</p>' )['description'] === '', 'Body became the lead.' );
$check( mb_strlen( Extractor::text( str_repeat( 'a', 1000 ), 240 ) ) === 240, 'Text limit failed.' );
$check(
	Store::canonical( [ 'z' => [ 'b' => 1, 'a' => 2 ], 'a' => [ 3, 2 ] ] ) ===
	Store::canonical( [ 'a' => [ 3, 2 ], 'z' => [ 'a' => 2, 'b' => 1 ] ] ),
	'Canonical object sorting failed.'
);
$check( Store::canonical( [ 1, 2 ] ) !== Store::canonical( [ 2, 1 ] ), 'Array ordering was lost.' );

$process = new Process();
$fingerprint = trim( $process->run( [ '/generate', '--fingerprint' ], '', 100 ) );
$check( preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) === 1, 'Invalid renderer fingerprint.' );
$payload = Store::canonical( [
	'schema' => 1, 'renderer' => $fingerprint, 'width' => 1200, 'height' => 630,
	'branding' => 'wiki.reggi.com', 'title' => 'Example article',
	'description' => 'An introduction extracted from a wiki article.',
	'image' => null,
] );
$png = $process->run( [ '/generate' ], $payload );
$check( Store::pngSize( $png ) === [ 1200, 630 ], 'Renderer did not produce the required PNG.' );
$check( Store::pngSize( substr( $png, 0, -5 ) ) === null, 'Truncated PNG was accepted.' );
$corrupt = $png;
$corrupt[100] = chr( ord( $corrupt[100] ) ^ 1 );
$check( Store::pngSize( $corrupt ) === null, 'Corrupted PNG was accepted.' );
$fails( fn () => $process->run( [ '/generate' ], '{' ) );
$fails( fn () => ( new Process( 1 ) )->run( [ PHP_BINARY, '-r', 'sleep(3);' ] ) );
$fails( fn () => $process->run( [ PHP_BINARY, '-r', 'echo str_repeat("x", 1000);' ], '', 10 ) );
$fails( fn () => $process->run( [ PHP_BINARY, '-r', 'exit(7);' ] ) );
$echo = $process->run( [
	PHP_BINARY, '-r', 'while (!feof(STDIN)) { echo fread(STDIN, 8192); }',
], str_repeat( 'x', 1000000 ) );
$check( strlen( $echo ) === 1000000, 'Large simultaneous stdin/stdout processing deadlocked or truncated.' );

$directory = sys_get_temp_dir() . '/social-card-tests-' . bin2hex( random_bytes( 6 ) );
$store = new Store( $directory );
$hash = hash( 'sha256', $payload );
try {
	$store->locked( 1, static function () use ( $store, $hash, $png ): void {
		$store->publish( 1, [
			'hash' => $hash, 'revision' => 1, 'mode' => 'generated',
			'width' => 1200, 'height' => 630, 'title' => 'Example', 'description' => 'Intro',
		], $png );
	} );
	$check( $store->cached( $hash, 1200, 630 ), 'Generated PNG was not cached.' );
	$check( !$store->cached( $hash, 630, 1200 ), 'Wrong dimensions were accepted.' );
	$check( $store->manifest( 1 )['hash'] === $hash, 'Manifest was not persisted.' );
	unlink( $store->path( $hash ) );
	$check( !$store->cached( $hash, 1200, 630 ), 'Missing PNG was treated as cached.' );
	$store->revoke( 1 );
	$check( $store->manifest( 1 ) === null, 'Publication was not revoked.' );
	$fails( fn () => $store->path( '../untrusted' ) );
} finally {
	foreach ( glob( $directory . '/*' ) as $file ) {
		unlink( $file );
	}
	rmdir( $directory );
}

echo "SocialCards integration tests passed.\n";
