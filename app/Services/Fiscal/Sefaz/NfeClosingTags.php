<?php

namespace App\Services\Fiscal\Sefaz;

use App\DataTransferObjects\Cnpj;
use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\Enums\FiscalDocumentOperation;
use App\Support\Money\Money;
use NFePHP\NFe\Make;

/**
 * Fechamento do XML — `transp`, `pag`, `infAdic` e `infRespTec` — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §5.4.
 */
final class NfeClosingTags
{
    /** modFrete 9 = sem ocorrência de transporte (NFC-e exige; NF-e do balcão também). */
    private const NO_FREIGHT = '9';

    /** tpIntegra 2 = maquininha não integrada ao sistema (POS). */
    private const CARD_NOT_INTEGRATED = '2';

    private const CREDIT_STORE = '05';

    private const SIMPLES_NOTICE = 'DOCUMENTO EMITIDO POR ME OU EPP OPTANTE PELO SIMPLES NACIONAL. NAO GERA DIREITO A CREDITO FISCAL DE IPI.';

    /** CRT do Simples Nacional e do MEI. */
    private const SIMPLES_REGIMES = ['1', '4'];

    public function add(Make $nfe, FiscalInvoicePayload $payload, string $regimeCode): void
    {
        $nfe->tagtransp((object) ['modFrete' => self::NO_FREIGHT]);
        $nfe->tagpag((object) []);

        foreach ($this->payments($payload) as $payment) {
            $nfe->tagdetPag((object) $payment);
        }

        if (in_array($regimeCode, self::SIMPLES_REGIMES, true)) {
            $nfe->taginfAdic((object) ['infCpl' => self::SIMPLES_NOTICE]);
        }

        $this->technicalResponsible($nfe);
    }

    /**
     * Venda sem recebimento (fiado/crediário) sai como "crédito loja" pelo total; devolução sai
     * "sem pagamento" (tPag 90, valor zero).
     *
     * @return list<array<string, string>>
     */
    private function payments(FiscalInvoicePayload $payload): array
    {
        if ($payload->payments !== []) {
            return array_map(fn (array $payment): array => $this->detail($payment), $payload->payments);
        }

        if ($payload->operation === FiscalDocumentOperation::SALE) {
            return [['tPag' => self::CREDIT_STORE, 'vPag' => Money::of($payload->totals['total'])->toString()]];
        }

        return [['tPag' => SefazCodes::PAYMENT_NONE, 'vPag' => Money::zero()->toString()]];
    }

    /**
     * @param  array{method: string|null, amount: string}  $payment
     * @return array<string, string>
     */
    private function detail(array $payment): array
    {
        $type = SefazCodes::paymentType($payment['method']);

        return array_filter([
            'tPag' => $type,
            'xPag' => $type === SefazCodes::PAYMENT_OTHER ? 'Outros' : null,
            'vPag' => Money::of($payment['amount'])->toString(),
            'tpIntegra' => in_array($type, SefazCodes::CARD_PAYMENT_TYPES, true) ? self::CARD_NOT_INTEGRATED : null,
        ]);
    }

    private function technicalResponsible(Make $nfe): void
    {
        $responsible = (array) config('fiscal.sefaz.technical_responsible');

        if (blank($responsible['cnpj'] ?? null)) {
            return;
        }

        $nfe->taginfRespTec((object) [
            'CNPJ' => Cnpj::stripMask((string) $responsible['cnpj']),
            'xContato' => (string) $responsible['contact'],
            'email' => (string) $responsible['email'],
            'fone' => preg_replace('/\D/', '', (string) $responsible['phone']),
        ]);
    }
}
