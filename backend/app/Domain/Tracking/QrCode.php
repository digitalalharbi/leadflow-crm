<?php

namespace App\Domain\Tracking;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use RuntimeException;

/**
 * A QR code as a picture: a vector for the screen and the printer, a PNG for the places that want
 * a file (a design tool, a chat, a print shop's form).
 *
 * The PNG is drawn with GD from the code's own module matrix — crisp squares, a whole number of
 * pixels per module, and a quiet zone — so it needs no imaging extension beyond the one PHP ships.
 */
class QrCode
{
    /** The quiet zone, in modules, the standard asks for around a code. */
    private const QUIET = 4;

    public static function svg(string $content, int $size = 320): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd)))->writeString($content);
    }

    /** @param  int  $size  the longest side in pixels, between 128 and 2048 */
    public static function png(string $content, int $size = 1024): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('PNG codes need the GD extension.');
        }

        $matrix = Encoder::encode($content, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ENCODING)->getMatrix();
        $modules = $matrix->getWidth();
        $total = $modules + self::QUIET * 2;
        // A whole number of pixels per module: a fractional one blurs the edges and some scanners dislike it.
        $scale = max(1, intdiv(max(128, min(2048, $size)), $total));
        $side = $total * $scale;

        $image = imagecreatetruecolor($side, $side);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $side - 1, $side - 1, $white);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $px = ($x + self::QUIET) * $scale;
                    $py = ($y + self::QUIET) * $scale;
                    imagefilledrectangle($image, $px, $py, $px + $scale - 1, $py + $scale - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
