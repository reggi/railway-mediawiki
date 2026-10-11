<?php

namespace MediaWiki\Extension\SocialCards;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

class Extractor {
	public static function text( string $text, int $limit ): string {
		$text = preg_replace( '/\s+/u', ' ', trim( $text ) );
		if ( $text === null ) {
			throw new RuntimeException( 'Article text is not valid UTF-8.' );
		}
		return mb_strlen( $text ) > $limit
			? mb_substr( $text, 0, $limit - 3 ) . '...'
			: $text;
	}

	public function extract( string $html ): array {
		$dom = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			if ( !$dom->loadHTML(
				'<meta charset="utf-8">' . $html,
				LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
			) ) {
				throw new RuntimeException( 'Unable to parse article HTML.' );
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		$xpath = new DOMXPath( $dom );
		foreach ( $xpath->query(
			'//script|//style|//sup|//*[contains(concat(" ",normalize-space(@class)," ")," mw-editsection ")]'
		) as $node ) {
			$node->parentNode->removeChild( $node );
		}
		$infobox = null;
		$legacy = null;
		foreach ( $xpath->query( '//table|//h1|//h2|//h3|//h4|//h5|//h6' ) as $node ) {
			if ( $node->tagName !== 'table' ) {
				break;
			}
			$classes = ' ' . preg_replace( '/\s+/', ' ', $node->getAttribute( 'class' ) ) . ' ';
			if ( str_contains( $classes, ' infobox ' ) ) {
				$infobox = $node;
				break;
			}
			if ( $legacy === null && str_contains( $classes, ' wikitable ' ) &&
				preg_match( '/(?:^|;)\s*float\s*:\s*right\b/i', $node->getAttribute( 'style' ) )
			) {
				$legacy = $node;
			}
		}
		$infobox ??= $legacy;
		$image = null;
		$label = 'Article';
		if ( $infobox !== null ) {
			$link = $xpath->query(
				'.//a[contains(concat(" ",normalize-space(@class)," ")," mw-file-description ")][.//img]',
				$infobox
			)->item( 0 );
			if ( $link instanceof DOMElement ) {
				$href = rawurldecode( $link->getAttribute( 'href' ) );
				$path = parse_url( $href, PHP_URL_PATH );
				if ( is_string( $path ) && str_starts_with( $path, '/wiki/' ) ) {
					$image = substr( $path, strlen( '/wiki/' ) );
				} else {
					parse_str( (string)parse_url( $href, PHP_URL_QUERY ), $query );
					$image = isset( $query['title'] ) && is_string( $query['title'] ) ? $query['title'] : null;
				}
			}
			foreach ( $xpath->query( './/tr', $infobox ) as $row ) {
				$key = $xpath->query( './th', $row )->item( 0 );
				$value = $xpath->query( './td', $row )->item( 0 );
				if ( $key && $value && strcasecmp( trim( $key->textContent ), 'Type' ) === 0 ) {
					$label = self::text( $value->textContent, 50 ) ?: 'Article';
					break;
				}
			}
		}
		$description = '';
		foreach ( $xpath->query( '//p|//h1|//h2|//h3|//h4|//h5|//h6' ) as $node ) {
			if ( $node->tagName !== 'p' ) {
				break;
			}
			if ( $xpath->query(
				'ancestor::table|ancestor::nav|ancestor::*[contains(concat(" ",normalize-space(@class)," ")," navbox ")'
				. ' or contains(concat(" ",normalize-space(@class)," ")," toc ")]',
				$node
			)->length > 0 ) {
				continue;
			}
			$description = self::text( $node->textContent, 240 );
			if ( $description !== '' ) {
				break;
			}
		}
		return [ 'description' => $description, 'label' => $label, 'image' => $image ];
	}
}
