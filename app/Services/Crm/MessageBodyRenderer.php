<?php

namespace App\Services\Crm;

/**
 * Substitui `{{placeholder}}` no corpo do template por dado real — contrato
 * `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`, critério de aceite "placeholder não
 * permite injeção". Só chaves da WHITELIST são substituídas; qualquer coisa fora dela é
 * devolvida intacta (nunca interpolação livre/`eval`-like), mesmo cuidado de SSTI já citado em
 * `docs/atendimento-veterinario/02-receituario-dominio.md`.
 */
final class MessageBodyRenderer
{
    /** @var list<string> */
    private const ALLOWED_PLACEHOLDERS = [
        'client_name',
        'pet_name',
        'professional_name',
        'clinic_name',
        'appointment_date',
    ];

    /** @param  array<string, string|null>  $data */
    public function render(string $body, array $data): string
    {
        return preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            fn (array $match): string => $this->resolve($match[1], $data, $match[0]),
            $body
        );
    }

    /** @param  array<string, string|null>  $data */
    private function resolve(string $key, array $data, string $original): string
    {
        if (! in_array($key, self::ALLOWED_PLACEHOLDERS, true)) {
            return $original;
        }

        return (string) ($data[$key] ?? '');
    }
}
