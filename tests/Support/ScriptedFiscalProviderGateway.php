<?php

namespace Tests\Support;

use App\Contracts\FiscalProviderGateway;
use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\DataTransferObjects\Fiscal\FiscalProviderCapabilities;
use App\DataTransferObjects\Fiscal\FiscalProviderResult;
use App\Enums\FiscalDocumentStatus;
use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use App\Models\FiscalDocument;
use App\Models\FiscalNumberInvalidation;
use RuntimeException;

/**
 * Driver de teste do `FiscalProviderGateway`: cada chamada consome a próxima resposta roteirizada
 * do método (um `FiscalProviderResult` ou uma exceção, ex. `FiscalProviderUnavailable`). Sem
 * roteiro, autoriza. Guarda os payloads recebidos para o teste conferir o que iria à SEFAZ.
 *
 *     $gateway = ScriptedFiscalProviderGateway::bind($this->app)
 *         ->script('issue', new FiscalProviderUnavailable('timeout'), FiscalProviderResult(...));
 */
final class ScriptedFiscalProviderGateway implements FiscalProviderGateway
{
    public const NAME = 'scripted';

    /** @var array<string, list<FiscalProviderResult|\Throwable>> */
    private array $scripts = [];

    /** @var list<FiscalInvoicePayload> */
    public array $issuedPayloads = [];

    /** @var array<string, int> */
    public array $calls = [];

    public function __construct(private FiscalProviderCapabilities $capabilities = new FiscalProviderCapabilities(true, true, true)) {}

    public static function bind(\Illuminate\Contracts\Foundation\Application $app, ?FiscalProviderCapabilities $capabilities = null): self
    {
        $gateway = new self($capabilities ?? new FiscalProviderCapabilities(true, true, true));
        // O driver é escolhido por dono (`FiscalGatewayResolver`); o roteirizado vale para todos.
        $app->instance(\App\Contracts\FiscalGatewayResolver::class, new FixedFiscalGatewayResolver($gateway));

        return $gateway;
    }

    public function script(string $method, FiscalProviderResult|\Throwable ...$responses): self
    {
        $this->scripts[$method] = [...($this->scripts[$method] ?? []), ...$responses];

        return $this;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capabilities(): FiscalProviderCapabilities
    {
        return $this->capabilities;
    }

    public function issue(FiscalDocument $document, FiscalInvoicePayload $payload): FiscalProviderResult
    {
        $this->issuedPayloads[] = $payload;

        return $this->next('issue', new FiscalProviderResult(FiscalDocumentStatus::AUTHORIZED, accessKey: str_pad((string) $document->id, 44, '1', STR_PAD_LEFT), protocol: 'P-'.$document->id));
    }

    public function checkStatus(FiscalDocument $document): FiscalProviderResult
    {
        return $this->next('checkStatus', new FiscalProviderResult($document->status, accessKey: $document->access_key));
    }

    public function cancel(FiscalDocument $document, string $reason): FiscalProviderResult
    {
        return $this->next('cancel', new FiscalProviderResult(FiscalDocumentStatus::CANCELLED, protocol: 'C-'.$document->id));
    }

    public function invalidate(FiscalNumberInvalidation $invalidation): FiscalProviderResult
    {
        return $this->next('invalidate', new FiscalProviderResult(FiscalDocumentStatus::AUTHORIZED, protocol: 'I-'.$invalidation->id));
    }

    private function next(string $method, FiscalProviderResult $default): FiscalProviderResult
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
        $this->scripts[$method] ??= [];
        $response = array_shift($this->scripts[$method]) ?? $default;

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }

    /** Atalho para roteirizar a queda do provedor. */
    public static function unavailable(string $message = 'SEFAZ fora do ar (timeout)'): FiscalProviderUnavailable
    {
        return new FiscalProviderUnavailable($message);
    }

    /** Garante que o roteiro foi todo consumido — evita teste passando por resposta que nunca saiu. */
    public function assertScriptsConsumed(): void
    {
        $left = array_filter($this->scripts);

        if ($left !== []) {
            throw new RuntimeException('Respostas roteirizadas não consumidas: '.implode(', ', array_keys($left)));
        }
    }
}
