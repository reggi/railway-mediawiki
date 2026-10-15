<?php

namespace MediaWiki\Extension\PublicArticleUrls;

use DOMDocument;
use DOMXPath;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;
use RuntimeException;

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class Integration extends Maintenance {
	public function execute() {
		$services = $this->getServiceContainer();
		$origin = getenv( 'SOCIAL_CARDS_TEST_ORIGIN' ) ?: 'http://127.0.0.1:8080';
		$user = $services->getUserFactory()->newFromName( 'Admin' );
		$save = function ( string $name, string $text ) use ( $services, $user ): void {
			$updater = $services->getWikiPageFactory()->newFromTitle( Title::newFromText( $name ) )->newPageUpdater( $user );
			$updater->setContent( 'main', new WikitextContent( $text ) );
			$this->check( $updater->saveRevision( 'Isolated public URL test' ) !== null, 'Save failed.' );
		};
		$save( 'Public:Clean URL test', 'Public introduction for the clean URL.' );
		$title = Title::newFromText( 'Public:Clean URL test' );
		$this->check( $title->getLocalURL() === '/wiki/Clean_URL_test', 'Public URL is not clean.' );
		$this->check( str_contains( $title->getLocalURL( 'action=edit' ), 'Public' ), 'Edit URL lost its namespace.' );
		$response = $this->request( '/wiki/Clean_URL_test' );
		$this->check( $response['status'] === 200 && str_contains( $response['body'], 'Public introduction for the clean URL.' ), 'Clean URL did not serve the public article.' );
		$this->check( str_contains( $response['body'], "property=\"og:url\" content=\"{$origin}/wiki/Clean_URL_test\"" ), 'SEO URL is not clean.' );
		preg_match( '/<link[^>]*canonical[^>]*>/', $response['body'], $canonical );
		$this->check( str_contains( $response['body'], "rel=\"canonical\" href=\"{$origin}/wiki/Clean_URL_test\"" ), 'Canonical URL is not clean: ' . ( $canonical[0] ?? 'missing' ) );
		$this->check( str_contains( $this->request( '/wiki/Public:Clean_URL_test' )['body'], 'Public introduction for the clean URL.' ), 'Namespaced URL stopped working.' );

		$save( 'Public:Mobile infobox test',
			"{| class=\"infobox\" style=\"float:right; width:300px\"\n| Mobile infobox sentinel.\n|}\nMobile lead paragraph sentinel.\n\n== Body ==\nArticle body." );
		$desktop = $this->request( '/wiki/Mobile_infobox_test' );
		$this->check( str_contains( $desktop['body'], 'skin-vector-2022' ), 'Desktop skin changed.' );
		$mobile = $this->request( '/wiki/Mobile_infobox_test',
			'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1' );
		$this->check( $mobile['status'] === 200 && str_contains( $mobile['body'], 'skin-minerva' ), 'Phone did not select MinervaNeue.' );
		$document = new DOMDocument();
		$this->check( $document->loadHTML( $mobile['body'], LIBXML_NOERROR | LIBXML_NOWARNING ), 'Mobile HTML could not be parsed.' );
		$xpath = new DOMXPath( $document );
		$this->check( $xpath->query(
			'//table[contains(concat(" ", normalize-space(@class), " "), " infobox ")]/preceding-sibling::p[normalize-space(.) = "Mobile lead paragraph sentinel."]'
		)->length === 1,
			'Public mobile article did not place its lead before the infobox.' );

		$save( 'Clean URL test', '#REDIRECT [[Public:Clean URL test]]' );
		$this->check( str_contains( $this->request( '/wiki/Clean_URL_test' )['body'], 'Public introduction for the clean URL.' ), 'Existing public redirect cannot be used as a clean alias.' );
		$save( 'Clean URL test', 'Private main namespace content must remain private.' );
		$this->check( Title::newFromText( 'Public:Clean URL test' )->getLocalURL() === '/wiki/Public:Clean_URL_test', 'Conflicting main page did not preserve namespaced URL.' );
		$response = $this->request( '/wiki/Clean_URL_test' );
		$this->check( !str_contains( $response['body'], 'Private main namespace content must remain private.' ) &&
			!str_contains( $response['body'], 'Public introduction for the clean URL.' ), 'Collision bypassed permissions or replaced the main page.' );

		$save( 'Public:Clean café/subpage', 'Unicode public title.' );
		$this->check( str_contains( $this->request( '/wiki/Clean_caf%C3%A9/subpage' )['body'], 'Unicode public title.' ), 'Unicode/subpage clean alias failed.' );
		$save( 'Public:Revoked clean URL', 'Previously public content.' );
		$status = $services->getMovePageFactory()->newMovePage(
			Title::newFromText( 'Public:Revoked clean URL' ), Title::newFromText( 'Private revoked clean URL' )
		)->move( $user, 'Privacy test', false );
		$this->check( $status->isOK(), 'Move failed.' );
		$this->check( !str_contains( $this->request( '/wiki/Revoked_clean_URL' )['body'], 'Previously public content.' ), 'Old clean URL leaked moved private content.' );
		$this->output( "Public URL routing, metadata, redirects, collisions and privacy tests passed.\n" );
	}

	private function check( bool $condition, string $message ): void {
		if ( !$condition ) {
			throw new RuntimeException( $message );
		}
	}

	private function request( string $path, string $userAgent = 'MediaWiki integration test' ): array {
		$origin = getenv( 'SOCIAL_CARDS_TEST_ORIGIN' ) ?: 'http://127.0.0.1:8080';
		$context = stream_context_create( [ 'http' => [
			'ignore_errors' => true,
			'timeout' => 15,
			'user_agent' => $userAgent,
		] ] );
		$body = file_get_contents( $origin . $path, false, $context );
		if ( $body === false ) {
			throw new RuntimeException( 'HTTP test request failed.' );
		}
		preg_match( '/HTTP\/\S+ ([0-9]+)/', $http_response_header[0], $matches );
		return [ 'status' => (int)$matches[1], 'body' => $body ];
	}
}

$maintClass = Integration::class;
require_once RUN_MAINTENANCE_IF_MAIN;
