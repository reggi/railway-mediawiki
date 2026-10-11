<?php

namespace MediaWiki\Extension\PublicHome;

use MediaWiki\Context\RequestContext;
use MediaWiki\Title\Title;

class Hooks {
	public static function resolveRequest(): void {
		if ( MW_ENTRY_POINT !== 'index' ) {
			return;
		}
		$request = RequestContext::getMain()->getRequest();
		if ( !$request->getSession()->getUser()->isAnon() ||
			$request->getVal( 'action', 'view' ) !== 'view' ||
			$request->getCheck( 'oldid' ) || $request->getCheck( 'diff' )
		) {
			return;
		}
		$title = Title::newFromText(
			$request->getText( 'title', Title::newMainPage()->getPrefixedDBkey() )
		);
		if ( $title && $title->isMainPage() ) {
			$request->setVal( 'title', 'Special:PublicHome' );
		}
	}
}
