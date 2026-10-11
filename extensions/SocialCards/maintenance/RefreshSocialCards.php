<?php

namespace MediaWiki\Extension\SocialCards;

use MediaWiki\Maintenance\Maintenance;
use Throwable;

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class RefreshSocialCards extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Generate or refresh social cards for eligible public articles.' );
		$this->addOption( 'page', 'Refresh one article title instead of all public articles.', false, true );
		$this->addOption( 'force', 'Regenerate even when the rendered input hash is cached.' );
		$this->requireExtension( 'SocialCards' );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$cards = new Cards( $services );
		$titleText = $this->getOption( 'page' );
		if ( $titleText !== null ) {
			$title = $services->getTitleFactory()->newFromText( $titleText );
			if ( !$cards->eligible( $title ) ) {
				$this->fatalError( 'The selected page is not an anonymously readable public article.' );
			}
			$ids = [ $title->getArticleID() ];
		} else {
			$db = $services->getConnectionProvider()->getPrimaryDatabase();
			$ids = $db->newSelectQueryBuilder()->select( 'page_id' )->from( 'page' )
				->where( [ 'page_namespace' => $services->getMainConfig()->get( 'SocialCardsNamespace' ) ] )
				->caller( __METHOD__ )->fetchFieldValues();
		}
		$failed = false;
		foreach ( $ids as $pageId ) {
			try {
				$result = $cards->generate( (int)$pageId, $this->hasOption( 'force' ) );
				$this->output( "{$pageId}: {$result}\n" );
			} catch ( Throwable $error ) {
				$failed = true;
				$this->error( "SocialCards page {$pageId}: " . $error->getMessage() );
			}
		}
		if ( $failed ) {
			$this->fatalError( 'One or more social cards could not be refreshed.' );
		}
	}
}

$maintClass = RefreshSocialCards::class;
require_once RUN_MAINTENANCE_IF_MAIN;
