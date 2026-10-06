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

$wgCookieSecure = str_starts_with( $wgServer, 'https://' );

wfLoadSkin( 'Vector' );
$wgDefaultSkin = 'vector-2022';

$wgEnableEmail = false;
$wgUseInstantCommons = false;
