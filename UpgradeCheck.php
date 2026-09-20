<?php

/**
 * Run  a series of pre-flight checks to ensure that our extension should be
 * probably likely to work.
 *
 * Usage:
 *   php maintenance/run.php /path/to/UpgradeCheck.php
 */

// phpcs:disable MediaWiki.Files.ClassMatchesFilename

if ( !class_exists( \MediaWiki\Maintenance\Maintenance::class, false ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__ );
	require_once "$IP/maintenance/Maintenance.php";
}

use MediaWiki\MediaWikiServices;

class UpgradeCheck extends \MediaWiki\Maintenance\Maintenance {

	private int $failures = 0;
	private int $warnings = 0;

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Check that BoxedMedia is compatible with this MediaWiki and Shellbox' );
	}

	public function execute() {
		$this->section( 'Core classes' );
		foreach ( [
			'TransformationalImageHandler',
			'BitmapHandler',
			'ExifBitmapHandler',
			'JpegHandler',
			'PNGHandler',
			'GIFHandler',
			'WebPHandler',
			'SvgHandler',
			'MediaTransformError',
		] as $class ) {
			$this->checkClass( $class );
		}

		$this->section( 'Methods the trait calls or overrides' );
		// [ class, method, min params, must be reachable from a subclass ]
		$methods = [
			[ 'TransformationalImageHandler', 'transformImageMagick', 2, true ],
			[ 'TransformationalImageHandler', 'getMediaTransformError', 2, true ],
			[ 'TransformationalImageHandler', 'escapeMagickProperty', 1, true ],
			[ 'TransformationalImageHandler', 'extractPreRotationDimensions', 2, true ],
			[ 'TransformationalImageHandler', 'getMagickVersion', 0, true ],
			[ 'BitmapHandler', 'imageMagickSubsampling', 1, true ],
			[ 'MediaHandler', 'isAnimatedImage', 1, true ],
			[ 'MediaHandler', 'removeBadFile', 2, true ],
			[ 'MediaHandler', 'logErrorForExternalProcess', 3, true ],
			[ 'MediaHandler', 'getRotation', 1, true ],
			[ 'ImageHandler', 'getImageArea', 1, true ],
		];
		foreach ( $methods as [ $class, $method, $minParams, $subclassReachable ] ) {
			$this->checkMethod( $class, $method, $minParams, $subclassReachable );
		}

		$this->section( 'SVG converter callable contract' );
		$this->checkSvgRasterize();

		$this->section( 'Config constants' );
		foreach ( [
			'SharpenReductionThreshold',
			'SharpenParameter',
			'MaxAnimatedGifArea',
			'JpegPixelFormat',
			'JpegQuality',
			'MaxImageArea',
			'UseImageMagick',
		] as $name ) {
			$this->checkConfigName( $name );
		}

		$this->section( 'Shellbox client' );
		$this->checkCreateBoxed();
		$this->checkBoxedCommandMethods();

		$this->section( 'Wiki configuration' );
		$this->checkWikiConfig();

		$this->section( 'Upload formats without a boxed handler' );
		$this->checkUncoveredFormats();

		$this->output( "\n" );
		if ( $this->failures ) {
			$this->fatalError(
				"FAILED: {$this->failures} problem(s), {$this->warnings} warning(s). "
				. "Do not install until these are resolved.\n" );
		}
		$this->output( "OK: no blocking problems, {$this->warnings} warning(s).\n" );
	}

	private function section( string $title ) {
		$this->output( "\n=== $title ===\n" );
	}

	private function pass( string $msg ) {
		$this->output( "  ok    $msg\n" );
	}

	private function fail( string $msg ) {
		$this->failures++;
		$this->output( "  FAIL  $msg\n" );
	}

	private function warn( string $msg ) {
		$this->warnings++;
		$this->output( "  warn  $msg\n" );
	}

	private function checkClass( string $class ) {
		if ( !class_exists( $class ) ) {
			$this->fail( "class $class not found -- it may have been namespaced; "
				. "update the use statements in the extension" );
			return;
		}
		$r = new ReflectionClass( $class );
		$ns = $r->getNamespaceName();
		if ( $ns !== '' ) {
			$this->fail( "$class is in namespace '$ns', extension expects global" );
			return;
		}
		$this->pass( "$class (global namespace)" );
	}

	private function checkMethod(
		string $class, string $method, int $minParams, bool $subclassReachable
	) {
		if ( !class_exists( $class ) ) {
			$this->fail( "$class::$method -- class missing" );
			return;
		}
		if ( !method_exists( $class, $method ) ) {
			$this->fail( "$class::$method does not exist" );
			return;
		}
		$r = new ReflectionMethod( $class, $method );
		$n = $r->getNumberOfParameters();
		if ( $n < $minParams ) {
			$this->fail( "$class::$method takes $n params, extension passes $minParams" );
			return;
		}
		if ( $subclassReachable && $r->isPrivate() ) {
			$this->fail( "$class::$method is private -- a subclass cannot call it" );
			return;
		}
		if ( $r->isFinal() ) {
			$this->fail( "$class::$method is final -- it cannot be overridden" );
			return;
		}
		$vis = $r->isPublic() ? 'public' : 'protected';
		$this->pass( "$class::$method ($vis, $n params)" );
	}

	private function checkSvgRasterize() {
		if ( !method_exists( 'SvgHandler', 'rasterize' ) ) {
			$this->fail( 'SvgHandler::rasterize does not exist' );
			return;
		}
		$r = new ReflectionMethod( 'SvgHandler', 'rasterize' );
		$n = $r->getNumberOfParameters();
		if ( $n < 5 ) {
			$this->fail( "SvgHandler::rasterize takes $n params; the callable "
				. 'contract expects ( srcPath, dstPath, width, height, lang )' );
			return;
		}
		$this->pass( "SvgHandler::rasterize ($n params)" );

		$file = $r->getFileName();
		$src = $file ? file_get_contents( $file ) : '';
		if ( str_contains( $src, 'is_array( $svgConverters[$svgConverter] )' ) ) {
			$this->pass( 'rasterize() still supports an array/callable converter' );
		} else {
			$this->fail( 'rasterize() may no longer support callable converters; '
				. 'read the method before trusting SvgRasterizer' );
		}
		if ( str_contains( $src, '$retval = (bool)$err' ) ) {
			$this->pass( 'return polarity unchanged: a falsy return means success' );
		} else {
			$this->warn( 'could not confirm return polarity; read rasterize() and '
				. 'check that a falsy return still means success' );
		}
	}

	private function checkConfigName( string $name ) {
		if ( defined( 'MediaWiki\\MainConfigNames::' . $name ) ) {
			$this->pass( "MainConfigNames::$name" );
		} else {
			$this->fail( "MainConfigNames::$name is not defined" );
		}
	}

	private function checkCreateBoxed() {
		$r = new ReflectionMethod( 'MediaWiki\Shell\CommandFactory', 'createBoxed' );
		$n = $r->getNumberOfParameters();
		if ( $n < 2 ) {
			$this->fail( "createBoxed() takes $n param(s); the extension passes the "
				. 'wall time as the 2nd argument to set the HTTP client timeout' );
			return;
		}
		$this->pass( "CommandFactory::createBoxed ($n params)" );
	}

	private function checkBoxedCommandMethods() {
		$class = 'Shellbox\Command\BoxedCommand';
		if ( !class_exists( $class ) ) {
			$this->fail( "$class not found -- is wikimedia/shellbox installed?" );
			return;
		}
		foreach ( [
			'routeName', 'params', 'inputFileFromFile', 'outputFileToFile',
			'environment', 'cpuTimeLimit', 'wallTimeLimit', 'memoryLimit',
			'fileSizeLimit', 'disableNetwork', 'firejailDefaultSeccomp', 'execute',
		] as $method ) {
			if ( method_exists( $class, $method ) ) {
				$this->pass( "BoxedCommand::$method" );
			} else {
				$this->fail( "BoxedCommand::$method missing -- remove that call "
					. 'from the extension or upgrade Shellbox' );
			}
		}
		if ( method_exists( 'Shellbox\Command\BoxedResult', 'getStderr' ) ) {
			$this->pass( 'BoxedResult::getStderr' );
		} else {
			$this->warn( 'BoxedResult::getStderr missing; the extension falls back '
				. 'to getStdout for error text' );
		}
	}

	private function checkWikiConfig() {
		$config = MediaWikiServices::getInstance()->getMainConfig();

		if ( $config->get( 'UseImageMagick' ) ) {
			$this->pass( '$wgUseImageMagick is true' );
		} else {
			$this->fail( '$wgUseImageMagick is FALSE -- getScalerType() will return '
				. "'gd' and transformImageMagick() is never called. The extension "
				. 'will appear installed and do nothing.' );
		}

		$urls = $config->get( 'ShellboxUrls' );
		foreach ( [ 'imagemagick', 'rsvg' ] as $service ) {
			if ( !empty( $urls[$service] ) ) {
				$this->pass( "\$wgShellboxUrls['$service'] is set" );
			} elseif ( !empty( $urls['default'] ) ) {
				$this->warn( "\$wgShellboxUrls['$service'] not set; it will fall back "
					. "to 'default'. Set BoxedMediaService/BoxedMediaSvgService to "
					. "'default', or add the key." );
			} else {
				$this->fail( "no URL for service '$service' and no 'default'" );
			}
		}

		if ( extension_loaded( 'apcu' ) ) {
			$this->pass( 'APCu loaded (getMagickVersion caching will work)' );
		} else {
			$this->warn( 'APCu not loaded: getLocalServerObjectCache() is a no-op, so '
				. 'the version lookup would hit the VM on every call. Harmless today '
				. 'because nothing in core calls getMagickVersion().' );
		}

		$area = $config->get( 'MaxImageArea' );
		if ( $area === false ) {
			$this->warn( '$wgMaxImageArea is false (no limit): scratch disk usage in '
				. 'the VM is then unbounded by area' );
		} else {
			$mp = round( $area / 1e6 );
			$gib = round( $area * 8 * 2 / 1024 ** 3, 1 );
			$this->pass( "\$wgMaxImageArea = {$mp} MP (~{$gib} GiB of Q16 RGBA pixel "
				. 'cache for two copies in flight)' );
		}
	}

	private function checkUncoveredFormats() {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$covered = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];

		// Extensions whose handlers shell out to ImageMagick but which the
		// extension does not register a boxed subclass for.
		$risky = [
			'tif' => 'image/tiff',
			'tiff' => 'image/tiff',
			'bmp' => 'image/x-ms-bmp',
			'xcf' => 'image/x-xcf',
			'jp2' => 'image/jp2',
			'djvu' => 'image/vnd.djvu',
		];

		$exts = array_map( 'strtolower', $config->get( 'FileExtensions' ) );
		$found = false;
		foreach ( $risky as $ext => $mime ) {
			if ( in_array( $ext, $exts, true ) && !in_array( $mime, $covered, true ) ) {
				$found = true;
				$this->warn( ".$ext ($mime) is uploadable but has no boxed handler; "
					. 'it will still use the host binary' );
			}
		}
		if ( in_array( 'svg', $exts, true ) ) {
			$this->pass( '.svg is uploadable and covered by SvgRasterizer' );
		}
		if ( !$found ) {
			$this->pass( 'no uploadable format falls back to the host' );
		}
	}
}

$maintClass = UpgradeCheck::class;
require_once RUN_MAINTENANCE_IF_MAIN;
