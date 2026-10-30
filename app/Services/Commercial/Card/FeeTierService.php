<?php

namespace App\Services\Commercial\Card;

use App\Models\PaymentMethod;
use App\Models\PaymentMethodFeeTier;
use App\Models\SaleReceipt;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cadastro das faixas de taxa de uma forma de cartão (docs/gap-petflow/PLANO.md, 1.1): o PUT
 * manda o conjunto inteiro. Duas faixas da MESMA bandeira não podem cobrir a mesma parcela no
 * mesmo período — senão a taxa de uma venda dependeria de qual faixa o banco devolve primeiro.
 *
 * Sincroniza em vez de apagar e recriar: apagar tudo soltava o `sale_receipts.fee_tier_id`
 * (`nullOnDelete`) de todo recebimento já gravado. Faixa com `id` é atualizada no lugar; sem
 * `id`, criada; a que não veio é removida — arquivada (soft delete) se algum recebimento a usou,
 * apagada de fato se não.
 */
final class FeeTierService
{
    /**
     * @param  list<array<string, mixed>>  $tiers
     */
    public function replace(PaymentMethod $method, array $tiers): PaymentMethod
    {
        $this->assertWithinMaxInstallments($method, $tiers);
        $this->assertNoOverlap($tiers);
        $this->assertTiersBelongToMethod($method, $tiers);

        DB::transaction(function () use ($method, $tiers): void {
            $this->removeTiersNotSent($method, array_filter(array_column($tiers, 'id')));

            foreach ($tiers as $tier) {
                $this->save($method, $tier);
            }
        });

        return $method->load('feeTiers');
    }

    /** @param  array<string, mixed>  $tier */
    private function save(PaymentMethod $method, array $tier): void
    {
        $id = $tier['id'] ?? null;
        unset($tier['id']);

        if ($id === null) {
            $method->feeTiers()->create($tier);

            return;
        }

        $method->feeTiers()->whereKey($id)->firstOrFail()->update($tier);
    }

    /** @param  array<int, int>  $keptIds */
    private function removeTiersNotSent(PaymentMethod $method, array $keptIds): void
    {
        $removed = $method->feeTiers()->whereNotIn('id', $keptIds)->get();
        $used = SaleReceipt::query()->whereIn('fee_tier_id', $removed->modelKeys())->distinct()->pluck('fee_tier_id')->all();

        $removed->each(fn (PaymentMethodFeeTier $tier) => in_array($tier->id, $used, true) ? $tier->delete() : $tier->forceDelete());
    }

    /**
     * `id` de faixa de outra forma (ou arquivada) seria "atualizar" a taxa de outra clínica.
     *
     * @param  list<array<string, mixed>>  $tiers
     */
    private function assertTiersBelongToMethod(PaymentMethod $method, array $tiers): void
    {
        $ownIds = $method->feeTiers()->pluck('id')->all();

        foreach ($tiers as $index => $tier) {
            if (($tier['id'] ?? null) !== null && ! in_array($tier['id'], $ownIds, true)) {
                throw ValidationException::withMessages(["tiers.{$index}.id" => 'Faixa não encontrada nesta forma de pagamento.']);
            }
        }
    }

    /** @param  list<array<string, mixed>>  $tiers */
    private function assertWithinMaxInstallments(PaymentMethod $method, array $tiers): void
    {
        $max = max(1, (int) $method->max_installments);

        foreach ($tiers as $index => $tier) {
            $this->assertCoherent($tier, $index);

            if ((int) $tier['installments_to'] > $max) {
                throw ValidationException::withMessages([
                    "tiers.{$index}.installments_to" => "Esta forma aceita até {$max} parcela(s).",
                ]);
            }
        }
    }

    /** @param  array<string, mixed>  $tier */
    private function assertCoherent(array $tier, int $index): void
    {
        // Repasse com taxa de 100% exigiria cobrar infinito do cliente (ver FeePassThroughCalculator).
        if (($tier['pass_to_customer'] ?? false) && Decimal::of($tier['fee_percent'])->isGreaterThanOrEqualTo(100)) {
            throw ValidationException::withMessages(["tiers.{$index}.fee_percent" => 'Taxa de 100% não pode ser repassada ao cliente.']);
        }

        if ((int) $tier['installments_from'] > (int) $tier['installments_to']) {
            throw ValidationException::withMessages(["tiers.{$index}.installments_to" => 'A parcela final deve ser maior ou igual à inicial.']);
        }

        if (($tier['valid_from'] ?? null) !== null && ($tier['valid_to'] ?? null) !== null && $tier['valid_to'] < $tier['valid_from']) {
            throw ValidationException::withMessages(["tiers.{$index}.valid_to" => 'O fim da vigência deve ser depois do início.']);
        }
    }

    /** @param  list<array<string, mixed>>  $tiers */
    private function assertNoOverlap(array $tiers): void
    {
        foreach ($tiers as $index => $tier) {
            foreach (array_slice($tiers, 0, $index, true) as $otherIndex => $other) {
                if ($this->overlaps($tier, $other)) {
                    throw ValidationException::withMessages([
                        "tiers.{$index}.installments_from" => sprintf('Sobrepõe a faixa %d (mesma bandeira, parcelas e vigência).', $otherIndex + 1),
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function overlaps(array $a, array $b): bool
    {
        return ($a['card_brand'] ?? null) === ($b['card_brand'] ?? null)
            && (int) $a['installments_from'] <= (int) $b['installments_to']
            && (int) $b['installments_from'] <= (int) $a['installments_to']
            && ($a['valid_from'] ?? '0000-00-00') <= ($b['valid_to'] ?? '9999-12-31')
            && ($b['valid_from'] ?? '0000-00-00') <= ($a['valid_to'] ?? '9999-12-31');
    }
}
