<?php

namespace App\Services\Commercial;

use App\Contracts\Sellable;
use App\Enums\CashMovementType;
use App\Enums\DiscountType;
use App\Enums\FiscalOperation;
use App\Enums\ProductPurpose;
use App\Enums\QuoteStatus;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\Commercial\CashRegisterRequiredException;
use App\Exceptions\Commercial\PriceOverrideNotAllowedException;
use App\Exceptions\Commercial\SaleNotEditableException;
use App\Models\CashRegister;
use App\Models\FinancialAccount;
use App\Models\OrganizationMember;
use App\Models\PaymentMethod;
use App\Models\Pet;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReceipt;
use App\Models\SaleReturnItem;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\Finance\SaleFinancialEntryRecorder;
use App\Services\Professional\ProfessionalClientsQuery;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Venda de balcão — contrato docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * Único lugar que cria venda, adiciona item, aplica desconto e registra recebimento. As regras
 * que este service concentra e que não podem vazar para o controller:
 *
 *  - venda só entra em caixa ABERTO (delegado a `CashRegisterService`, que é o portão), e
 *    o recebimento entra no caixa aberto de QUEM RECEBE — não no de quem abriu a venda;
 *  - produto, serviço, cliente, animal e funcionário do item são sempre do escopo da clínica
 *    (nunca um id solto vindo do app: vender o produto de outra clínica baixaria o estoque
 *    dela);
 *  - orçamento (`kind = quote`) não movimenta caixa nem estoque — só a conversão movimenta;
 *  - item com `allow_price_override = false` recusa preço diferente do cadastrado;
 *  - recebimento grava taxa e previsão de depósito CONGELADAS a partir da forma de pagamento.
 */
final class SaleService
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly CashRegisterService $cashRegisters,
        private readonly ProfessionalClientsQuery $clients,
        private readonly StockService $stock,
        private readonly SoldPackageService $soldPackages,
        private readonly SaleFinancialEntryRecorder $financialEntries,
        private readonly SaleClientAccountEffects $clientAccounts,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, array $attributes): Sale
    {
        $kind = SaleKind::from($attributes['kind'] ?? SaleKind::SALE->value);
        $ownership = $this->scope->ownershipFor($user);

        $this->assertCounterparty($user, $attributes['client_id'] ?? null, $attributes['pet_id'] ?? null);

        // Orçamento nunca prende caixa: ele pode ser feito hoje e virar venda semana que vem,
        // com outro operador e outro caixa (doc 24). Venda exige o caixa ABERTO de quem vende
        // — sempre o da própria pessoa, nunca um `cash_register_id` vindo do app.
        $cashRegisterId = $kind->movesMoney() ? $this->requireOpenRegister($user, 'vender')->id : null;

        return DB::transaction(function () use ($user, $attributes, $kind, $ownership, $cashRegisterId): Sale {
            return Sale::create([
                'number' => $this->nextNumber($ownership),
                'client_id' => $attributes['client_id'] ?? null,
                'pet_id' => $attributes['pet_id'] ?? null,
                'cash_register_id' => $cashRegisterId,
                'kind' => $kind,
                'fiscal_operation' => FiscalOperation::from(
                    $attributes['fiscal_operation'] ?? FiscalOperation::IN_PERSON_CONSUMER->value
                ),
                'status' => SaleStatus::from($attributes['status'] ?? SaleStatus::OPEN->value),
                'discount_type' => DiscountType::NONE,
                'printed_notes' => $attributes['printed_notes'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'valid_until' => $attributes['valid_until'] ?? null,
                'created_by' => $user->id,
                'sold_at' => $kind->movesMoney() ? ($attributes['sold_at'] ?? now()) : null,
            ] + $this->quoteAttributes($kind, $attributes) + $ownership);
        });
    }

    /**
     * Cabeçalho da venda — "Alterar Cliente" da consulta de vendas (doc 01). Trocar o cliente
     * é permitido mesmo em venda paga (vendeu para o consumidor não identificado e o cliente
     * pediu o nome no recibo depois); o resto só enquanto a venda é editável.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateDetails(Sale $sale, User $user, array $attributes): Sale
    {
        abort_if($sale->status === SaleStatus::CANCELLED, 422, 'Venda cancelada não pode ser alterada.');

        $changesClient = array_key_exists('client_id', $attributes) || array_key_exists('pet_id', $attributes);
        $otherFields = array_intersect_key($attributes, array_flip(['fiscal_operation', 'printed_notes', 'notes', 'valid_until']));

        if ($otherFields !== []) {
            $this->assertEditable($sale);
        }

        if ($changesClient) {
            $clientId = array_key_exists('client_id', $attributes) ? $attributes['client_id'] : $sale->client_id;
            // Trocar o cliente sem informar o animal solta o animal antigo — ele era do outro tutor.
            $petId = array_key_exists('pet_id', $attributes)
                ? $attributes['pet_id']
                : ($clientId === $sale->client_id ? $sale->pet_id : null);

            $this->assertCounterparty($user, $clientId, $petId);

            $sale->client_id = $clientId;
            $sale->pet_id = $petId;
        }

        if (array_key_exists('fiscal_operation', $otherFields)) {
            $sale->fiscal_operation = FiscalOperation::from($otherFields['fiscal_operation']);
        }

        foreach (['printed_notes', 'notes', 'valid_until'] as $field) {
            if (array_key_exists($field, $otherFields)) {
                $sale->{$field} = $otherFields[$field];
            }
        }

        $sale->save();

        return $sale->fresh(Sale::RESOURCE_RELATIONS);
    }

    /**
     * Adiciona uma linha. O preço informado só vence o cadastrado quando o item permite —
     * caso contrário, 422 (critério de aceite do doc 08).
     *
     * `unit_cost` e `commission_percent` são congelados aqui: são a base do doc 09, e a regra
     * de comissão precisa do custo e do percentual QUE VALIAM na hora da venda.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addItem(Sale $sale, User $user, array $attributes): SaleItem
    {
        $this->assertEditable($sale);

        $sellable = $this->resolveSellable($user, $attributes['sellable_type'], (int) $attributes['sellable_id']);
        $this->assertPackageHasPet($sale, $sellable);
        $this->assertStaffBelongsToSale($sale, $attributes['staff_id'] ?? null);
        $quantity = round((float) ($attributes['quantity'] ?? 1), 3);
        $unitPrice = $this->resolveUnitPrice($sellable, $attributes);

        return DB::transaction(function () use ($sale, $sellable, $attributes, $quantity, $unitPrice): SaleItem {
            $item = new SaleItem([
                'sale_id' => $sale->id,
                'sellable_type' => $sellable::class,
                'sellable_id' => $sellable->getKey(),
                'description' => $attributes['description'] ?? $sellable->sellableName(),
                'staff_id' => $attributes['staff_id'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_cost' => $sellable->unitCost(),
                'discount' => round((float) ($attributes['discount'] ?? 0), 2),
                'commission_percent' => $sellable->commissionPercent(),
            ]);

            $item->total = $item->calculateTotal();
            $item->save();

            $this->refreshTotals($sale);

            return $item->load('staff.user');
        });
    }

    /**
     * Edita uma linha já lançada (quantidade, preço, desconto, responsável) mantendo o MESMO
     * `sale_items.id` — a comissão do doc 09 aponta para a linha, e apagar + recriar mudaria o
     * id a cada ajuste de quantidade no balcão. Mesmas travas do `addItem`.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateItem(Sale $sale, int $itemId, array $attributes): SaleItem
    {
        $this->assertEditable($sale);

        $item = $sale->items()->with('sellable')->findOrFail($itemId);

        if (array_key_exists('staff_id', $attributes)) {
            $this->assertStaffBelongsToSale($sale, $attributes['staff_id']);
            $item->staff_id = $attributes['staff_id'];
        }

        if (array_key_exists('quantity', $attributes)) {
            $item->quantity = round((float) $attributes['quantity'], 3);
        }

        if (array_key_exists('discount', $attributes)) {
            $item->discount = round((float) ($attributes['discount'] ?? 0), 2);
        }

        if (array_key_exists('unit_price', $attributes) && $attributes['unit_price'] !== null) {
            $item->unit_price = $item->sellable instanceof Sellable
                ? $this->resolveUnitPrice($item->sellable, $attributes)
                // Item cujo cadastro sumiu: sem cadastro não há regra de preço a proteger.
                : round((float) $attributes['unit_price'], 2);
        }

        return DB::transaction(function () use ($sale, $item): SaleItem {
            $item->total = $item->calculateTotal();
            $item->save();

            $this->refreshTotals($sale);

            return $item->load('staff.user');
        });
    }

    public function removeItem(Sale $sale, int $itemId): void
    {
        $this->assertEditable($sale);

        DB::transaction(function () use ($sale, $itemId): void {
            $sale->items()->whereKey($itemId)->delete();
            $this->refreshTotals($sale);
        });
    }

    public function applyDiscount(Sale $sale, DiscountType $type, float $value): Sale
    {
        $this->assertEditable($sale);

        $sale->discount_type = $type;
        $sale->discount_value = $type === DiscountType::NONE ? 0 : round($value, 2);
        $this->refreshTotals($sale);

        return $sale->fresh(Sale::RESOURCE_RELATIONS);
    }

    /**
     * Registra UM recebimento. Chamado uma vez por forma de pagamento — "dinheiro + cartão"
     * são duas chamadas, dois `sale_receipts` e dois movimentos de caixa (critério de aceite).
     *
     * Quando o total recebido cobre a venda, ela vira `paid`, o estoque baixa e a data de
     * venda é carimbada. Enquanto não cobrir, fica `unpaid` — é o pagamento parcial, que o
     * balcão usa o dia inteiro.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function registerReceipt(Sale $sale, User $user, array $attributes): SaleReceipt
    {
        abort_if($sale->isQuote(), 422, 'Orçamento não recebe pagamento. Converta em venda primeiro.');
        abort_if($sale->status === SaleStatus::CANCELLED, 422, 'Venda cancelada não recebe pagamento.');
        abort_if($sale->status === SaleStatus::PAID, 422, 'Esta venda já está paga.');
        abort_if((float) $sale->total <= 0, 422, 'Adicione ao menos um item antes de receber.');

        $method = $this->scope->scopeQuery(PaymentMethod::query(), $user)
            ->active()
            ->find($attributes['payment_method_id']);

        if ($method === null) {
            throw ValidationException::withMessages(['payment_method_id' => 'Forma de recebimento inválida.']);
        }

        $accountId = $attributes['account_id'] ?? $method->default_account_id;

        if ($accountId !== null && ! $this->scope->scopeQuery(FinancialAccount::query(), $user)->whereKey((int) $accountId)->exists()) {
            throw ValidationException::withMessages(['account_id' => 'Conta não encontrada nesta clínica.']);
        }

        $amount = round((float) $attributes['amount'], 2);
        abort_if($amount <= 0, 422, 'O valor do recebimento deve ser maior que zero.');

        // Troco é do balcão, não da venda: o app manda só o que abate o saldo. Aceitar mais
        // que o devido registraria dinheiro que voltou para o bolso do cliente como receita.
        abort_if(
            $amount - $sale->amountDue() > 0.01,
            422,
            sprintf('O valor recebido (R$ %s) é maior que o saldo da venda (R$ %s).', number_format($amount, 2, ',', '.'), number_format($sale->amountDue(), 2, ',', '.'))
        );

        // Recebimento em conta corrente do cliente (doc 11) é dívida, não dinheiro: não precisa
        // de caixa. Todo o resto entra na gaveta de quem está recebendo.
        $register = $method->kind->isDeferredToClientAccount() ? null : $this->requireOpenRegister($user, 'receber');

        return DB::transaction(function () use ($sale, $user, $attributes, $method, $amount, $register, $accountId): SaleReceipt {
            $receivedAt = isset($attributes['received_at'])
                ? \Carbon\CarbonImmutable::parse($attributes['received_at'])
                : \Carbon\CarbonImmutable::now();

            $fee = $method->feeFor($amount);

            $receipt = $sale->receipts()->create([
                'payment_method_id' => $method->id,
                'account_id' => $accountId,
                'amount' => $amount,
                'installments' => (int) ($attributes['installments'] ?? 1),
                'received_at' => $receivedAt,
                'operator_fee' => $fee,
                'net_amount' => round($amount - $fee, 2),
                // Só quem passa por adquirente espera depósito. Dinheiro na gaveta não tem
                // "previsão de liquidação", e preencher com a data de hoje faria a tela de
                // conciliação listar toda venda em dinheiro como pendente.
                'expected_settlement_date' => $method->kind->settlesThroughAcquirer()
                    ? $method->expectedSettlementDate($receivedAt)->toDateString()
                    : null,
                'received_by' => $user->id,
                'notes' => $attributes['notes'] ?? null,
            ]);

            // Recebimento em `account_credit` (doc 11): consome o saldo credor do cliente
            // ANTES de mexer em caixa/estoque — 422 aqui desfaz a transação inteira (o
            // recebimento não se conclui sem saldo suficiente).
            $this->clientAccounts->consumeCreditForReceipt($sale, $method, $amount, $user);

            if ($register !== null) {
                $this->mirrorReceiptInCashRegister($register, $sale, $receipt, $method, $user);
                // Venda que nasceu num caixa já fechado (ontem) passa a pertencer ao caixa que
                // efetivamente recebeu; venda sem caixa ganha um.
                $sale->cash_register_id ??= $register->id;
            }

            $sale->paid_amount = round((float) $sale->receipts()->sum('amount'), 2);
            $sale->status = $sale->isFullyPaid() ? SaleStatus::PAID : SaleStatus::UNPAID;

            if ($sale->status === SaleStatus::PAID) {
                $sale->sold_at ??= now();
                $sale->save();
                $this->deductStock($sale, $user);
                $this->soldPackages->activateFromSale($sale, $user);
                // Perna contábil da venda (doc 02): receita no DRE, produto e serviço
                // separados pelo mesmo rateio de desconto do Financeiro do PDV.
                $this->financialEntries->recordForFullyPaidSale($sale->fresh(['items', 'receipts']), $user);
            } else {
                $sale->save();
                // Venda que fecha `unpaid` (fiado, doc 11): débito no cliente pelo valor em
                // aberto — bloqueia com 422 se ele não tiver `allow_credit_sale` ou estourar o
                // limite, desfazendo também o recebimento que acabou de ser criado.
                $this->clientAccounts->debitIfSaleWentUnpaid($sale, $user);
            }

            return $receipt->load('paymentMethod');
        });
    }

    /**
     * Orçamento → venda. Cria uma venda NOVA copiando os itens, em vez de mudar o `kind` no
     * lugar: o orçamento tem que continuar existindo como peça (doc 24), e o cliente que
     * recebeu o PDF precisa poder conferir o documento original depois.
     */
    public function convertQuoteToSale(Sale $quote, User $user): Sale
    {
        abort_unless($quote->isQuote(), 422, 'Esta venda não é um orçamento.');
        abort_if($quote->converted_to_sale_id !== null, 422, 'Este orçamento já foi convertido em venda.');

        return DB::transaction(function () use ($quote, $user): Sale {
            $sale = $this->create($user, [
                'kind' => SaleKind::SALE->value,
                'client_id' => $quote->client_id,
                'pet_id' => $quote->pet_id,
                'fiscal_operation' => $quote->fiscal_operation->value,
                'printed_notes' => $quote->printed_notes,
                'notes' => $quote->notes,
            ]);

            $this->copyItemsAndDiscount($quote, $sale);

            $quote->update([
                'converted_to_sale_id' => $sale->id,
                'quote_status' => QuoteStatus::CONVERTED,
            ]);

            return $sale->fresh(Sale::RESOURCE_RELATIONS);
        });
    }

    /**
     * Copia as linhas e o desconto de uma venda/orçamento para outra, com os valores
     * CONGELADOS da origem (preço, custo, comissão) — não os do cadastro de hoje. É o que faz
     * a conversão sair "com os mesmos itens e valores" e a revisão partir do que o tutor viu
     * (doc 24). Usado pela conversão (aqui) e pela revisão (`QuoteService::revise`).
     */
    public function copyItemsAndDiscount(Sale $from, Sale $to): void
    {
        foreach ($from->items()->get() as $item) {
            $to->items()->create($item->only([
                'sellable_type', 'sellable_id', 'description', 'staff_id', 'quantity',
                'unit_price', 'unit_cost', 'discount', 'total', 'commission_percent',
            ]));
        }

        $to->discount_type = $from->discount_type;
        $to->discount_value = $from->discount_value;
        $this->refreshTotals($to);
    }

    /**
     * Cancela. Não apaga nada: estorna o que entrou no caixa com movimentos `refund` e devolve
     * o estoque. Venda é documento — some do faturamento, não do histórico.
     */
    public function cancel(Sale $sale, User $user, string $reason): Sale
    {
        abort_if($sale->status === SaleStatus::CANCELLED, 422, 'Venda já cancelada.');

        return DB::transaction(function () use ($sale, $user, $reason): Sale {
            // O estorno sai da gaveta de quem está devolvendo o dinheiro agora; sem caixa
            // aberto, do caixa da venda se ainda estiver aberto. Com os dois fechados não há
            // gaveta onde lançar — a devolução fica registrada só no cancelamento.
            $register = $this->cashRegisters->currentFor($user);

            if ($register === null && $sale->cashRegister?->isOpen()) {
                $register = $sale->cashRegister;
            }

            foreach ($sale->receipts()->with('paymentMethod')->get() as $receipt) {
                if ($receipt->paymentMethod?->kind->isDeferredToClientAccount()) {
                    // Devolve o crédito consumido (doc 11) — não depende de caixa aberto: o
                    // saldo do cliente não é dinheiro de gaveta.
                    $this->clientAccounts->refundForCancelledReceipt($sale, $receipt, $user);

                    continue;
                }

                if ($register !== null) {
                    $this->cashRegisters->recordMovement(
                        $register,
                        CashMovementType::REFUND,
                        (float) $receipt->amount,
                        'Estorno da venda '.($sale->number ?? $sale->id),
                        $user,
                        $receipt->payment_method_id,
                        $receipt->account_id,
                        $sale,
                    );
                }
            }

            if ($sale->status === SaleStatus::PAID) {
                $this->restoreStock($sale, $user);
                $this->soldPackages->cancelForSale($sale);
            }

            $sale->update([
                'status' => SaleStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            return $sale->fresh(Sale::RESOURCE_RELATIONS);
        });
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * Campos que só o orçamento tem (doc 24). Venda devolve vazio — `quote_status` fica
     * `null`, e a constraint `sales_quote_status_kind_check` garante isso no banco.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function quoteAttributes(SaleKind $kind, array $attributes): array
    {
        if ($kind !== SaleKind::QUOTE) {
            return [];
        }

        return [
            'quote_status' => QuoteStatus::DRAFT,
            'version' => $attributes['version'] ?? 1,
            'parent_quote_id' => $attributes['parent_quote_id'] ?? null,
            'root_quote_id' => $attributes['root_quote_id'] ?? null,
            'medical_record_id' => $attributes['medical_record_id'] ?? null,
            'hospitalization_id' => $attributes['hospitalization_id'] ?? null,
        ];
    }

    private function assertEditable(Sale $sale): void
    {
        if (! $sale->isEditable()) {
            throw new SaleNotEditableException($sale);
        }
    }

    private function refreshTotals(Sale $sale): void
    {
        $sale->recalculateTotals();
        $sale->save();
    }

    /**
     * `sellable_type` chega como "product"/"service" (o app não conhece namespace PHP) e vira
     * a classe aqui. Um `match` explícito, e não `class_exists($input)`: aceitar nome de
     * classe vindo do cliente seria deixar o usuário escolher qual model instanciar.
     */
    private function resolveSellable(User $user, string $type, int $id): Sellable
    {
        // Sempre pela query ESCOPADA e só item ativo: `findOrFail($id)` puro deixaria vender
        // (e baixar o estoque de) produto de outra clínica mandando um id qualquer.
        $sellable = match ($type) {
            // `purpose=resale` filtra uso interno/consumível fora do balcão (doc 08, regra 2):
            // sem isto, um id de item `internal_use` mandado direto pelo app ainda vendia.
            'product', Product::class => $this->scope->scopeQuery(Product::query(), $user)
                ->active()
                ->where('purpose', ProductPurpose::RESALE->value)
                ->find($id),
            'service', Service::class => $this->scope->scopeQuery(Service::query(), $user)->active()->find($id),
            'package', ServicePackage::class => $this->scope->scopeQuery(ServicePackage::query(), $user)->active()->find($id),
            default => abort(422, 'Tipo de item inválido. Use "product", "service" ou "package".'),
        };

        if ($sellable === null) {
            throw ValidationException::withMessages(['sellable_id' => 'Item não encontrado no catálogo desta clínica.']);
        }

        return $sellable;
    }

    private function requireOpenRegister(User $user, string $action): CashRegister
    {
        return $this->cashRegisters->currentFor($user) ?? throw new CashRegisterRequiredException($action);
    }

    /**
     * Cliente tem que ser cliente de alguém da equipe (mesma definição de "cliente" do resto
     * do sistema, `ProfessionalClientsQuery`), e o animal tem que ser desse cliente. Sem isto,
     * mandar um `client_id` qualquer expunha nome e telefone de qualquer usuário no recibo.
     */
    private function assertCounterparty(User $user, mixed $clientId, mixed $petId): void
    {
        if ($clientId === null) {
            if ($petId !== null) {
                throw ValidationException::withMessages(['client_id' => 'Informe o tutor ao vincular um animal à venda.']);
            }

            return;
        }

        $isClient = $this->clients->queryForAny($this->scope->teamUserIds($user))->whereKey((int) $clientId)->exists();

        if (! $isClient) {
            throw ValidationException::withMessages(['client_id' => 'Cliente não encontrado entre os clientes desta clínica.']);
        }

        if ($petId !== null && ! Pet::whereKey((int) $petId)->where('user_id', (int) $clientId)->exists()) {
            throw ValidationException::withMessages(['pet_id' => 'Este animal não pertence ao cliente informado.']);
        }
    }

    /**
     * Pacote de serviços (doc 10) é vendido a um animal específico, nunca a um crédito
     * genérico do tutor (regra de negócio 1 do spec) — sem `pet_id` no cabeçalho da venda,
     * `SoldPackageService::activateFromSale()` não teria a quem atribuir o saldo.
     */
    private function assertPackageHasPet(Sale $sale, Sellable $sellable): void
    {
        if (! $sellable instanceof ServicePackage) {
            return;
        }

        if ($sale->pet_id === null) {
            throw ValidationException::withMessages([
                'pet_id' => 'Informe o animal para vender um pacote de serviços.',
            ]);
        }
    }

    /**
     * Funcionário responsável tem que ser um vínculo ATIVO da mesma organização da venda —
     * é ele que recebe a comissão (doc 09). Venda sem organização (vet volante) não tem equipe.
     */
    private function assertStaffBelongsToSale(Sale $sale, mixed $staffId): void
    {
        if ($staffId === null) {
            return;
        }

        $belongs = $sale->organization_id !== null && OrganizationMember::query()
            ->whereKey((int) $staffId)
            ->where('organization_id', $sale->organization_id)
            ->where('is_active', true)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages(['staff_id' => 'Funcionário não pertence à equipe desta clínica.']);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveUnitPrice(Sellable $sellable, array $attributes): float
    {
        $cataloguePrice = $sellable->sellableUnitPrice();

        if (! array_key_exists('unit_price', $attributes) || $attributes['unit_price'] === null) {
            return $cataloguePrice;
        }

        $requested = round((float) $attributes['unit_price'], 2);

        // Um centavo de tolerância: o front manda o preço que leu, e 89.90 pode voltar como
        // 89.899999 depois de um `toFixed` mal arredondado. Recusar isso seria recusar o
        // preço do próprio cadastro.
        if (abs($requested - $cataloguePrice) <= 0.01) {
            return $cataloguePrice;
        }

        if (! $sellable->allowsPriceOverride()) {
            throw new PriceOverrideNotAllowedException($sellable, $requested);
        }

        return $requested;
    }

    /**
     * Espelha o recebimento no caixa de quem recebeu. Recebimento em conta corrente do cliente
     * (doc 11) nem chega aqui — é dívida, não dinheiro (ver `registerReceipt()`).
     */
    private function mirrorReceiptInCashRegister(CashRegister $register, Sale $sale, SaleReceipt $receipt, PaymentMethod $method, User $user): void
    {
        $this->cashRegisters->recordMovement(
            $register,
            CashMovementType::SALE_RECEIPT,
            (float) $receipt->amount,
            'Recebimento da venda '.($sale->number ?? $sale->id),
            $user,
            $method->id,
            $receipt->account_id,
            $sale,
            $receipt->received_at,
        );
    }

    /**
     * Baixa de estoque na venda paga — um movimento `sale_out` por item que controla estoque,
     * pelo `StockService` (doc 07): livro `stock_movements`, lote FEFO e lock do produto moram
     * lá. Ordem por produto para duas vendas simultâneas travarem as linhas na mesma sequência.
     */
    private function deductStock(Sale $sale, User $user): void
    {
        foreach ($this->stockItems($sale) as $item) {
            $this->stock->out($item->sellable, StockMovementType::SALE_OUT, (int) ceil((float) $item->quantity), [
                'unit_cost' => (float) $item->unit_cost,
                'reference' => $sale,
                'user' => $user,
                'occurred_at' => $sale->sold_at,
                'notes' => 'Venda '.($sale->number ?? $sale->id),
            ]);
        }
    }

    /**
     * Estorno do cancelamento: `return_in`, sem apagar o `sale_out` original (critério de
     * aceite do doc 07). O que já voltou por devolução parcial (`sale_returns`) não volta de
     * novo.
     */
    private function restoreStock(Sale $sale, User $user): void
    {
        foreach ($this->stockItems($sale) as $item) {
            $returned = (int) SaleReturnItem::query()->where('sale_item_id', $item->id)->sum('quantity');
            $quantity = (int) ceil((float) $item->quantity) - $returned;

            if ($quantity < 1) {
                continue;
            }

            $this->stock->in($item->sellable, StockMovementType::RETURN_IN, $quantity, [
                'unit_cost' => (float) $item->unit_cost,
                'reference' => $sale,
                'user' => $user,
                'notes' => 'Cancelamento da venda '.($sale->number ?? $sale->id),
            ]);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, SaleItem>
     */
    private function stockItems(Sale $sale): \Illuminate\Support\Collection
    {
        return $sale->items()->with('sellable')->get()
            ->filter(fn (SaleItem $item): bool => $item->movesStock() && $item->sellable instanceof Product)
            ->sortBy('sellable_id')
            ->values();
    }

    /**
     * Próximo número visível da venda, por dono: `MAX + 1` dentro da transação da criação.
     *
     * A serialização é um advisory lock TRANSACIONAL por dono, não `lockForUpdate()`: o
     * PostgreSQL recusa `FOR UPDATE` junto de agregação (`MAX`), e travar as linhas não
     * seguraria a primeira venda de um dono que ainda não tem nenhuma. O índice único parcial
     * (`sales_org_number_unique`) continua sendo a garantia final.
     *
     * @param  array{organization_id: int|null, professional_id: int}  $ownership
     */
    private function nextNumber(array $ownership): int
    {
        $query = Sale::withTrashed();

        if ($ownership['organization_id'] !== null) {
            $query->where('organization_id', $ownership['organization_id']);
            $lockKey = 'sales_number:organization:'.$ownership['organization_id'];
        } else {
            $query->where('professional_id', $ownership['professional_id'])->whereNull('organization_id');
            $lockKey = 'sales_number:professional:'.$ownership['professional_id'];
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$lockKey]);
        }

        return ((int) $query->max('number')) + 1;
    }
}
