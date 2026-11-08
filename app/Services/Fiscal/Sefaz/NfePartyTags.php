<?php

namespace App\Services\Fiscal\Sefaz;

use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\DataTransferObjects\Fiscal\FiscalParty;
use App\Enums\FiscalEnvironment;
use App\Exceptions\Fiscal\NfeXmlInvalid;
use App\Support\Fiscal\IbgeMunicipalities;
use NFePHP\NFe\Make;

/**
 * Emitente e destinatário (`emit`/`enderEmit`, `dest`/`enderDest`) — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §5.1. O cMun do destinatário vem da tabela do IBGE pelo
 * nome da cidade (o cadastro de clientes/fornecedores não guarda o código).
 */
final class NfePartyTags
{
    private const BRAZIL_CODE = '1058';

    public function __construct(private readonly IbgeMunicipalities $municipalities) {}

    public function emitter(Make $nfe, FiscalParty $emitter, string $cityCode, string $regime): void
    {
        $nfe->tagEmit((object) array_filter([
            'xNome' => mb_substr($emitter->name, 0, 60),
            'IE' => self::digitsOrExempt($emitter->stateRegistration),
            'CRT' => $regime,
            'CNPJ' => $emitter->document,
        ]));
        $nfe->tagenderEmit((object) $this->address($emitter, $cityCode));
    }

    /**
     * NF-e sempre tem destinatário com endereço; NFC-e só quando o consumidor foi identificado
     * por CPF/CNPJ (sem endereço). Em homologação o nome é o texto obrigatório da SEFAZ.
     */
    public function recipient(Make $nfe, FiscalInvoicePayload $payload, bool $consumerInvoice): void
    {
        $recipient = $payload->recipient;

        if ($recipient === null || ($consumerInvoice && $recipient->documentType() === null)) {
            return;
        }

        $nfe->tagdest((object) array_filter([
            'xNome' => $payload->environment === FiscalEnvironment::HOMOLOGATION ? SefazCodes::HOMOLOGATION_RECIPIENT : mb_substr($recipient->name, 0, 60),
            'indIEDest' => SefazCodes::recipientTaxpayer($recipient->stateRegistrationType, $consumerInvoice),
            'IE' => $consumerInvoice ? null : self::digitsOrNull($recipient->stateRegistration),
            'email' => $consumerInvoice ? null : $recipient->email,
            $recipient->documentType() === 'cnpj' ? 'CNPJ' : 'CPF' => $recipient->document,
        ]));

        if (! $consumerInvoice) {
            $nfe->tagenderDest((object) $this->address($recipient, $this->recipientCityCode($recipient)));
        }
    }

    private function recipientCityCode(FiscalParty $recipient): string
    {
        $code = $this->municipalities->code($recipient->address['city'] ?? null, $recipient->state());

        if ($code === null) {
            throw NfeXmlInvalid::because([sprintf('Município do destinatário não encontrado na tabela do IBGE: "%s/%s". Corrija a cidade no cadastro.', $recipient->address['city'] ?? '', $recipient->state() ?? '')]);
        }

        return $code;
    }

    /** @return array<string, string|null> */
    private function address(FiscalParty $party, string $cityCode): array
    {
        return array_filter([
            'xLgr' => $party->address['street'] ?? null,
            'nro' => $party->address['number'] ?? 'S/N',
            'xCpl' => $party->address['complement'] ?? null,
            'xBairro' => $party->address['district'] ?? null,
            'cMun' => $cityCode,
            'xMun' => $party->address['city'] ?? null,
            'UF' => $party->state(),
            'CEP' => $party->address['zip'] ?? null,
            'cPais' => self::BRAZIL_CODE,
            'xPais' => 'BRASIL',
        ], fn (?string $value): bool => filled($value));
    }

    private static function digitsOrNull(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }

    /** IE do emitente: só dígitos, ou "ISENTO" quando é o caso (o layout aceita os dois). */
    private static function digitsOrExempt(?string $value): ?string
    {
        return strtoupper(trim((string) $value)) === 'ISENTO' ? 'ISENTO' : self::digitsOrNull($value);
    }
}
