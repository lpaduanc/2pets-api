<?php

namespace App\Services\PetCard;

final class QRCodeService
{
    public function generateQRCodeUrl(string $data): string
    {
        // Using Google Charts API for QR code generation (no dependencies)
        $encodedData = urlencode($data);
        return "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl={$encodedData}&choe=UTF-8";
    }

    public function generatePetCardUrl(string $publicId): string
    {
        return url("/pet-card/{$publicId}");
    }

    public function generateQRCodeSvg(string $data, int $size = 200): string
    {
        // Simple inline SVG QR code placeholder
        $url = $this->generatePetCardUrl($data);
        
        return <<<SVG
        <svg width="{$size}" height="{$size}" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
            <rect fill="#ffffff" width="200" height="200"/>
            <text x="100" y="100" text-anchor="middle" font-size="12" fill="#000">
                QR Code for Pet Card
            </text>
            <text x="100" y="120" text-anchor="middle" font-size="10" fill="#666">
                {$url}
            </text>
        </svg>
        SVG;
    }
}

