<?php

namespace App\Services\Fiscal\Nfse\Transport;

/**
 * Pedido e resposta HTTP crus com a Sefin Nacional — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §6. `path` é relativo à base do ambiente.
 */
final readonly class NfseHttpExchange
{
    public const GET = 'GET';

    public const POST = 'POST';

    /** @param  array<string, string>|null  $json */
    public function __construct(
        public string $method,
        public string $url,
        public ?array $json = null,
        public ?int $status = null,
        public string $body = '',
    ) {}

    public function answered(int $status, string $body): self
    {
        return new self($this->method, $this->url, $this->json, $status, $body);
    }

    /** @return array<string, mixed>|null corpo JSON (objeto) ou nulo quando não é JSON */
    public function decoded(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
