<?php

namespace App\Services\Fiscal\Nfse;

use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\DataTransferObjects\Fiscal\FiscalParty;
use App\DataTransferObjects\Fiscal\NfseDpsContext;
use App\Enums\FiscalEnvironment;
use App\Enums\NfseLayout;
use App\Exceptions\Fiscal\NfeXmlInvalid;
use DOMElement;

/**
 * Payload neutro → DPS 1.01 (sem assinatura) — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §5.1/§5.2. Serviço e valores ficam em
 * `NfseDpsValues`. Regras do Anexo I que moldam o XML:
 *
 * - `tpEmit` sempre 1 (tomador/intermediário emitindo é recusado: E9996);
 * - prestador emitente vai SEM `xNome` e SEM `end` (E0121/E0128) — a Sefin usa o cadastro;
 * - tomador só com CPF/CNPJ (opcional no leiaute); endereço só completo e com cMun conhecido;
 *   tomador = prestador é recusado (E0202), então fica de fora. E-mail/telefone do tomador são
 *   opcionais e não vão (LGPD: o mínimo necessário para a nota).
 */
final class NfseDpsBuilder
{
    private const ISSUER_IS_PROVIDER = '1';

    private const REGULAR_INVOICE = '0';

    private const ENVIRONMENT_CODES = [FiscalEnvironment::PRODUCTION->value => '1', FiscalEnvironment::HOMOLOGATION->value => '2'];

    private const NAME_MAX = 300;

    private const MUNICIPAL_REGISTRATION_MAX = 15;

    private const STREET_MAX = 255;

    private const SHORT_FIELD_MAX = 60;

    private const COMPLEMENT_MAX = 156;

    public function __construct(private readonly NfseDpsValues $values) {}

    /** @throws NfeXmlInvalid dado obrigatório faltando */
    public function build(FiscalInvoicePayload $payload, NfseDpsContext $context): string
    {
        $cnpj = self::providerCnpj($payload->emitter);
        $writer = new NfseXmlWriter;
        $dps = $writer->root('DPS', ['versao' => NfseLayout::VERSION]);
        $info = $writer->group($dps, 'infDPS', ['Id' => $context->dpsId($cnpj)]);

        $this->header($writer, $info, $context);
        $this->provider($writer, $info, $payload->emitter, $context, $cnpj);
        $this->recipient($writer, $info, $payload->recipient, $context, $cnpj);
        $this->values->append($writer, $info, $payload, $context);

        return $writer->xml();
    }

    private function header(NfseXmlWriter $writer, DOMElement $info, NfseDpsContext $context): void
    {
        $writer->add($info, 'tpAmb', self::ENVIRONMENT_CODES[$context->environment->value]);
        $writer->add($info, 'dhEmi', $context->issuedAt->format('Y-m-d\TH:i:sP'));
        $writer->add($info, 'verAplic', NfseXmlWriter::text((string) config('fiscal.nfse.app_version'), 20));
        $writer->add($info, 'serie', $context->paddedSeries());
        $writer->add($info, 'nDPS', (string) $context->number);
        $writer->add($info, 'dCompet', $context->competence->format('Y-m-d'));
        $writer->add($info, 'tpEmit', self::ISSUER_IS_PROVIDER);

        if ($context->layout->hasFinality()) {
            $writer->add($info, 'finNFSe', self::REGULAR_INVOICE);
        }

        $writer->add($info, 'cLocEmi', $context->cityCode);
    }

    private function provider(NfseXmlWriter $writer, DOMElement $info, FiscalParty $emitter, NfseDpsContext $context, string $cnpj): void
    {
        $provider = $writer->group($info, 'prest');
        $writer->add($provider, 'CNPJ', $cnpj);
        $writer->add($provider, 'IM', NfseXmlWriter::text($emitter->municipalRegistration, self::MUNICIPAL_REGISTRATION_MAX));
        $regime = $writer->group($provider, 'regTrib');
        $writer->add($regime, 'opSimpNac', $context->regime['opSimpNac']);
        $writer->add($regime, 'regApTribSN', $context->regime['regApTribSN']);
        $writer->add($regime, 'regEspTrib', $context->regime['regEspTrib']);
    }

    private function recipient(NfseXmlWriter $writer, DOMElement $info, ?FiscalParty $recipient, NfseDpsContext $context, string $providerCnpj): void
    {
        $type = $recipient?->documentType();

        if ($recipient === null || $type === null || $recipient->document === $providerCnpj) {
            return;
        }

        $taker = $writer->group($info, 'toma');
        $writer->add($taker, strtoupper($type), $recipient->document);
        $writer->add($taker, 'xNome', NfseXmlWriter::text($recipient->name, self::NAME_MAX) ?? strtoupper($type).' '.$recipient->document);
        $this->recipientAddress($writer, $taker, $recipient, $context->recipientCityCode);
    }

    private function recipientAddress(NfseXmlWriter $writer, DOMElement $taker, FiscalParty $recipient, ?string $cityCode): void
    {
        $address = $recipient->address;
        $street = NfseXmlWriter::text($address['street'] ?? null, self::STREET_MAX);
        $number = NfseXmlWriter::text($address['number'] ?? null, self::SHORT_FIELD_MAX);
        $district = NfseXmlWriter::text($address['district'] ?? null, self::SHORT_FIELD_MAX);
        $zip = (string) ($address['zip'] ?? '');

        if ($cityCode === null || $street === null || $number === null || $district === null || strlen($zip) !== 8) {
            return;
        }

        $end = $writer->group($taker, 'end');
        $national = $writer->group($end, 'endNac');
        $writer->add($national, 'cMun', $cityCode);
        $writer->add($national, 'CEP', $zip);
        $writer->add($end, 'xLgr', $street);
        $writer->add($end, 'nro', $number);
        $writer->add($end, 'xCpl', NfseXmlWriter::text($address['complement'] ?? null, self::COMPLEMENT_MAX));
        $writer->add($end, 'xBairro', $district);
    }

    private static function providerCnpj(FiscalParty $emitter): string
    {
        if ($emitter->documentType() !== 'cnpj') {
            throw NfeXmlInvalid::because(['CNPJ do prestador ausente ou inválido no cadastro da empresa.']);
        }

        return (string) $emitter->document;
    }
}
