<?php

namespace App\Services\Fiscal\Sefaz;

use App\DataTransferObjects\Fiscal\BuiltNfe;
use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalEmissionMode;
use App\Enums\FiscalEnvironment;
use App\Exceptions\Fiscal\NfeXmlInvalid;
use App\Support\Fiscal\AlphanumericCnpjSupport;
use App\Support\Money\Money;
use Carbon\CarbonInterface;
use NFePHP\Common\TimeZoneByUF;
use NFePHP\Common\UFList;
use NFePHP\NFe\Make;

/**
 * Payload neutro (`FiscalInvoicePayload`) → XML da NF-e/NFC-e 4.00, ainda sem assinatura — spec
 * docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md §5 (tabela de mapeamento). Monta
 * `ide` e `NFref` e delega `emit`/`dest` (`NfePartyTags`), `det` (`NfeItemTags`) e o fechamento
 * (`NfeClosingTags`).
 * Os totais (`ICMSTot`) são somados pelo sped-nfe a partir dos itens e conferidos contra o total
 * do documento: divergência de um centavo é erro, nunca "ajuste".
 */
final class NfeXmlBuilder
{
    private const LAYOUT_VERSION = '4.00';

    private const MODELS = [FiscalDocumentKind::NFE->value => '55', FiscalDocumentKind::NFCE->value => '65'];

    /** tpImp: 1 DANFE retrato (NF-e), 4 DANFCE (NFC-e). */
    private const PRINT_FORMATS = [FiscalDocumentKind::NFE->value => '1', FiscalDocumentKind::NFCE->value => '4'];

    public function __construct(
        private readonly NfeItemTags $items,
        private readonly NfeClosingTags $closing,
        private readonly NfePartyTags $parties,
    ) {}

    /** @throws NfeXmlInvalid */
    public function build(FiscalInvoicePayload $payload, CarbonInterface $issuedAt, string $emitterCityCode, int $randomCode): BuiltNfe
    {
        $this->assertLayoutAcceptsDocuments($payload);
        $nfe = new Make((string) config('fiscal.sefaz.schema'));
        $nfe->setCheckGtin(false);
        $nfe->taginfNFe((object) ['versao' => self::LAYOUT_VERSION]);
        $nfe->tagide((object) $this->ide($payload, $issuedAt, $emitterCityCode, $randomCode));

        foreach ($payload->references as $accessKey) {
            $nfe->tagrefNFe((object) ['refNFe' => $accessKey]);
        }

        $regime = SefazCodes::taxRegime($payload->emitter->taxRegime);
        $this->parties->emitter($nfe, $payload->emitter, $emitterCityCode, $regime);
        $this->parties->recipient($nfe, $payload, $this->isConsumerInvoice($payload));
        $this->addItems($nfe, $payload);
        $this->closing->add($nfe, $payload, $regime);

        return $this->render($nfe, $payload);
    }

    /**
     * CNPJ alfanumérico (emitente, destinatário ou na chave referenciada) só sai com pacote que o
     * aceite — senão a rejeição é local e clara, não um erro de XSD (spec §5.5, NT 2026.004).
     *
     * @throws NfeXmlInvalid
     */
    private function assertLayoutAcceptsDocuments(FiscalInvoicePayload $payload): void
    {
        AlphanumericCnpjSupport::assertAccepted([
            'emitente' => $payload->emitter->document,
            'destinatário' => $payload->recipient?->document,
            'chave referenciada' => implode('', $payload->references),
        ], AlphanumericCnpjSupport::nfeSchemaAccepts(), 'NF-e '.config('fiscal.sefaz.schema'));
    }

    /** @return array<string, mixed> */
    private function ide(FiscalInvoicePayload $payload, CarbonInterface $issuedAt, string $emitterCityCode, int $randomCode): array
    {
        $state = (string) $payload->emitter->state();
        $issuedLocal = $issuedAt->copy()->setTimezone(TimeZoneByUF::get($state))->format('Y-m-d\TH:i:sP');
        $offline = $payload->emissionMode === FiscalEmissionMode::OFFLINE_CONTINGENCY;

        return array_filter([
            'cUF' => UFList::getCodeByUF($state),
            'cNF' => str_pad((string) $randomCode, 8, '0', STR_PAD_LEFT),
            'natOp' => SefazCodes::operationNature($payload->operation),
            'mod' => self::MODELS[$payload->kind->value],
            'serie' => (int) $payload->series,
            'nNF' => $payload->number,
            'dhEmi' => $issuedLocal,
            'tpNF' => SefazCodes::direction($payload->direction),
            'idDest' => $this->destination($payload),
            'cMunFG' => $emitterCityCode,
            'tpImp' => self::PRINT_FORMATS[$payload->kind->value],
            'tpEmis' => $offline ? '9' : '1',
            'tpAmb' => SefazCodes::environment($payload->environment),
            'finNFe' => SefazCodes::purpose($payload->operation),
            'indFinal' => $payload->finalConsumer || $this->isConsumerInvoice($payload) ? '1' : '0',
            'indPres' => $payload->presence ?? '0',
            'procEmi' => '0',
            'verProc' => (string) config('fiscal.sefaz.app_version'),
            // Contingência offline da NFC-e: entrada e justificativa (15 a 256 caracteres).
            'dhCont' => $offline ? $issuedLocal : null,
            'xJust' => $offline ? mb_substr((string) $payload->contingencyReason, 0, 256) : null,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /** idDest: 1 interna, 2 interestadual. NFC-e e destinatário sem UF são sempre internas. */
    private function destination(FiscalInvoicePayload $payload): string
    {
        $recipientState = $payload->recipient?->state();

        if ($this->isConsumerInvoice($payload) || $recipientState === null || $recipientState === $payload->emitter->state()) {
            return '1';
        }

        return '2';
    }

    private function addItems(Make $nfe, FiscalInvoicePayload $payload): void
    {
        foreach (array_values($payload->items) as $index => $item) {
            $isFirstHomologationNfce = $index === 0 && $this->isConsumerInvoice($payload) && $payload->environment === FiscalEnvironment::HOMOLOGATION;
            $this->items->add($nfe, $item, $index + 1, $isFirstHomologationNfce ? SefazCodes::HOMOLOGATION_NFCE_FIRST_ITEM : null);
        }
    }

    private function render(Make $nfe, FiscalInvoicePayload $payload): BuiltNfe
    {
        try {
            $xml = $nfe->render();
        } catch (\Throwable $failure) {
            throw NfeXmlInvalid::because([...$nfe->getErrors(), $failure->getMessage()]);
        }

        if ($nfe->getErrors() !== []) {
            throw NfeXmlInvalid::because($nfe->getErrors());
        }

        $this->assertTotalMatches($xml, $payload);

        return new BuiltNfe($xml, $nfe->getChave());
    }

    private function assertTotalMatches(string $xml, FiscalInvoicePayload $payload): void
    {
        preg_match('/<vNF>([\d.]+)<\/vNF>/', $xml, $match);

        if (! Money::of($match[1] ?? '0')->isEqualTo($payload->totals['total'])) {
            throw NfeXmlInvalid::because([sprintf('Total do XML (%s) difere do total do documento (%s).', $match[1] ?? '?', $payload->totals['total'])]);
        }
    }

    private function isConsumerInvoice(FiscalInvoicePayload $payload): bool
    {
        return $payload->kind === FiscalDocumentKind::NFCE;
    }
}
