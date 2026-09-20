# BoxedMedia

Convert MediaWiki's ImageMagick and SVG transforms inside of a shellbox instead
of on a host, this way you don't need to have them installed on the host & open
up those vector of attacks.

This purely covers `JPEG`/`PNG/`GIF`/`WebP`/`SVG` files, and does not cover the
rest.

## Installation ##

Assuming you already have shellbox urls setup, you can:

```php
wfLoadExtension( 'BoxedMedia' );

$wgUseImageMagick = true;

// THESE PATHS ARE RELATIVE INSIDE THE MICROVM
$wgBoxedMediaConvertCommand  = '/usr/bin/convert';
$wgBoxedMediaRsvgCommand     = '/usr/bin/rsvg-convert';

// If you want to customize the temporary directory
$wgBoxedMediaMagickTempDir   = '/var/tmp/scratch/magick';

$wgSVGConverters['boxed-rsvg'] =
    [ 'MediaWiki\\Extension\\BoxedMedia\\SvgRasterizer::rasterize' ];
$wgSVGConverter = 'boxed-rsvg';
```
