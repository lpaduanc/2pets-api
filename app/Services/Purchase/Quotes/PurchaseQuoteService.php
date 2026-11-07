<?php

namespace App\Services\Purchase\Quotes;

use App\Enums\PurchaseQuoteStatus;
use App\Enums\PurchaseQuoteSupplierStatus;
use App\Mail\PurchaseQuoteRequestMail;
use App\Models\Product;
use App\Models\PurchaseQuote;
use App\Models\PurchaseQuoteSupplier;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Purchase\OwnerSequence;
use App\Support\BusinessClock;
use App\Support\Money\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Lado da clínica da cotação com fornecedores (PLANO 3.5): criar, editar (só rascunho),
 * excluir, enviar. A geração dos pedidos está em `PurchaseQuoteOrderService`.
 */
final class PurchaseQuoteService
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly OwnerSequence $sequence,
        private readonly PurchaseQuoteTokenService $tokens,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(User $user, array $data): PurchaseQuote
    {
        return DB::transaction(function () use ($user, $data): PurchaseQuote {
            $ownership = $this->scope->ownershipFor($user);
            $quote = PurchaseQuote::create([
                'number' => $this->sequence->next(PurchaseQuote::class, $ownership, 'number'),
                'status' => PurchaseQuoteStatus::DRAFT,
                'valid_until' => $data['valid_until'] ?? BusinessClock::today()->addDays(PurchaseQuote::DEFAULT_VALID_DAYS)->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ] + $ownership);

            $this->sync($quote, $user, $data);

            return $quote->fresh(PurchaseQuote::RESOURCE_RELATIONS);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(PurchaseQuote $quote, User $user, array $data): PurchaseQuote
    {
        abort_unless($quote->status === PurchaseQuoteStatus::DRAFT, 422, 'Só cotação em rascunho pode ser editada.');

        return DB::transaction(function () use ($quote, $user, $data): PurchaseQuote {
            $quote->update(array_intersect_key($data, array_flip(['valid_until', 'notes'])));
            $this->sync($quote, $user, $data);

            return $quote->fresh(PurchaseQuote::RESOURCE_RELATIONS);
        });
    }

    public function delete(PurchaseQuote $quote): void
    {
        abort_if($quote->status === PurchaseQuoteStatus::CLOSED, 422, 'Cotação encerrada (já gerou pedidos) não pode ser excluída.');

        $quote->delete();
    }

    /**
     * Gera o link de cada fornecedor (uma vez) e manda o e-mail a quem tem e-mail e ainda não
     * respondeu nem recusou. Quem não tem e-mail fica com o `public_url` para a clínica copiar.
     * Reenviar uma cotação já enviada repete o e-mail (lembrete) com o MESMO link; com
     * `$quoteSupplierIds`, só para esses (ids de `purchase_quote_suppliers` desta cotação).
     *
     * @param  list<int>|null  $quoteSupplierIds
     */
    public function send(PurchaseQuote $quote, ?array $quoteSupplierIds = null): PurchaseQuote
    {
        abort_if($quote->status === PurchaseQuoteStatus::CLOSED, 422, 'Cotação encerrada não pode ser enviada.');
        abort_if($quote->valid_until->toDateString() < BusinessClock::todayString(), 422, 'O prazo de resposta já passou: ajuste a validade antes de enviar.');

        $quote->loadMissing(['items.product', 'suppliers.supplier', 'organization', 'professional.professional']);

        $targets = $this->targets($quote, $quoteSupplierIds);

        DB::transaction(function () use ($quote, $targets): void {
            // Link para todos (a clínica pode copiar de qualquer um); e-mail só para os alvos.
            $quote->suppliers->each(fn (PurchaseQuoteSupplier $s) => $this->tokens->ensureIssued($s));
            $targets->each(fn (PurchaseQuoteSupplier $s) => $this->notify($quote, $s));

            $quote->update(['status' => PurchaseQuoteStatus::SENT, 'sent_at' => $quote->sent_at ?? now()]);
        });

        return $quote->fresh(PurchaseQuote::RESOURCE_RELATIONS);
    }

    /**
     * @param  list<int>|null  $ids
     * @return \Illuminate\Support\Collection<int, PurchaseQuoteSupplier>
     */
    private function targets(PurchaseQuote $quote, ?array $ids): \Illuminate\Support\Collection
    {
        if ($ids === null) {
            return $quote->suppliers;
        }

        $unknown = array_diff(array_map('intval', $ids), $quote->suppliers->pluck('id')->all());

        if ($unknown !== []) {
            throw ValidationException::withMessages(['quote_supplier_ids' => 'Fornecedor não pertence a esta cotação.']);
        }

        return $quote->suppliers->whereIn('id', array_map('intval', $ids))->values();
    }

    /** Nome com que a clínica aparece para o fornecedor — só o nome (nada de CNPJ, endereço). */
    public static function clinicName(PurchaseQuote $quote): string
    {
        $quote->loadMissing(['organization', 'professional.professional']);

        return $quote->organization?->business_name
            ?: ($quote->professional?->professional?->business_name ?: ($quote->professional?->name ?? 'Clínica'));
    }

    private function notify(PurchaseQuote $quote, PurchaseQuoteSupplier $quoteSupplier): void
    {
        $email = $quoteSupplier->supplier?->email;

        if (! $quoteSupplier->status->isAwaiting() || $email === null || $email === '') {
            return;
        }

        Mail::to($email)->queue(new PurchaseQuoteRequestMail(
            self::clinicName($quote),
            $quoteSupplier->supplier->trade_name ?: $quoteSupplier->supplier->legal_name,
            (int) $quote->number,
            $quote->valid_until->format('d/m/Y'),
            (string) $this->tokens->publicUrl($quoteSupplier),
            $quote->items->map(fn ($item): array => ['name' => $item->product->name, 'quantity' => Quantity::of($item->quantity)->toBigDecimal()->strippedOfTrailingZeros()->__toString(), 'unit' => $item->product->unit_of_sale])->all(),
        ));
        $quoteSupplier->update(['emailed_at' => now()]);
    }

    /**
     * Itens e fornecedores no escopo do usuário. Fornecedor sai da lista → sai da cotação;
     * o prazo de resposta de cada um acompanha o da cotação.
     *
     * @param  array<string, mixed>  $data
     */
    private function sync(PurchaseQuote $quote, User $user, array $data): void
    {
        if (array_key_exists('items', $data)) {
            $quote->items()->delete();

            foreach ($data['items'] as $index => $item) {
                $product = $this->scope->scopeQuery(Product::query(), $user)->find((int) $item['product_id'])
                    ?? throw ValidationException::withMessages(["items.{$index}.product_id" => 'Produto não encontrado.']);
                $quantity = Quantity::of($item['quantity']);
                abort_unless($product->acceptsQuantity($quantity), 422, sprintf('"%s" é comprado em unidades inteiras.', $product->name));
                $quote->items()->create(['product_id' => $product->id, 'quantity' => $quantity->toString()]);
            }
        }

        if (array_key_exists('supplier_ids', $data)) {
            $quote->suppliers()->delete();

            foreach (array_values($data['supplier_ids']) as $index => $supplierId) {
                $supplier = $this->scope->scopeQuery(Supplier::query(), $user)->find((int) $supplierId)
                    ?? throw ValidationException::withMessages(["supplier_ids.{$index}" => 'Fornecedor não encontrado.']);
                $quote->suppliers()->create(['supplier_id' => $supplier->id, 'status' => PurchaseQuoteSupplierStatus::REQUESTED, 'valid_until' => $quote->valid_until]);
            }
        }

        $quote->suppliers()->update(['valid_until' => $quote->fresh()->valid_until]);
    }
}
