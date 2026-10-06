<?php

namespace MediaWiki\Extension\ImageMetadataSanitizer;

use MediaWiki\FileRepo\File\LocalFile;
use MediaWiki\FileRepo\FileRepo;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use MediaWiki\Utils\MWFileProps;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\FileBackend\FSFile\FSFile;

class SanitizingLocalFile extends LocalFile {
	public function upload( $src, $comment, $pageText, $flags = 0, $props = false,
		$timestamp = false, $uploader = null, $tags = [],
		$createDummyRevision = true, $revert = false
	) {
		$srcPath = $src instanceof FSFile ? $src->getPath() : $src;
		$uploadSource = $src;
		$localCopy = null;

		if ( is_string( $srcPath )
			&& ( FileRepo::isVirtualUrl( $srcPath ) || FileBackend::isStoragePath( $srcPath ) )
		) {
			$localCopy = $this->getRepo()->getLocalCopy( $srcPath );
			if ( !$localCopy instanceof FSFile ) {
				LoggerFactory::getInstance( 'ImageMetadataSanitizer' )->error(
					'Image upload rejected because its virtual source could not be materialized.'
				);

				return Status::newFatal( 'imagemetadatasanitizer-failed' );
			}
			$srcPath = $localCopy->getPath();
		}

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
					if ( $localCopy !== null ) {
						$uploadSource = $srcPath;
					}
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
			$uploadSource,
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
