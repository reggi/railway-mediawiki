<?php

namespace MediaWiki\Extension\ImageMetadataSanitizer;

use MediaWiki\FileRepo\File\LocalFile;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use MediaWiki\Utils\MWFileProps;
use Wikimedia\FileBackend\FSFile\FSFile;

class SanitizingLocalFile extends LocalFile {
	public function upload( $src, $comment, $pageText, $flags = 0, $props = false,
		$timestamp = false, $uploader = null, $tags = [],
		$createDummyRevision = true, $revert = false
	) {
		$srcPath = $src instanceof FSFile ? $src->getPath() : $src;

		if ( !is_string( $srcPath ) || !is_file( $srcPath ) ) {
			return parent::upload(
				$src,
				$comment,
				$pageText,
				$flags,
				$props,
				$timestamp,
				$uploader,
				$tags,
				$createDummyRevision,
				$revert
			);
		}

		$services = MediaWikiServices::getInstance();
		$fileProps = new MWFileProps( $services->getMimeAnalyzer() );
		$currentProps = $fileProps->getPropsFromPath( $srcPath, true );
		$mime = $currentProps['mime'] ?? '';

		if ( is_string( $mime ) && str_starts_with( $mime, 'image/' ) && $mime !== 'image/svg+xml' ) {
			$config = $services->getMainConfig();
			$sanitizer = new ImageMetadataSanitizer(
				$config->get( 'ImageMetadataSanitizerExifToolPath' ),
				$config->get( 'ImageMetadataSanitizerImageMagickPath' ),
				$config->get( 'ImageMetadataSanitizerCommandTimeout' )
			);

			try {
				if ( $sanitizer->sanitize( $srcPath, $mime ) ) {
					$currentProps = $fileProps->getPropsFromPath( $srcPath, true );
				}
			} catch ( SanitizationException $exception ) {
				LoggerFactory::getInstance( 'ImageMetadataSanitizer' )->error(
					'Image upload rejected because metadata sanitization failed.',
					[ 'exception' => $exception ]
				);

				return Status::newFatal( 'imagemetadatasanitizer-failed' );
			}
		}

		return parent::upload(
			$src,
			$comment,
			$pageText,
			$flags,
			$currentProps,
			$timestamp,
			$uploader,
			$tags,
			$createDummyRevision,
			$revert
		);
	}
}
