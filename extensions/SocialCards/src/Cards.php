<?php

namespace MediaWiki\Extension\SocialCards;

use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;
use RuntimeException;
use Wikimedia\Rdbms\IDBAccessObject;

class Cards {
	private readonly Store $store;
	private readonly Process $process;

	public function __construct( private readonly MediaWikiServices $services ) {
		$config = $services->getMainConfig();
		$this->store = new Store( $config->get( 'SocialCardsDirectory' ) );
		$this->process = new Process( $config->get( 'SocialCardsTimeout' ) );
	}

	public function eligible( ?Title $title ): bool {
		return $title !== null && $title->exists() &&
			$title->getNamespace() === $this->services->getMainConfig()->get( 'SocialCardsNamespace' ) &&
			$this->services->getPermissionManager()->userCan(
				'read', $this->services->getUserFactory()->newAnonymous(), $title
			);
	}

	public function generate( int $pageId, bool $force = false ): string {
		return $this->store->locked( $pageId, function () use ( $pageId, $force ): string {
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				$title = Title::newFromID( $pageId, IDBAccessObject::READ_LATEST );
				if ( !$this->eligible( $title ) ) {
					$this->store->revoke( $pageId );
					return 'ineligible';
				}
				$revision = $this->services->getRevisionLookup()->getRevisionByPageId(
					$pageId, 0, IDBAccessObject::READ_LATEST
				);
				if ( !$revision ) {
					throw new RuntimeException( 'Latest article revision is unavailable.' );
				}
				$content = $revision->getContent( 'main' );
				if ( !$content || $content->getModel() !== CONTENT_MODEL_WIKITEXT || $content->isRedirect() ) {
					$this->store->revoke( $pageId );
					return 'ineligible';
				}
				$rendered = $this->services->getRevisionRenderer()->getRenderedRevision(
					$revision, ParserOptions::newFromAnon(), null, [ 'use-master' => true ]
				);
				$output = $rendered ? $rendered->getRevisionParserOutput() : null;
				if ( !$output ) {
					$this->store->revoke( $pageId );
					throw new RuntimeException( 'Article revision is not publicly renderable.' );
				}
				$data = ( new Extractor() )->extract( $output->getRawText() );
				$data['description'] = Extractor::description(
					$data['description'], $output->getPageProperty( 'description' )
				);
				$override = $output->getPageProperty( 'image' );
				$manual = is_string( $override ) && trim( $override ) !== '';
				$displayTitle = Extractor::text( $title->getText(), 120 );
				$png = null;
				if ( $manual ) {
					$png = $this->image( $override );
					$size = Store::pngSize( $png );
					if ( !$size ) {
						throw new RuntimeException( 'Manual card is not a valid raster image.' );
					}
					$payload = [
						'schema' => 1, 'mode' => 'manual', 'image' => hash( 'sha256', $png ),
					];
				} else {
					$config = $this->services->getMainConfig();
					$fingerprint = trim( file_get_contents( $config->get( 'SocialCardsFingerprint' ) ) );
					if ( !preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) {
						throw new RuntimeException( 'Renderer fingerprint is unavailable or invalid.' );
					}
					$payload = [
						'schema' => 1,
						'renderer' => $fingerprint,
						'width' => 1200,
						'height' => 630,
						'branding' => Extractor::text( (string)parse_url( $config->get( 'CanonicalServer' ), PHP_URL_HOST ), 100 ),
						'title' => $displayTitle,
						'description' => $data['description'],
						'image' => $data['image'] === null ? null : 'data:image/png;base64,' . base64_encode( $this->image( $data['image'] ) ),
					];
					$size = [ 1200, 630 ];
				}
				$input = Store::canonical( $payload );
				$hash = hash( 'sha256', $input );
				$cached = !$force && $this->store->cached( $hash, $size[0], $size[1] );
				if ( !$cached && !$manual ) {
					$png = $this->process->run( [ $this->services->getMainConfig()->get( 'SocialCardsGenerator' ) ], $input );
				}
				$currentTitle = Title::newFromID( $pageId, IDBAccessObject::READ_LATEST );
				$current = $this->services->getRevisionLookup()->getRevisionByPageId(
					$pageId, 0, IDBAccessObject::READ_LATEST
				);
				if ( !$this->eligible( $currentTitle ) ) {
					$this->store->revoke( $pageId );
					return 'ineligible';
				}
				if ( !$current || $current->getId() !== $revision->getId() ||
					$currentTitle->getPrefixedDBkey() !== $title->getPrefixedDBkey()
				) {
					continue;
				}
				$this->store->publish( $pageId, [
					'hash' => $hash, 'revision' => $revision->getId(),
					'mode' => $manual ? 'manual' : 'generated',
					'width' => $size[0], 'height' => $size[1],
					'title' => $displayTitle, 'description' => $data['description'],
				], $cached ? null : $png );
				return $cached ? 'cached' : ( $manual ? 'manual' : 'generated' );
			}
			throw new RuntimeException( 'Article changed repeatedly during social card generation.' );
		} );
	}

	public function publication( int $pageId ): ?array {
		$title = Title::newFromID( $pageId, IDBAccessObject::READ_LATEST );
		if ( !$this->eligible( $title ) ) {
			return null;
		}
		$revision = $this->services->getRevisionLookup()->getRevisionByPageId(
			$pageId, 0, IDBAccessObject::READ_LATEST
		);
		if ( !$revision || !$revision->getContent( 'main' ) || $revision->getContent( 'main' )->isRedirect() ) {
			return null;
		}
		$manifest = $this->store->manifest( $pageId );
		if ( !$manifest || !$this->store->cached( $manifest['hash'], $manifest['width'], $manifest['height'] ) ) {
			return null;
		}
		return $manifest;
	}

	public function path( string $hash ): string {
		return $this->store->path( $hash );
	}

	public function revoke( int $pageId ): void {
		$this->store->locked( $pageId, fn () => $this->store->revoke( $pageId ) );
	}

	private function image( string $name ): string {
		$title = Title::newFromText( $name, NS_FILE );
		if ( !$title || $title->getNamespace() !== NS_FILE ) {
			throw new RuntimeException( 'Social card image must be a local wiki File title.' );
		}
		$file = $this->services->getRepoGroup()->getLocalRepo()->findFile( $title );
		if ( !$file || !in_array( $file->getMimeType(), [
			'image/png', 'image/jpeg', 'image/gif', 'image/webp',
		], true ) ) {
			throw new RuntimeException( 'Social card image is missing or has an unsupported format.' );
		}
		$path = $file->getLocalRefPath();
		if ( !$path || !is_file( $path ) || filesize( $path ) > 33554432 ||
			$file->getWidth() * $file->getHeight() > 40000000
		) {
			throw new RuntimeException( 'Social card source image is inaccessible or exceeds its size limit.' );
		}
		$png = $this->process->run( [
			$this->services->getMainConfig()->get( 'SocialCardsImageMagick' ),
			'-limit', 'memory', '128MiB', '-limit', 'map', '256MiB',
			'-limit', 'time', '10', $path . '[0]',
			'-auto-orient', '-strip', '-resize', '1200x1200>', 'png:-',
		], '', 2097152 );
		if ( !Store::pngSize( $png ) ) {
			throw new RuntimeException( 'Unable to normalize social card source image.' );
		}
		return $png;
	}
}
