<?php

namespace MediaWiki\Extension\BoxedMedia;

use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use Shellbox\ShellboxError;

trait BoxedImageMagickTrait {

	/**
	 * Transform an image using ImageMagick running inside a Shellbox.
	 *
	 * Overrides TransformationalImageHandler::transformImageMagick().
	 *
	 * @param \File $image
	 * @param array $params Scaler parameters (see doTransform())
	 * @return \MediaTransformError|false False on success
	 */
	protected function transformImageMagick( $image, $params ) {
		$mainConfig = MediaWikiServices::getInstance()->getMainConfig();

		if ( $mainConfig->get( 'BoxedMediaThumbnailer' ) === 'vips' ) {
			return $this->transformVips( $image, $params );
		}

		$sharpenReductionThreshold = $mainConfig->get( MainConfigNames::SharpenReductionThreshold );
		$sharpenParameter = $mainConfig->get( MainConfigNames::SharpenParameter );
		$maxAnimatedGifArea = $mainConfig->get( MainConfigNames::MaxAnimatedGifArea );
		$jpegPixelFormat = $mainConfig->get( MainConfigNames::JpegPixelFormat );
		$jpegQuality = $mainConfig->get( MainConfigNames::JpegQuality );

		$convertCommand = $mainConfig->get( 'BoxedMediaConvertCommand' );

		$quality = [];
		$sharpen = [];
		$scene = false;
		$animation_pre = [];
		$animation_post = [];
		$decoderHint = [];
		$subsampling = [];

		$inName = 'in.' . self::extensionForMime( $image->getMimeType() );
		$outName = 'out.' . self::extensionForMime( $params['mimeType'] );

		if ( $params['mimeType'] === 'image/jpeg' ) {
			$qualityVal = isset( $params['quality'] ) ? (string)$params['quality'] : null;
			$quality = [ '-quality', $qualityVal ?: (string)$jpegQuality ];
			if ( $params['interlace'] ) {
				$animation_post = [ '-interlace', 'JPEG' ];
			}
			# Sharpening, see T8193
			if ( ( $params['physicalWidth'] + $params['physicalHeight'] )
				/ ( $params['srcWidth'] + $params['srcHeight'] )
				< $sharpenReductionThreshold
			) {
				$sharpen = [ '-sharpen', $sharpenParameter ];
			}

			$decoderHint = [ '-define', "jpeg:size={$params['physicalDimensions']}" ];
			if ( $jpegPixelFormat ) {
				$factors = $this->imageMagickSubsampling( $jpegPixelFormat );
				$subsampling = [ '-sampling-factor', implode( ',', $factors ) ];
			}
		} elseif ( $params['mimeType'] === 'image/png' ) {
			$quality = [ '-quality', '95' ];
			if ( $params['interlace'] ) {
				$animation_post = [ '-interlace', 'PNG' ];
			}
		} elseif ( $params['mimeType'] === 'image/webp' ) {
			$quality = [ '-quality', '95', '-define', 'webp:method=2' ];
		} elseif ( $params['mimeType'] === 'image/gif' ) {
			if ( $this->getImageArea( $image ) > $maxAnimatedGifArea ) {
				$scene = 0;
			} elseif ( $this->isAnimatedImage( $image ) ) {
				$animation_pre = [ '-coalesce' ];
				$animation_post = [ '-fuzz', '5%', '-layers', 'optimizeTransparency' ];
			}
			if ( $params['interlace'] && !$this->isAnimatedImage( $image ) ) {
				$animation_post[] = '-interlace';
				$animation_post[] = 'GIF';
			}
		} elseif ( $params['mimeType'] === 'image/x-xcf' ) {
			$animation_pre = [
				'-background', 'transparent',
				'-layers', 'merge',
				'-background', 'white',
			];
		}

		$strip = [];
		if ( $mainConfig->get( 'BoxedMediaStripMetadata' ) ) {
			$strip = $mainConfig->get( 'BoxedMediaStripColourProfile' )
				? [ '+profile', '*' ]
				: [ '+profile', '!icc,icm,*' ];
		}

		$rotation = isset( $params['disableRotation'] ) ? 0 : $this->getRotation( $image );
		[ $width, $height ] = $this->extractPreRotationDimensions( $params, $rotation );

		$inSpec = $scene !== false ? "{$inName}[{$scene}]" : $inName;

		$args = array_merge(
			[ $convertCommand ],
			$quality,
			[ '-background', 'white' ],
			$decoderHint,
			[ $inSpec ],
			$animation_pre,
			[ '-thumbnail', "{$width}x{$height}!" ],
			$strip,
			( $params['comment'] !== ''
				? [ '-set', 'comment', $this->escapeMagickProperty( $params['comment'] ) ]
				: [] ),
			[ '+set', 'Thumb::URI' ],
			[ '-depth', 8 ],
			$sharpen,
			[ '-rotate', "-$rotation" ],
			$subsampling,
			$animation_post,
			[ $outName ]
		);

		$args = array_map( 'strval', $args );

		$error = $this->executeBoxed(
			$args,
			$inName,
			$params['srcPath'],
			$outName,
			$params['dstPath'],
			'thumbnail-imagemagick',
			$mainConfig->get( 'BoxedMediaService' )
		);

		if ( $error !== false ) {
			[ $retval, $err ] = $error;
			$this->logErrorForExternalProcess( $retval, $err, implode( ' ', $args ) );

			return $this->getMediaTransformError( $params, "$err\nError code: $retval" );
		}

		return false; # No error
	}

	/**
	 * Thumbnail with libvips instead of ImageMagick.
	 *
	 * libvips processes the image in horizontal strips rather than decoding it
	 * whole, so peak memory is roughly (width x bands x strip height) instead of
	 * (width x height x 8) bytes. On a large source that is the difference
	 * between working in RAM and thrashing the pixel cache through disk.
	 *
	 * @param \File $image
	 * @param array $params
	 * @return \MediaTransformError|false False on success
	 */
	protected function transformVips( $image, $params ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();

		$inName = 'in.' . self::extensionForMime( $image->getMimeType() );
		$outName = 'out.' . self::extensionForMime( $params['mimeType'] );

		// vipsthumbnail fits WITHIN the given box rather than forcing exact
		// dimensions the way ImageMagick's trailing "!" does. In practice the
		// two agree, because core has already computed physicalWidth and
		// physicalHeight from the source aspect ratio.
		$size = "{$params['physicalWidth']}x{$params['physicalHeight']}";

		// Per-format save options, passed in brackets on the output filename.
		$saveOpts = '';
		switch ( $params['mimeType'] ) {
			case 'image/jpeg':
				$q = isset( $params['quality'] )
					? (int)$params['quality']
					: (int)$config->get( 'JpegQuality' );
				$saveOpts = "[Q={$q},optimize_coding,strip]";
				break;
			case 'image/webp':
				$saveOpts = '[Q=' . (int)$config->get( 'BoxedMediaWebpQuality' )
					. ',effort=' . (int)$config->get( 'BoxedMediaWebpEffort' )
					. ',strip]';
				break;
			case 'image/png':
				$saveOpts = '[compression=9,strip]';
				break;
		}

		$args = array_merge(
			[ $config->get( 'BoxedMediaVipsCommand' ) ],
			[ $inName ],
			[ '--size', $size ],
			(array)$config->get( 'BoxedMediaVipsExtraArgs' ),
			[ '--output', $outName . $saveOpts ]
		);
		$args = array_map( 'strval', $args );

		$error = $this->executeBoxed(
			$args,
			$inName,
			$params['srcPath'],
			$outName,
			$params['dstPath'],
			'thumbnail-vips',
			$config->get( 'BoxedMediaService' )
		);

		if ( $error !== false ) {
			[ $retval, $err ] = $error;
			$this->logErrorForExternalProcess( $retval, $err, implode( ' ', $args ) );
			return $this->getMediaTransformError( $params, "$err\nError code: $retval" );
		}

		return false;
	}

	/**
	 * Run a command inside the Shellbox with one input file and one output file.
	 *
	 * @param string[] $args Argv
	 * @param string $inName Name of the input file inside the box
	 * @param string $srcPath Real path on the wiki host to send
	 * @param string $outName Name of the output file inside the box
	 * @param string $dstPath Real path on the wiki host to write the result to
	 * @param string $routeName Shellbox route name (used in the request path)
	 * @param string $service Key in $wgShellboxUrls
	 * @return array|false [ int $retval, string $err ] on failure, false on success
	 */
	protected function executeBoxed(
		array $args, $inName, $srcPath, $outName, $dstPath, $routeName, $service
	) {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$wallTime = $config->get( 'BoxedMediaWallTimeLimit' );

		try {
			$result = $services->getShellCommandFactory()
				->createBoxed( $service, $wallTime )
				->routeName( $routeName )
				->disableNetwork()
				->firejailDefaultSeccomp()
				->cpuTimeLimit( $config->get( 'BoxedMediaCpuTimeLimit' ) )
				->wallTimeLimit( $config->get( 'BoxedMediaWallTimeLimit' ) )
				->memoryLimit( $config->get( 'BoxedMediaMemoryLimit' ) )
				->fileSizeLimit( $config->get( 'BoxedMediaFileSizeLimit' ) )
				->environment( [
					'OMP_NUM_THREADS' => (string)$config->get( 'BoxedMediaThreads' ),
					'VIPS_CONCURRENCY' => (string)$config->get( 'BoxedMediaThreads' ),
					'MAGICK_TMPDIR' => $config->get( 'BoxedMediaMagickTempDir' ),
					'VIPS_DISC_THRESHOLD' => (string)$config->get( 'BoxedMediaVipsDiscThreshold' ),
				] )
				->params( ...$args )
				->inputFileFromFile( $inName, $srcPath )
				->outputFileToFile( $outName, $dstPath )
				->execute();
		} catch ( ShellboxError $e ) {
			return [ -1, 'Shellbox unavailable: ' . $e->getMessage() ];
		}

		$retval = $result->getExitCode();
		if ( $retval !== 0 ) {
			$err = method_exists( $result, 'getStderr' ) ? (string)$result->getStderr() : '';
			if ( $err === '' ) {
				$err = (string)$result->getStdout();
			}
			return [ $retval, $err ];
		}

		if ( $this->removeBadFile( $dstPath, $retval ) ) {
			return [ $retval, 'Shellbox produced an unusable output file' ];
		}

		return false;
	}

	/**
	 * @return string|false Version string, or false if it could not be determined
	 */
	protected function getMagickVersion() {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$cache = $services->getLocalServerObjectCache();

		$service = $config->get( 'BoxedMediaService' );
		$key = $cache->makeGlobalKey( 'boxedmedia-imagemagick-version', $service );

		$version = $cache->get( $key );
		if ( $version !== false ) {
			return $version;
		}

		$version = $this->fetchMagickVersion();
		if ( $version !== null ) {
			$cache->set( $key, $version, $cache::TTL_HOUR );
			return $version;
		}

		$version = $config->get( 'BoxedMediaImageMagickVersion' );
		$cache->set( $key, $version, 60 );
		return $version;
	}

	/**
	 * Ask the Shellbox which ImageMagick it has.
	 *
	 * @return string|null Version string, or null if it could not be determined
	 */
	private function fetchMagickVersion() {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();

		try {
			$result = $services->getShellCommandFactory()
				->createBoxed( $config->get( 'BoxedMediaService' ), 15 )
				->routeName( 'imagemagick-version' )
				->disableNetwork()
				->cpuTimeLimit( 10 )
				->wallTimeLimit( 15 )
				->params( $config->get( 'BoxedMediaConvertCommand' ), '-version' )
				->execute();
		} catch ( ShellboxError $e ) {
			return null;
		}

		if ( $result->getExitCode() !== 0 ) {
			return null;
		}

		if ( preg_match(
			'/Version: ImageMagick ([0-9]*\.[0-9]*\.[0-9]*)/',
			$result->getStdout(),
			$matches
		) ) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * Map a MIME type to a filename extension for use inside the box.
	 *
	 * @param string $mime
	 * @return string
	 */
	private static function extensionForMime( $mime ) {
		$map = [
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp',
			'image/tiff' => 'tif',
			'image/svg+xml' => 'svg',
			'image/x-xcf' => 'xcf',
			'image/x-ms-bmp' => 'bmp',
			'image/x-bmp' => 'bmp',
			'image/vnd.djvu' => 'djvu',
		];
		return $map[$mime] ?? 'bin';
	}
}
