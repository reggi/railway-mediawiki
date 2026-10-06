<?php

namespace MediaWiki\Extension\ImageMetadataSanitizer;

use MediaWiki\FileRepo\LocalRepo;

class SanitizingLocalRepo extends LocalRepo {
	public function __construct( ?array $info = null ) {
		parent::__construct( $info );

		$this->fileFactory = [ SanitizingLocalFile::class, 'newFromTitle' ];
		$this->fileFactoryKey = [ SanitizingLocalFile::class, 'newFromKey' ];
		$this->fileFromRowFactory = [ SanitizingLocalFile::class, 'newFromRow' ];
	}
}
