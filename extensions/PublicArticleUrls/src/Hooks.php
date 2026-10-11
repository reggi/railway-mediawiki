<?php

namespace MediaWiki\Extension\PublicArticleUrls;

use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDBAccessObject;

class Hooks {
	public static function resolveRequest(): void {
		if ( MW_ENTRY_POINT !== 'index' ) {
			return;
		}
		$request = RequestContext::getMain()->getRequest();
		$path = parse_url( $request->getRequestURL(), PHP_URL_PATH );
		if ( !is_string( $path ) || !str_starts_with( $path, '/wiki/' ) ) {
			return;
		}
		$title = Title::newFromText( $request->getText( 'title' ) );
		if ( !$title || $title->getNamespace() !== NS_MAIN || $title->isExternal() ) {
			return;
		}
		$public = Title::makeTitleSafe(
			MediaWikiServices::getInstance()->getMainConfig()->get( 'PublicArticleUrlsNamespace' ),
			$title->getDBkey()
		);
		if ( $public && self::hasCleanUrl( $public ) ) {
			// ExtensionFunctions runs after path interpolation, before title and permission initialization.
			$request->setVal( 'title', $public->getPrefixedDBkey() );
		}
	}

	public static function onGetLocalURLArticle( Title $title, string &$url ): void {
		if ( self::hasCleanUrl( $title ) ) {
			$url = '/wiki/' . wfUrlencode( $title->getDBkey() );
		}
	}

	private static function hasCleanUrl( Title $title ): bool {
		$services = MediaWikiServices::getInstance();
		if ( $title->getNamespace() !== $services->getMainConfig()->get( 'PublicArticleUrlsNamespace' ) ||
			!$title->exists() || !$services->getPermissionManager()->userCan(
				'read', $services->getUserFactory()->newAnonymous(), $title
			)
		) {
			return false;
		}
		$main = Title::makeTitleSafe( NS_MAIN, $title->getDBkey() );
		if ( !$main ) {
			return false;
		}
		if ( !$main->exists() ) {
			return true;
		}
		// An existing direct redirect to this public article is an alias, not a conflicting article.
		$revision = $services->getRevisionLookup()->getRevisionByPageId(
			$main->getArticleID(), 0, IDBAccessObject::READ_LATEST
		);
		$content = $revision ? $revision->getContent( 'main' ) : null;
		$target = $content && $content->isRedirect() ? $content->getRedirectTarget() : null;
		return $target && !$target->isExternal() &&
			$target->getNamespace() === $title->getNamespace() &&
			$target->getDBkey() === $title->getDBkey();
	}
}
