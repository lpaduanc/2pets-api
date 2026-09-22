<?php

namespace App\Services\Commercial;

use App\Enums\PaymentDirection;
use App\Enums\PaymentMethodKind;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Formas de recebimento PADRÃO de quem ainda não cadastrou nenhuma — contrato
 * docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * O PDV e a conferência do caixa são por forma de pagamento: sem nenhuma forma cadastrada, o
 * balcão não consegue receber o primeiro real. O cadastro completo (taxa, adquirente, prazo) é
 * do doc 04; aqui só garantimos que as quatro formas que todo balcão brasileiro usa existam, e
 * com taxa zero — quem tem maquininha ajusta depois.
 *
 * Idempotente: só cria quando o escopo não tem NENHUMA forma (nem inativa), para não
 * ressuscitar uma forma que a clínica desativou de propósito.
 */
final class PaymentMethodProvisioner
{
    /** @var list<array{name: string, kind: PaymentMethodKind, settlement_days: int, max_installments: int}> */
    private const DEFAULTS = [
        ['name' => 'Dinheiro', 'kind' => PaymentMethodKind::CASH, 'settlement_days' => 0, 'max_installments' => 1],
        ['name' => 'Pix', 'kind' => PaymentMethodKind::PIX, 'settlement_days' => 0, 'max_installments' => 1],
        ['name' => 'Cartão de débito', 'kind' => PaymentMethodKind::DEBIT_CARD, 'settlement_days' => 1, 'max_installments' => 1],
        ['name' => 'Cartão de crédito', 'kind' => PaymentMethodKind::CREDIT_CARD, 'settlement_days' => 30, 'max_installments' => 12],
    ];

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function ensureDefaults(User $user): void
    {
        $hasAny = $this->scope->scopeQuery(PaymentMethod::withTrashed(), $user)->exists();

        if ($hasAny) {
            return;
        }

        $ownership = $this->scope->ownershipFor($user);

        DB::transaction(function () use ($ownership): void {
            foreach (self::DEFAULTS as $order => $default) {
                PaymentMethod::create([
                    'name' => $default['name'],
                    'kind' => $default['kind'],
                    'direction' => PaymentDirection::BOTH,
                    'settlement_days' => $default['settlement_days'],
                    'max_installments' => $default['max_installments'],
                    'display_order' => $order,
                    'active' => true,
                ] + $ownership);
            }
        });
    }

    /**
     * Forma cadastrada que corresponde a uma NATUREZA — usada pelos fluxos que ainda falam o
     * enum do marketplace (`App\Enums\PaymentMethod`, na fatura do atendimento) e precisam
     * cair na coluna certa da conferência do caixa. Os valores dos dois enums coincidem de
     * propósito (`cash`, `pix`, `credit_card`, `debit_card`, `boleto`).
     */
    public function methodForKind(User $user, PaymentMethodKind $kind): ?PaymentMethod
    {
        $this->ensureDefaults($user);

        return $this->scope->scopeQuery(PaymentMethod::query(), $user)
            ->active()
            ->where('kind', $kind->value)
            ->orderBy('display_order')
            ->orderBy('id')
            ->first();
    }
}
