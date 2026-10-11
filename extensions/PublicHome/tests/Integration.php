<?php

namespace MediaWiki\Extension\PublicHome;

use DOMDocument;
use DOMXPath;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;
use RuntimeException;

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class Integration extends Maintenance {
	private array $cookies = [];

	public function execute() {
		$services = $this->getServiceContainer();
		$user = $services->getUserFactory()->newFromName( 'Admin' );
		$save = function ( string $name, string $text, string $date ) use ( $services, $user ): void {
			$page = $services->getWikiPageFactory()->newFromTitle( Title::newFromText( $name ) );
			$updater = $page->newPageUpdater( $user );
			$updater->setContent( 'main', new WikitextContent( $text ) );
			$revision = $updater->saveRevision( 'Isolated public homepage test' );
			$this->check( $revision !== null, 'Save failed.' );
			$services->getConnectionProvider()->getPrimaryDatabase()->newUpdateQueryBuilder()
				->update( 'revision' )->set( [ 'rev_timestamp' => $date ] )
				->where( [ 'rev_id' => $revision->getId() ] )->caller( __METHOD__ )->execute();
		};
		$save( 'Main Page', 'Signed-in homepage sentinel.', '20260101000000' );
		$save( 'Homepage private sentinel', 'Private homepage blurb sentinel.', '20260101000000' );
		$save( 'Public:Z newest homepage article', 'Newest article intro.', '20991201000000' );
		$save( 'Public:A edited homepage article', 'Original intro.', '20200101000000' );
		$save( 'Public:A edited homepage article', 'Updated homepage intro & details.', '20991202000000' );
		$save( 'Public:Homepage SEO article',
			"{{#seo:|description=Explicit homepage blurb & details.}}\nFallback homepage intro.", '20260101000000' );
		$save( 'Public:Homepage infobox article',
			"{| class=\"infobox\"\n| Caption not the blurb.\n|}\nActual lead outside infobox.\n\n== Body ==\nBody not the blurb.",
			'20260101000000' );
		$save( 'Public:Homepage redirect sentinel', '#REDIRECT [[Public:Homepage SEO article]]', '20260101000000' );
		$save( 'Public:Homepage moved sentinel', 'Moved private blurb sentinel.', '20260101000000' );
		$status = $services->getMovePageFactory()->newMovePage(
			Title::newFromText( 'Public:Homepage moved sentinel' ), Title::newFromText( 'Homepage moved sentinel' )
		)->move( $user, 'Homepage privacy test', false );
		$this->check( $status->isOK(), 'Private move failed.' );

		foreach ( [ '/', '/index.php', '/wiki/Main_Page', '/wiki/Special:PublicHome' ] as $path ) {
			$response = $this->request( $path );
			$this->check( $response['status'] === 200 && str_contains( $response['body'], 'class="public-home"' ),
				'Anonymous homepage missing at ' . $path );
			foreach ( [ 'Signed-in homepage sentinel.', 'Homepage private sentinel', 'Private homepage blurb sentinel.',
				'Homepage moved sentinel', 'Moved private blurb sentinel.', 'Homepage redirect sentinel' ] as $secret ) {
				$this->check( !str_contains( $response['body'], $secret ), 'Directory exposed private/redirect content: ' . $secret );
			}
		}
		$body = $this->request( '/' )['body'];
		$this->check( str_contains( $body, 'Updated homepage intro &amp; details.' ), 'Latest intro missing or not escaped.' );
		$this->check( str_contains( $body, 'Explicit homepage blurb &amp; details.' ), 'SEO blurb was not used.' );
		$this->check( str_contains( $body, 'Actual lead outside infobox.' ) &&
			!str_contains( $body, 'Caption not the blurb.' ), 'Infobox lead extraction failed.' );
		$this->check( str_contains( $body, 'href="/wiki/Homepage_SEO_article"' ), 'Directory link is not clean.' );
		$titles = $this->titles( $body );
		$this->check( $titles[0] === 'A edited homepage article' && $titles[1] === 'Z newest homepage article',
			'Feed does not combine creations and edits in activity order.' );
		$this->check( count( $titles ) === count( array_unique( $titles ) ), 'Feed contains duplicate articles.' );
		$this->check( str_contains( $body, 'Created Dec 1, 2099' ) && str_contains( $body, 'Edited Dec 2, 2099' ),
			'Feed did not distinguish created and edited activity.' );
		foreach ( [ 'Newly created', 'Newly edited', 'All public articles' ] as $heading ) {
			$this->check( !str_contains( $body, $heading ), 'Old duplicate section is still present.' );
		}
		foreach ( [ '/wiki/Main_Page?action=edit', '/wiki/Main_Page?action=history', '/wiki/Main_Page?oldid=1' ] as $path ) {
			$this->check( !str_contains( $this->request( $path )['body'], 'class="public-home"' ),
				'Non-view action was replaced by directory.' );
		}

		for ( $i = 0; $i < 31; $i++ ) {
			$save( sprintf( 'Public:Directory Page %02d', $i ), "Directory introduction {$i}.", '20990101000000' );
		}
		$response = $this->request( '/wiki/Special:PublicHome' );
		$titles = $this->titles( $response['body'] );
		$this->check( count( $titles ) === 30 && $titles[2] === 'Directory Page 30' &&
			$titles[29] === 'Directory Page 03', 'Activity page size or tied timestamp order failed.' );
		$this->check( str_contains( $response['body'], 'page=2' ), 'Next-page link is wrong.' );
		$next = $this->request( '/wiki/Special:PublicHome?page=2' )['body'];
		$nextTitles = $this->titles( $next );
		$this->check( $nextTitles[0] === 'Directory Page 02' && array_intersect( $titles, $nextTitles ) === [],
			'Pagination skipped or repeated an article.' );
		$combined = array_merge( $titles, $nextTitles );
		for ( $i = 0; $i < 31; $i++ ) {
			$this->check( in_array( sprintf( 'Directory Page %02d', $i ), $combined, true ), 'Feed skipped an article.' );
		}
		foreach ( [ '0', '-1', (string)PHP_INT_MAX ] as $invalid ) {
			$this->check( $this->request( '/wiki/Special:PublicHome?page=' . $invalid )['status'] === 400,
				'Invalid feed page did not report an error.' );
		}

		$login = $this->request( '/wiki/Special:UserLogin' );
		$xpath = $this->xpath( $login['body'] );
		$token = $xpath->query( '//input[@name="wpLoginToken"]/@value' )->item( 0 );
		$this->check( $token !== null, 'Login token missing.' );
		$loginResult = $this->request( '/wiki/Special:UserLogin', [
			'wpName' => 'Admin',
			'wpPassword' => getenv( 'PUBLIC_HOME_TEST_PASSWORD' ) ?: 'test-only-password',
			'wpLoginToken' => $token->nodeValue,
			'authAction' => 'login',
			'wploginattempt' => 'Log in',
		] );
		$homeRoot = $this->request( '/' );
		$home = $this->request( '/wiki/Main_Page' );
		$this->check( str_contains( $homeRoot['body'], 'Signed-in homepage sentinel.' ) ||
			( in_array( $homeRoot['status'], [ 301, 302 ], true ) &&
				str_contains( $homeRoot['location'], '/wiki/Main_Page' ) ), 'Signed-in root did not retain main-page routing.' );
		$loginMessages = [];
		foreach ( $this->xpath( $loginResult['body'] )->query(
			'//*[contains(@class,"error") or contains(@class,"warning")]'
		) as $node ) {
			$loginMessages[] = trim( $node->textContent );
		}
		$this->check( str_contains( $home['body'], 'Signed-in homepage sentinel.' ) &&
			!str_contains( $home['body'], 'class="public-home"' ), 'Signed-in homepage changed or login failed. Login status: ' .
			$loginResult['status'] . '; login messages: ' . implode( ' ', $loginMessages ) );
		$this->output( "Public homepage routes, blurbs, ordering, pagination, escaping and anonymous/authenticated privacy tests passed.\n" );
	}

	private function titles( string $html ): array {
		$values = [];
		foreach ( $this->xpath( $html )->query( '//ul[@class="public-home-articles"]/li/h3/a' ) as $node ) {
			$values[] = $node->textContent;
		}
		return $values;
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			$this->check( $dom->loadHTML( '<meta charset="utf-8">' . $html, LIBXML_NONET ), 'Test HTML did not parse.' );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		return new DOMXPath( $dom );
	}

	private function check( bool $condition, string $message ): void {
		if ( !$condition ) {
			throw new RuntimeException( $message );
		}
	}

	private function request( string $path, ?array $post = null ): array {
		$origin = getenv( 'SOCIAL_CARDS_TEST_ORIGIN' ) ?: 'http://127.0.0.1:8080';
		$headers = 'Cookie: ' . implode( '; ', $this->cookies ) . "\r\n";
		$options = [
			'ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0,
			'method' => $post === null ? 'GET' : 'POST',
			'header' => $headers . ( $post === null ? '' : "Content-Type: application/x-www-form-urlencoded\r\n" ),
		];
		if ( $post !== null ) {
			$options['content'] = http_build_query( $post );
		}
		$body = file_get_contents( $origin . $path, false, stream_context_create( [ 'http' => $options ] ) );
		$this->check( $body !== false, 'HTTP homepage test failed.' );
		foreach ( $http_response_header as $header ) {
			if ( preg_match( '/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $matches ) ) {
				$this->cookies[$matches[1]] = "{$matches[1]}={$matches[2]}";
			}
		}
		preg_match( '/HTTP\/\S+ ([0-9]+)/', $http_response_header[0], $matches );
		$location = '';
		foreach ( $http_response_header as $header ) {
			if ( str_starts_with( strtolower( $header ), 'location:' ) ) {
				$location = trim( substr( $header, 9 ) );
			}
		}
		return [ 'status' => (int)$matches[1], 'body' => $body, 'location' => $location ];
	}
}

$maintClass = Integration::class;
require_once RUN_MAINTENANCE_IF_MAIN;
