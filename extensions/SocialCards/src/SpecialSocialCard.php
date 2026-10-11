<?php

namespace MediaWiki\Extension\SocialCards;

use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use Throwable;

class SpecialSocialCard extends SpecialPage {
	public function __construct() {
		parent::__construct( 'SocialCard' );
	}

	public function isListed() {
		return false;
	}

	public function execute( $subPage ): void {
		$this->getOutput()->disable();
		$response = $this->getRequest()->response();
		$response->header( 'Cache-Control: no-store' );
		$response->header( 'X-Content-Type-Options: nosniff' );
		$response->header( 'X-Robots-Tag: noindex' );
		if ( !preg_match( '/^([1-9][0-9]*)\/([a-f0-9]{64})\.png$/D', (string)$subPage, $matches ) ) {
			$response->statusHeader( 404 );
			return;
		}
		try {
			$cards = new Cards( MediaWikiServices::getInstance() );
			$publication = $cards->publication( (int)$matches[1] );
			if ( !$publication || !hash_equals( $publication['hash'], $matches[2] ) ) {
				$response->statusHeader( 404 );
				return;
			}
			$png = file_get_contents( $cards->path( $publication['hash'] ) );
			if ( $png === false ) {
				throw new \RuntimeException( 'Unable to read published social card.' );
			}
			$response->header( 'Content-Type: image/png' );
			$response->header( 'Content-Length: ' . strlen( $png ) );
			echo $png;
		} catch ( Throwable $error ) {
			wfLogWarning( 'SocialCards image request failed: ' . get_class( $error ) );
			$response->statusHeader( 500 );
		}
	}
}
