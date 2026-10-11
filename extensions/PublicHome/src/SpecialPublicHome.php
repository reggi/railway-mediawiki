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
	private array $articles = [];

	public function __construct() {
		parent::__construct( 'PublicHome' );
	}

	public function execute( $subPage ): void {
		$this->setHeaders();
		$output = $this->getOutput();
		$output->setPageTitle( $this->msg( 'publichome' )->text() );
		$output->enableClientCache( false );
		$output->addModuleStyles( 'ext.publicHome' );
		$output->addHTML( Html::openElement( 'div', [ 'class' => 'public-home' ] ) );
		$output->addHTML( Html::element( 'p', [], $this->msg( 'publichome-intro' )->text() ) );
		$this->section( 'publichome-created', 'newly-created', $this->query( 'created', 5 ), true );
		$this->section( 'publichome-edited', 'newly-edited', $this->query( 'edited', 5 ), false );

		$from = $this->getRequest()->getText( 'from' );
		$rows = $this->query( 'all', 31, $from );
		$next = count( $rows ) > 30 ? array_pop( $rows )->page_title : null;
		$this->section( 'publichome-all', 'all-public-articles', $rows, false );
		$navigation = '';
		if ( $from !== '' ) {
			$navigation .= Html::element( 'a', [ 'href' => $this->getPageTitle()->getLocalURL() ],
				$this->msg( 'publichome-start' )->text() );
		}
		if ( $next !== null ) {
			$navigation .= Html::element( 'a', [
				'href' => $this->getPageTitle()->getLocalURL( [ 'from' => $next ] ),
			], $this->msg( 'publichome-next' )->text() );
		}
		$output->addHTML( Html::rawElement( 'nav', [ 'class' => 'public-home-pagination' ], $navigation ) );
		$output->addHTML( Html::closeElement( 'div' ) );
	}

	private function query( string $sort, int $limit, string $from = '' ): array {
		$services = MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getReplicaDatabase();
		$query = $db->newSelectQueryBuilder()
			->select( [
				'page_id', 'page_title', 'page_latest', 'latest.rev_timestamp AS edited',
				'MIN(first.rev_timestamp) AS created',
			] )
			->from( 'page' )
			->join( 'revision', 'latest', 'latest.rev_id = page_latest' )
			->join( 'revision', 'first', 'first.rev_page = page_id' )
			->where( [
				'page_namespace' => $services->getMainConfig()->get( 'PublicHomeNamespace' ),
				'page_is_redirect' => 0,
			] )
			->groupBy( [ 'page_id', 'page_title', 'page_latest', 'latest.rev_timestamp' ] );
		if ( $sort === 'all' ) {
			if ( $from !== '' ) {
				$query->where( $db->expr( 'page_title', '>=', $from ) );
			}
			$query->orderBy( 'page_title' );
		} else {
			if ( $sort === 'edited' ) {
				$query->where( $db->expr( 'latest.rev_parent_id', '!=', 0 ) );
			}
			$query->orderBy( $sort, 'DESC' )->orderBy( 'page_title' );
		}
		return iterator_to_array( $query->limit( $limit )->caller( __METHOD__ )->fetchResultSet(), false );
	}

	private function section( string $message, string $id, array $rows, bool $created ): void {
		$html = Html::element( 'h2', [ 'id' => $id ], $this->msg( $message )->text() );
		$items = '';
		foreach ( $rows as $row ) {
			$article = $this->article( $row );
			if ( $article === null ) {
				continue;
			}
			$date = $created ? $row->created : $row->edited;
			$items .= Html::rawElement( 'li', [],
				Html::rawElement( 'h3', [], Html::element( 'a', [ 'href' => $article['url'] ], $article['title'] ) ) .
				Html::element( 'p', [ 'class' => 'public-home-blurb' ], $article['description'] ) .
				Html::element( 'time', [
					'datetime' => wfTimestamp( TS_ISO_8601, $date ),
				], $this->msg( $created ? 'publichome-date-created' : 'publichome-date-edited',
					gmdate( 'M j, Y', (int)wfTimestamp( TS_UNIX, $date ) )
				)->text() )
			);
		}
		$html .= $items === ''
			? Html::element( 'p', [], $this->msg( 'publichome-empty' )->text() )
			: Html::rawElement( 'ul', [ 'class' => 'public-home-articles' ], $items );
		$this->getOutput()->addHTML( Html::rawElement( 'section', [ 'aria-labelledby' => $id ], $html ) );
	}

	private function article( object $row ): ?array {
		$pageId = (int)$row->page_id;
		if ( array_key_exists( $pageId, $this->articles ) ) {
			return $this->articles[$pageId];
		}
		$services = MediaWikiServices::getInstance();
		$title = Title::newFromID( $pageId );
		$anonymous = $services->getUserFactory()->newAnonymous();
		if ( !$title || $title->getNamespace() !== $services->getMainConfig()->get( 'PublicHomeNamespace' ) ||
			!$services->getPermissionManager()->userCan( 'read', $anonymous, $title )
		) {
			return $this->articles[$pageId] = null;
		}
		$revision = $services->getRevisionLookup()->getRevisionByPageId( $pageId );
		$content = $revision ? $revision->getContent( 'main' ) : null;
		if ( !$content || $content->getModel() !== CONTENT_MODEL_WIKITEXT || $content->isRedirect() ) {
			return $this->articles[$pageId] = null;
		}
		$rendered = $services->getRevisionRenderer()->getRenderedRevision( $revision, ParserOptions::newFromAnon() );
		$output = $rendered ? $rendered->getRevisionParserOutput() : null;
		if ( !$output ) {
			throw new RuntimeException( 'Public article introduction is not renderable.' );
		}
		$intro = ( new Extractor() )->extract( $output->getRawText() )['description'];
		return $this->articles[$pageId] = [
			'title' => $title->getText(),
			'url' => $title->getLocalURL(),
			'description' => Extractor::description( $intro, $output->getPageProperty( 'description' ) ),
		];
	}
}
