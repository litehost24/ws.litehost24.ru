<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Throwable;

class QrCodeService
{
    public function makePng(string $text, int $size = 320): ?string
    {
        $payload = trim($text);
        if ($payload === '' || !extension_loaded('gd')) {
            return null;
        }

        try {
            $writer = new Writer(new GDLibRenderer($size));
            return $writer->writeString($payload, 'UTF-8', ErrorCorrectionLevel::L());
        } catch (Throwable) {
            return null;
        }
    }

    public function makeDataUri(string $text, int $size = 320): ?string
    {
        $png = $this->makePng($text, $size);

        if (!$png) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode($png);
    }
}
