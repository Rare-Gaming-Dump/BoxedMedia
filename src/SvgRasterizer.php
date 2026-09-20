<?php

namespace MediaWiki\Extension\BoxedMedia;

use MediaWiki\MediaWikiServices;
use Shellbox\ShellboxError;

class SvgRasterizer {

	/**
	 * Rasterize an SVG to PNG inside the Shellbox.
	 *
	 * @param string $srcPath Source SVG on the wiki host
	 * @param string $dstPath Destination PNG on the wiki host
	 * @param string|int $width
	 * @param string|int $height
	 * @param string|false $lang Language code for systemLanguage switching
	 * @return string|false Error text on failure, false on success
	 */
	public static function rasterize( $srcPath, $dstPath, $width, $height, $lang = false ) {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$wallTime = $config->get( 'BoxedMediaWallTimeLimit' );

		$args = [
			$config->get( 'BoxedMediaRsvgCommand' ),
			'--width', (string)(int)$width,
			'--height', (string)(int)$height,
			'--format', 'png',
			'--output', 'out.png',
			'in.svg',
		];

		$env = [];
		if ( $lang !== false && $lang !== '' ) {
			$env['LANG'] = (string)$lang;
		}

		try {
			$result = $services->getShellCommandFactory()
				->createBoxed( $config->get( 'BoxedMediaSvgService' ), $wallTime )
				->routeName( 'thumbnail-rsvg' )
				->disableNetwork()
				->firejailDefaultSeccomp()
				->cpuTimeLimit( $config->get( 'BoxedMediaCpuTimeLimit' ) )
				->wallTimeLimit( $config->get( 'BoxedMediaWallTimeLimit' ) )
				->memoryLimit( $config->get( 'BoxedMediaMemoryLimit' ) )
				->fileSizeLimit( $config->get( 'BoxedMediaFileSizeLimit' ) )
				->environment( $env )
				->params( ...$args )
				->inputFileFromFile( 'in.svg', $srcPath )
				->outputFileToFile( 'out.png', $dstPath )
				->execute();
		} catch ( ShellboxError $e ) {
			return 'Shellbox unavailable: ' . $e->getMessage();
		}

		if ( $result->getExitCode() !== 0 ) {
			$stderr = method_exists( $result, 'getStderr' ) ? (string)$result->getStderr() : '';
			return $stderr !== '' ? $stderr : 'rsvg-convert failed with no output';
		}

		if ( !file_exists( $dstPath ) || filesize( $dstPath ) === 0 ) {
			return 'rsvg-convert produced no output file';
		}

		return false;
	}
}
