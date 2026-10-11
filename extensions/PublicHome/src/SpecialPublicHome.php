<?php

namespace MediaWiki\Extension\PublicHome;

use MediaWiki\Extension\SocialCards\Extractor;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use RuntimeException;

class SpecialPublicHome extends SpecialPage {
	public function __construct() {
		parent::__construct( 'PublicHome' );
	}

	public function execute( $subPage ): void {
		$this->setHeaders();
		$output = $this->getOutput();
		$output->setPageTitle( $this->msg( 'publichome' )->text() );
		$output->enableClientCache( false );
		$output->addModuleStyles( 'ext.publicHome' );
		$page = $this->getRequest()->getInt( 'page', 1 );
		if ( $page < 1 || $page > intdiv( PHP_INT_MAX, 30 ) ) {
			$this->getRequest()->response()->statusHeader( 400 );
			$output->showErrorPage( 'error', 'publichome-invalid-page' );
			return;
		}
		$output->addHTML( Html::openElement( 'div', [ 'class' => 'public-home' ] ) );
		$output->addHTML( Html::element( 'p', [], $this->msg( 'publichome-intro' )->text() ) );
		$rows = $this->query( $page );
		$hasNext = count( $rows ) > 30;
		if ( $hasNext ) {
			array_pop( $rows );
		}
		$this->feed( $rows );
		$navigation = '';
		if ( $page > 1 ) {
			$navigation .= Html::element( 'a', [ 'href' => $this->getPageTitle()->getLocalURL() ],
				$this->msg( 'publichome-start' )->text() );
		}
		if ( $hasNext ) {
			$navigation .= Html::element( 'a', [
				'href' => $this->getPageTitle()->getLocalURL( [ 'page' => $page + 1 ] ),
			], $this->msg( 'publichome-next' )->text() );
		}
		$output->addHTML( Html::rawElement( 'nav', [ 'class' => 'public-home-pagination' ], $navigation ) );
		$output->addHTML( Html::closeElement( 'div' ) );
	}

	private function query( int $page ): array {
		$services = MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getReplicaDatabase();
		$query = $db->newSelectQueryBuilder()
			->select( [
				'page_id', 'latest.rev_timestamp AS edited', 'latest.rev_parent_id',
			] )
			->from( 'page' )
			->join( 'revision', 'latest', 'latest.rev_id = page_latest' )
			->where( [
				'page_namespace' => $services->getMainConfig()->get( 'PublicHomeNamespace' ),
				'page_is_redirect' => 0,
			] )
			->orderBy( 'latest.rev_timestamp', 'DESC' )
			->orderBy( 'page_id', 'DESC' )
			->offset( ( $page - 1 ) * 30 )
			->limit( 31 );
		return iterator_to_array( $query->caller( __METHOD__ )->fetchResultSet(), false );
	}

	private function feed( array $rows ): void {
		$items = '';
		foreach ( $rows as $row ) {
			$article = $this->article( $row );
			if ( $article === null ) {
				continue;
			}
			$date = $row->edited;
			$items .= Html::rawElement( 'li', [],
				Html::rawElement( 'h3', [], Html::element( 'a', [ 'href' => $article['url'] ], $article['title'] ) ) .
				Html::element( 'p', [ 'class' => 'public-home-blurb' ], $article['description'] ) .
				Html::element( 'time', [
					'datetime' => wfTimestamp( TS_ISO_8601, $date ),
				], $this->msg( (int)$row->rev_parent_id === 0 ? 'publichome-date-created' : 'publichome-date-edited',
					gmdate( 'M j, Y', (int)wfTimestamp( TS_UNIX, $date ) )
				)->text() )
			);
		}
		$html = $items === ''
			? Html::element( 'p', [], $this->msg( 'publichome-empty' )->text() )
			: Html::rawElement( 'ul', [ 'class' => 'public-home-articles' ], $items );
		$this->getOutput()->addHTML( $html );
	}

	private function article( object $row ): ?array {
		$pageId = (int)$row->page_id;
		$services = MediaWikiServices::getInstance();
		$title = Title::newFromID( $pageId );
		$anonymous = $services->getUserFactory()->newAnonymous();
		if ( !$title || $title->getNamespace() !== $services->getMainConfig()->get( 'PublicHomeNamespace' ) ||
			!$services->getPermissionManager()->userCan( 'read', $anonymous, $title )
		) {
			return null;
		}
		$revision = $services->getRevisionLookup()->getRevisionByPageId( $pageId );
		$content = $revision ? $revision->getContent( 'main' ) : null;
		if ( !$content || $content->getModel() !== CONTENT_MODEL_WIKITEXT || $content->isRedirect() ) {
			return null;
		}
		$rendered = $services->getRevisionRenderer()->getRenderedRevision( $revision, ParserOptions::newFromAnon() );
		$output = $rendered ? $rendered->getRevisionParserOutput() : null;
		if ( !$output ) {
			throw new RuntimeException( 'Public article introduction is not renderable.' );
		}
		$intro = ( new Extractor() )->extract( $output->getRawText() )['description'];
		return [
			'title' => $title->getText(),
			'url' => $title->getLocalURL(),
			'description' => Extractor::description( $intro, $output->getPageProperty( 'description' ) ),
		];
	}
}
