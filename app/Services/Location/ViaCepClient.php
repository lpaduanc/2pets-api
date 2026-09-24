<?php

namespace App\Services\Location;

use App\DataTransferObjects\Location\PostalAddress;
use App\Exceptions\Location\PostalCodeLookupUnavailableException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CEP → endereço pela ViaCEP (gratuita, sem chave). Distingue "CEP não existe" (`null`) de
 * "ViaCEP fora do ar" (exceção) porque o frontend reage de forma diferente a cada um.
 *
 * Só CEP encontrado é cacheado: endereço de CEP praticamente não muda, e uma falha
 * transitória não pode ficar grudada por 30 dias.
 */
final class ViaCepClient
{
    private const CACHE_TTL_DAYS = 30;

    private const TIMEOUT_SECONDS = 5;

    public function lookup(string $zipCode): ?PostalAddress
    {
        $digits = preg_replace('/\D/', '', $zipCode);

        $payload = Cache::remember(
            "viacep:{$digits}",
            now()->addDays(self::CACHE_TTL_DAYS),
            fn (): ?array => $this->fetch($digits)
        );

        return $payload === null ? null : PostalAddress::fromViaCep($payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(string $digits): ?array
    {
        $response = $this->request($digits);

        // ViaCEP responde 200 com `{"erro": true}` (ou `"true"`) para CEP bem formado que não
        // existe, e 400 para CEP mal formado — os dois são "não existe" para quem pergunta.
        if ($response->status() === 400 || filter_var($response->json('erro'), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        if ($response->failed() || $response->json('localidade') === null) {
            throw $this->unavailable($digits, "HTTP {$response->status()}");
        }

        return $response->json();
    }

    private function request(string $digits): Response
    {
        try {
            return Http::timeout(self::TIMEOUT_SECONDS)
                ->get(rtrim((string) config('services.viacep.base_url'), '/')."/ws/{$digits}/json/");
        } catch (Throwable $exception) {
            throw $this->unavailable($digits, $exception->getMessage());
        }
    }

    private function unavailable(string $digits, string $error): PostalCodeLookupUnavailableException
    {
        Log::warning('ViaCepClient: lookup failed', ['zip_code' => $digits, 'error' => $error]);

        return PostalCodeLookupUnavailableException::postalService();
    }
}
