<?php

namespace App\Services\PetCard;

final class QRCodeService
{
    public function generateQRCodeUrl(string $data): string
    {
        // A Google desligou a Image Charts API (`chart.googleapis.com/chart`)
        // em marco de 2024, entao o QR de TODAS as carteirinhas vinha quebrado:
        // a imagem falhava e o cartao exibia um retangulo branco vazio.
        $encodedData = urlencode($data);

        return "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data={$encodedData}";
    }

    /**
     * The scanned URL must open the frontend screen that renders the pet
     * card, never this API — the API only returns JSON, so scanning the old
     * URL (this app's own domain, `/pet-card/{publicId}`, a route that does
     * not even exist here) always landed on a 404/JSON dump instead of the
     * card someone who found a lost pet needs to see.
     */
    public function generatePetCardUrl(string $publicId): string
    {
        $frontendUrl = rtrim(config('app.frontend_url') ?? config('app.url'), '/');

        return "{$frontendUrl}/pet-card/{$publicId}";
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
