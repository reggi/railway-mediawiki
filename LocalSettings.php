<?php

if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

$requiredEnvironmentVariable = static function ( string $name ): string {
	$value = getenv( $name );

	if ( $value === false || $value === '' ) {
		throw new RuntimeException( "Required environment variable {$name} is not set." );
	}

	return $value;
};

$environmentVariable = static function ( string $name, string $default ): string {
	$value = getenv( $name );
	return $value === false || $value === '' ? $default : $value;
};

$railwayPublicDomain = getenv( 'RAILWAY_PUBLIC_DOMAIN' );
$defaultServer = $railwayPublicDomain ? "https://{$railwayPublicDomain}" : '';

$wgSitename = $environmentVariable( 'MW_SITE_NAME', 'MediaWiki' );
$wgMetaNamespace = str_replace( ' ', '_', $wgSitename );
$wgScriptPath = '';
$wgResourceBasePath = $wgScriptPath;
$wgArticlePath = '/wiki/$1';
$wgUsePathInfo = true;
$wgServer = rtrim( $environmentVariable( 'MW_SERVER', $defaultServer ), '/' );

if ( $wgServer === '' ) {
	throw new RuntimeException( 'Set MW_SERVER or generate a Railway public domain.' );
}

$wgCanonicalServer = $wgServer;
$wgLanguageCode = $environmentVariable( 'MW_LANGUAGE_CODE', 'en' );
$wgLocaltimezone = $environmentVariable( 'MW_TIMEZONE', 'UTC' );

$wgDBtype = 'postgres';
$wgDBserver = $requiredEnvironmentVariable( 'PGHOST' );
$wgDBport = $requiredEnvironmentVariable( 'PGPORT' );
$wgDBname = $requiredEnvironmentVariable( 'PGDATABASE' );
$wgDBuser = $requiredEnvironmentVariable( 'PGUSER' );
$wgDBpassword = $requiredEnvironmentVariable( 'PGPASSWORD' );
$wgDBprefix = $environmentVariable( 'MW_DB_PREFIX', '' );
$wgDBmwschema = $environmentVariable( 'MW_DB_SCHEMA', 'mediawiki' );

$wgEnableUploads = true;
$wgUploadDirectory = '/var/www/html/images';
$wgUploadPath = '/images';
$wgFileExtensions[] = 'mp4';

wfLoadExtension( 'ImageMetadataSanitizer' );
wfLoadExtension( 'Cite' );
$wgLocalFileRepo['class'] = MediaWiki\Extension\ImageMetadataSanitizer\SanitizingLocalRepo::class;

$wgSecretKey = $requiredEnvironmentVariable( 'MW_SECRET_KEY' );
$wgAuthenticationTokenVersion = $environmentVariable( 'MW_AUTH_TOKEN_VERSION', '1' );
$wgUpgradeKey = $requiredEnvironmentVariable( 'MW_UPGRADE_KEY' );

$wgGroupPermissions['*']['read'] = false;
$wgGroupPermissions['*']['edit'] = false;
$wgGroupPermissions['*']['createaccount'] = false;
$wgGroupPermissions['user']['read'] = true;
$wgGroupPermissions['user']['edit'] = true;
$wgWhitelistRead = [ 'Special:UserLogin' ];
$wgWhitelistReadRegexp = [ '/^Public:/i' ];

define( 'NS_PUBLIC', 3000 );
define( 'NS_PUBLIC_TALK', 3001 );
$wgExtraNamespaces[NS_PUBLIC] = 'Public';
$wgExtraNamespaces[NS_PUBLIC_TALK] = 'Public_talk';

$talkNamespaces = [
	NS_TALK,
	NS_USER_TALK,
	NS_PROJECT_TALK,
	NS_FILE_TALK,
	NS_MEDIAWIKI_TALK,
	NS_TEMPLATE_TALK,
	NS_HELP_TALK,
	NS_CATEGORY_TALK,
	NS_PUBLIC_TALK,
];

foreach ( $talkNamespaces as $talkNamespace ) {
	$wgNamespaceProtection[$talkNamespace] = [ 'edit-talk' ];
}

$wgHooks['SkinTemplateNavigation::Universal'][] = static function (
	$skinTemplate,
	array &$links
): void {
	unset( $links['namespaces']['talk'] );
};

$wgHooks['ParserFirstCallInit'][] = static function (
	MediaWiki\Parser\Parser $parser
): void {
	$parser->setHook( 'localvideo', static function ( $input, array $args ): string {
		$booleanArgument = static function (
			string $name,
			bool $default
		) use ( $args ): bool {
			if ( !array_key_exists( $name, $args ) ) {
				return $default;
			}

			return !in_array(
				strtolower( trim( (string)$args[$name] ) ),
				[ '0', 'false', 'no', 'off' ],
				true
			);
		};

		$filename = trim( (string)( $args['file'] ?? '' ) );
		$width = max( 160, min( 1280, (int)( $args['width'] ?? 720 ) ) );
		$viewportWidth = max(
			10,
			min( 100, (int)( $args['viewport-width'] ?? 50 ) )
		);
		$minimumWidth = min( 280, $width );
		$autoplay = $booleanArgument( 'autoplay', false );
		$loop = $booleanArgument( 'loop', false );
		$muted = $autoplay || $booleanArgument( 'muted', false );
		$controls = $booleanArgument( 'controls', true );
		$file = MediaWiki\MediaWikiServices::getInstance()
			->getRepoGroup()
			->findFile( $filename );

		if ( !$file || $file->getMimeType() !== 'video/mp4' ) {
			return MediaWiki\Html\Html::element(
				'span',
				[ 'class' => 'error' ],
				'Local MP4 video not found.'
			);
		}

		return MediaWiki\Html\Html::rawElement(
			'video',
			[
				'class' => 'local-video',
				'autoplay' => $autoplay,
				'controls' => $controls,
				'loop' => $loop,
				'muted' => $muted,
				'playsinline' => true,
				'preload' => 'metadata',
				'style' => "display: block; width: clamp({$minimumWidth}px, {$viewportWidth}vw, {$width}px); max-width: 100%; height: auto;",
			],
			MediaWiki\Html\Html::element(
				'source',
				[
					'src' => $file->getUrl(),
					'type' => 'video/mp4',
				]
			)
		);
	} );
};

$wgHooks['BeforePageDisplay'][] = static function (
	MediaWiki\Output\OutputPage $outputPage,
	MediaWiki\Skin\Skin $skin
): void {
	$title = $outputPage->getTitle();

	$outputPage->addInlineStyle(
		'#ca-talk, #ca-talk-sticky-header { display: none !important; }'
	);

	if ( $title->getNamespace() === NS_PUBLIC ) {
		$outputPage->setPageTitle( $title->getText() );
	}
};

$wgCookieSecure = str_starts_with( $wgServer, 'https://' );

wfLoadSkin( 'Vector' );
$wgDefaultSkin = 'vector-2022';

$wgEnableEmail = false;
$wgUseInstantCommons = false;
