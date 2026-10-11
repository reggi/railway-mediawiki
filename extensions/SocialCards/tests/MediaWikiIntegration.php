<?php

namespace MediaWiki\Extension\SocialCards;

use MediaWiki\Content\WikitextContent;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;
use RuntimeException;

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class MediaWikiIntegration extends Maintenance {
	public function execute() {
		$services = $this->getServiceContainer();
		$user = $services->getUserFactory()->newFromName( 'Admin' );
		$authority = $user;
		$cards = new Cards( $services );
		$store = new Store( $services->getMainConfig()->get( 'SocialCardsDirectory' ) );
		$save = function ( string $name, string $text ) use ( $services, $user ) {
			$title = Title::newFromText( $name );
			$page = $services->getWikiPageFactory()->newFromTitle( $title );
			$updater = $page->newPageUpdater( $user );
			$updater->setContent( 'main', new WikitextContent( $text ) );
			$revision = $updater->saveRevision( 'Social card test' );
			$this->check( $revision !== null, 'Article save failed.' );
			return $page->getId();
		};
		$id = $save( 'Public:Card test', 'An introductory paragraph.' );
		$publication = $cards->publication( $id );
		$this->check( $publication !== null, 'Save hook did not publish a card.' );
		$this->check( [ $publication['width'], $publication['height'] ] === [ 1200, 630 ], 'Wrong generated dimensions.' );
		$this->check( $publication['description'] === 'An introductory paragraph.', 'Wrong introduction.' );
		$hash = $publication['hash'];
		$mtime = filemtime( $cards->path( $hash ) );
		$save( 'Public:Card test', "An introductory paragraph.\n\n== Body ==\nChanged body text." );
		$this->check( $cards->publication( $id )['hash'] === $hash, 'Body-only edit changed the hash.' );
		$this->check( filemtime( $cards->path( $hash ) ) === $mtime, 'Body-only edit rewrote the PNG.' );
		$this->check( $cards->generate( $id ) === 'cached', 'Unchanged render was not cached.' );
		$save( 'Public:Card test', "An introductory paragraph.\n\n{{#seo:|description=A deliberate card blurb.}}" );
		$this->check( $cards->publication( $id )['description'] === 'A deliberate card blurb.', 'Explicit SEO blurb was not used.' );
		$this->check( $cards->publication( $id )['hash'] !== $hash, 'Explicit blurb did not invalidate the card.' );
		$save( 'Public:Card test', 'An introductory paragraph.' );
		$this->check( $cards->publication( $id )['description'] === 'An introductory paragraph.', 'Removing blurb did not restore lead fallback.' );
		$head = $this->request( '/wiki/Public:Card_test' );
		$this->check( $head['status'] === 200, 'Public page is not anonymously readable.' );
		$this->check( str_contains( $head['body'], 'property="og:image"' ), 'OpenGraph image is missing.' );
		$this->check( str_contains( $head['body'], 'name="twitter:image"' ), 'Twitter image is missing.' );
		$this->check( str_contains( $head['body'], 'summary_large_image' ), 'Wrong Twitter card type.' );
		$this->check( str_contains( $head['body'], "/social-cards/{$id}/{$hash}.png" ), 'Metadata references the wrong PNG.' );
		$image = $this->request( "/social-cards/{$id}/{$hash}.png" );
		$this->check( $image['status'] === 200 && Store::pngSize( $image['body'] ) === [ 1200, 630 ], 'Protected PNG delivery failed.' );
		$this->check( $this->request( "/images/social-cards/{$hash}.png" )['status'] === 403, 'Storage is accessible directly.' );
		$this->check( $this->request( "/images/social-cards/{$id}.json" )['status'] === 403, 'Manifest is accessible directly.' );

		unlink( $cards->path( $hash ) );
		$this->check( $cards->generate( $id ) === 'generated', 'Missing PNG was not regenerated.' );
		file_put_contents( $cards->path( $hash ), 'corrupt' );
		$this->check( $cards->generate( $id ) === 'generated', 'Corrupt PNG was not regenerated.' );
		$save( 'Public:Card test', 'A changed introductory paragraph.' );
		$this->check( $cards->publication( $id )['hash'] !== $hash, 'Introduction did not invalidate the hash.' );
		$this->check( $this->request( "/social-cards/{$id}/{$hash}.png" )['status'] === 404, 'Old hash is still published.' );

		$process = new Process();
		$source = '/tmp/social-card-test-source.png';
		file_put_contents( $source, $process->run( [
			'/usr/bin/convert', '-size', '300x200', 'xc:#786448', '-strip', 'png:-',
		] ) );
		$file = $services->getRepoGroup()->getLocalRepo()->newFile( Title::newFromText( 'File:Card test image.png' ) );
		$status = $file->upload( $source, 'Test image', '', 0, false, false, $authority );
		$this->check( $status->isOK(), 'Test upload failed.' );
		$box = "{| class=\"wikitable\" style=\"float: right;\"\n| [[File:Card test image.png|frameless]]\n|-\n! Type\n| Project\n|}\n\n";
		$save( 'Public:Card test', $box . 'An article with an infobox.' );
		$this->check( $cards->publication( $id )['mode'] === 'generated', 'Infobox card generation failed.' );
		$infoboxHash = $cards->publication( $id )['hash'];
		$save( 'Public:Card test', $box . "An article with an infobox.\n\n{{#seo:|image=Card test image.png|description=Editorial description}}" );
		$manual = $cards->publication( $id );
		$this->check( $manual['mode'] === 'manual', 'Manual override was not selected.' );
		$this->check( $manual['hash'] !== $infoboxHash, 'Manual and automatic card hashes match.' );
		$this->check( [ $manual['width'], $manual['height'] ] === [ 300, 200 ], 'Manual dimensions were not retained.' );
		$head = $this->request( '/wiki/Public:Card_test' );
		$this->check( str_contains( $head['body'], 'Editorial description' ), 'Explicit SEO description was lost.' );
		$save( 'Public:Card test', $box . 'An article with an infobox.' );
		$this->check( $cards->publication( $id )['mode'] === 'generated', 'Removing override did not restore automatic mode.' );

		$privateId = $save( 'Private card test', 'Private content must not appear in a generated image.' );
		$this->check( $store->manifest( $privateId ) === null, 'Private article generated a card.' );
		$beforeMove = $cards->publication( $id );
		$status = $services->getMovePageFactory()->newMovePage(
			Title::newFromText( 'Public:Card test' ), Title::newFromText( 'Private moved card test' )
		)->move( $user, 'Privacy test', false );
		$this->check( $status->isOK(), 'Public-to-private move failed.' );
		$this->check( $cards->publication( $id ) === null, 'Private move did not revoke publication.' );
		$this->check(
			$this->request( "/social-cards/{$id}/{$beforeMove['hash']}.png" )['status'] === 404,
			'Old public card is readable after a private move.'
		);
		$status = $services->getMovePageFactory()->newMovePage(
			Title::newFromText( 'Private moved card test' ), Title::newFromText( 'Public:Restored card test' )
		)->move( $user, 'Publication test', false );
		$this->check( $status->isOK() && $cards->publication( $id ) !== null, 'Moving into Public did not generate a card.' );
		$beforeDelete = $cards->publication( $id );
		$page = $services->getWikiPageFactory()->newFromTitle( Title::newFromText( 'Public:Restored card test' ) );
		$status = $services->getDeletePageFactory()->newDeletePage( $page, $authority )->deleteIfAllowed( 'Deletion test' );
		$this->check( $status->isOK(), 'Test deletion failed.' );
		$this->check( $cards->publication( $id ) === null, 'Deleted article still has a publication.' );
		$this->check(
			$this->request( "/social-cards/{$id}/{$beforeDelete['hash']}.png" )['status'] === 404,
			'Deleted article image remains accessible.'
		);
		unlink( $source );
		$this->output( "MediaWiki social-card save/SEO/upload/privacy lifecycle tests passed.\n" );
	}

	private function check( bool $condition, string $message ): void {
		if ( !$condition ) {
			throw new RuntimeException( $message );
		}
	}

	private function request( string $path ): array {
		$context = stream_context_create( [ 'http' => [ 'ignore_errors' => true, 'timeout' => 15 ] ] );
		$body = file_get_contents( 'http://127.0.0.1:8080' . $path, false, $context );
		if ( $body === false ) {
			throw new RuntimeException( 'HTTP validation request failed.' );
		}
		preg_match( '/HTTP\/\S+ ([0-9]+)/', $http_response_header[0], $matches );
		return [ 'status' => (int)$matches[1], 'body' => $body ];
	}
}

$maintClass = MediaWikiIntegration::class;
require_once RUN_MAINTENANCE_IF_MAIN;
