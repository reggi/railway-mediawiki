<?php

namespace MediaWiki\Extension\SocialCards;

use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use Throwable;

class Hooks {
	private ?OutputPage $output = null;

	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		$this->afterSave( $wikiPage->getId() );
	}

	public function onPageMoveComplete( $old, $new, $user, $pageId, $redirectId, $reason, $revision ): void {
		$this->afterSave( $pageId );
		if ( $redirectId ) {
			$this->afterSave( $redirectId );
		}
	}

	public function onPageDeleteComplete( $page, $deleter, $reason, $pageId, $deletedRev, $logEntry, $count ): void {
		try {
			( new Cards( MediaWikiServices::getInstance() ) )->revoke( $pageId );
		} catch ( Throwable $error ) {
			$this->log( $pageId, $error );
		}
	}

	public function onBeforePageDisplay( $output, $skin ): void {
		// Load SocialCards before WikiSEO so its pre-metadata hook has this exact OutputPage.
		$this->output = $output;
	}

	public function onWikiSEOPreAddMetadata( array &$metadata ): void {
		$out = $this->output;
		if ( !$out ) {
			return;
		}
		$title = $out->getTitle();
		$pageId = $title ? $title->getArticleID() : 0;
		$cards = new Cards( MediaWikiServices::getInstance() );
		try {
			if ( !$cards->eligible( $title ) || !$out->isArticle() ||
				$out->getRequest()->getVal( 'action', 'view' ) !== 'view' ||
				$out->getRequest()->getVal( 'oldid' ) !== null ||
				$out->getRequest()->getVal( 'diff' ) !== null
			) {
				unset( $metadata['image'], $metadata['image_width'], $metadata['image_height'], $metadata['image_alt'] );
				return;
			}
			$publication = $cards->publication( $pageId );
			if ( !$publication ) {
				unset( $metadata['image'], $metadata['image_width'], $metadata['image_height'], $metadata['image_alt'] );
				return;
			}
			$server = rtrim( MediaWikiServices::getInstance()->getMainConfig()->get( 'CanonicalServer' ), '/' );
			$metadata['image'] = "{$server}/social-cards/{$pageId}/{$publication['hash']}.png";
			$metadata['image_width'] = $publication['width'];
			$metadata['image_height'] = $publication['height'];
			$metadata['image_alt'] ??= $publication['title'];
			$metadata['description'] ??= $publication['description'];
			$metadata['type'] ??= 'article';
			// WikiSEO 2.7's Twitter generator uses OpenGraph image keys; add the explicit Twitter image too.
			$out->addMeta( 'twitter:image', $metadata['image'] );
			$out->addMeta( 'twitter:image:alt', $metadata['image_alt'] );
		} catch ( Throwable $error ) {
			unset( $metadata['image'], $metadata['image_width'], $metadata['image_height'], $metadata['image_alt'] );
			$this->log( $pageId, $error );
		}
	}

	private function afterSave( int $pageId ): void {
		try {
			( new Cards( MediaWikiServices::getInstance() ) )->generate( $pageId );
		} catch ( Throwable $error ) {
			// This is post-commit work: card failures must never turn a saved edit into an error response.
			$this->log( $pageId, $error );
		}
	}

	private function log( int $pageId, Throwable $error ): void {
		wfLogWarning( "SocialCards page {$pageId}: " . get_class( $error ) . ': ' . $error->getMessage() );
	}
}
