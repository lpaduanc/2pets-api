<?php

namespace App\Services\Commercial;

use App\Enums\NotificationType;
use App\Enums\QuoteStatus;
use App\Enums\SaleKind;
use App\Exceptions\Commercial\PriceOverrideNotAllowedException;
use App\Exceptions\Commercial\QuoteTransitionException;
use App\Exceptions\Commercial\SaleNotEditableException;
use App\Models\Appointment;
use App\Models\ConsentLog;
use App\Models\Hospitalization;
use App\Models\MedicalRecord;
use App\Models\Sale;
use App\Models\User;
use App\Notifications\QuoteReceivedMail;
use App\Notifications\Support\FrontendRoute;
use App\Services\Notification\NotificationService;
use App\Services\Report\QuotePdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orçamento — docs/gap-simplesvet/24-orcamentos.md.
 *
 * Orçamento é `sales.kind = quote`, então itens, desconto e totais continuam sendo de
 * `SaleService` (este service nunca soma nada). O que mora aqui é só o que a venda não tem:
 * o ciclo envio → visualização → decisão do tutor, a revisão em versões e a conversão.
 *
 * Invariantes que este service garante:
 *  - orçamento não movimenta caixa, estoque nem financeiro — só `convert()` movimenta, e o faz
 *    delegando a `SaleService` (que é quem sabe baixar estoque e espelhar no caixa);
 *  - vencido (`valid_until` < hoje) não é aprovado, enviado nem convertido — lido do status
 *    EFETIVO (`Sale::effectiveQuoteStatus()`), sem job;
 *  - aprovado é imutável; mudança é `revise()`, que cria a próxima versão e preserva a anterior;
 *  - toda decisão do tutor grava data, IP e canal, e vira linha em `consent_logs`: é
 *    consentimento sobre procedimento, dado sensível.
 */
final class QuoteService
{
    /** Validade padrão quando a clínica envia sem informar uma. */
    public const DEFAULT_VALIDITY_DAYS = 15;

    public function __construct(
        private readonly SaleService $sales,
        private readonly QuotePublicTokenService $tokens,
        private readonly QuotePdfService $pdf,
        private readonly NotificationService $notifications,
    ) {}

    // ------------------------------------------------------------------
    // Criação
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, array $attributes): Sale
    {
        return $this->sales->create($user, [...$attributes, 'kind' => SaleKind::QUOTE->value]);
    }

    /**
     * Orçamento a partir de um atendimento: tutor e animal vêm do prontuário, e os itens
     * sugeridos são os serviços e cobranças já lançados no agendamento dele (doc 09 §13) — é
     * o "itens sugeridos pelo procedimento" do doc 24. A clínica ajusta depois, no rascunho.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createFromRecord(MedicalRecord $record, User $user, array $attributes = []): Sale
    {
        $record->loadMissing(['pet', 'appointment']);

        return DB::transaction(function () use ($record, $user, $attributes): Sale {
            $quote = $this->create($user, [
                ...$attributes,
                'client_id' => $record->pet?->user_id,
                'pet_id' => $record->pet_id,
                'medical_record_id' => $record->id,
            ]);

            $this->prefillFromAppointment($quote, $user, $record->appointment);

            return $quote;
        });
    }

    /**
     * Mesmo raciocínio para a internação prolongada — o caso de orçamento mais comum depois
     * da cirurgia. As diárias e procedimentos já lançados na conta da internação viram itens.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createFromHospitalization(Hospitalization $hospitalization, User $user, array $attributes = []): Sale
    {
        $hospitalization->loadMissing(['pet', 'appointment']);

        return DB::transaction(function () use ($hospitalization, $user, $attributes): Sale {
            $quote = $this->create($user, [
                ...$attributes,
                'client_id' => $hospitalization->pet?->user_id,
                'pet_id' => $hospitalization->pet_id,
                'hospitalization_id' => $hospitalization->id,
            ]);

            $this->prefillFromAppointment($quote, $user, $hospitalization->appointment);

            return $quote;
        });
    }

    /**
     * Cabeçalho do rascunho (tutor, animal, validade, observações).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateHeader(Sale $quote, User $user, array $attributes): Sale
    {
        $this->assertQuote($quote);

        // `SaleService::updateDetails` deixa trocar o cliente de venda já fechada (é correção
        // de cadastro); em orçamento, trocar o tutor depois do envio mudaria o destinatário de
        // um documento já entregue — por isso a trava vem antes, para TODOS os campos.
        if (! $quote->isEditable()) {
            throw new SaleNotEditableException($quote);
        }

        return $this->sales->updateDetails($quote, $user, array_intersect_key($attributes, array_flip([
            'client_id', 'pet_id', 'valid_until', 'printed_notes', 'notes',
        ])));
    }

    // ------------------------------------------------------------------
    // Envio e leitura pelo tutor
    // ------------------------------------------------------------------

    /**
     * Envia (ou reenvia) ao tutor: congela a validade, gera o PDF, emite o link público e
     * notifica. Devolve o token em texto puro — a ÚNICA vez que ele existe fora do hash; a
     * clínica usa para compartilhar o link (WhatsApp, e-mail) com quem não tem o app.
     *
     * @return array{quote: Sale, token: string}
     */
    public function send(Sale $quote, User $user): array
    {
        $this->assertQuote($quote);

        $status = $quote->effectiveQuoteStatus();

        if ($status === QuoteStatus::EXPIRED) {
            throw QuoteTransitionException::expired();
        }

        if (! in_array($status, [QuoteStatus::DRAFT, QuoteStatus::SENT, QuoteStatus::VIEWED], true)) {
            throw new QuoteTransitionException(
                'Só é possível enviar um orçamento em rascunho ou aguardando decisão.',
                'quote_not_sendable'
            );
        }

        if ($quote->client_id === null) {
            throw new QuoteTransitionException('Informe o tutor antes de enviar o orçamento.', 'quote_without_client');
        }

        if (! $quote->items()->exists()) {
            throw new QuoteTransitionException('Adicione ao menos um item antes de enviar o orçamento.', 'quote_without_items');
        }

        $token = DB::transaction(function () use ($quote): string {
            $quote->forceFill([
                'quote_status' => QuoteStatus::SENT,
                'sent_at' => now(),
                'viewed_at' => null,
                'valid_until' => $quote->valid_until ?? today()->addDays(self::DEFAULT_VALIDITY_DAYS),
            ])->save();

            $token = $this->tokens->issue($quote);

            $quote->forceFill(['pdf_path' => $this->pdf->store($quote)])->save();

            return $token;
        });

        $this->notifyTutor($quote, $token);

        return ['quote' => $quote, 'token' => $token];
    }

    /** Primeira abertura pelo tutor (app ou link) depois do envio. Idempotente. */
    public function markViewed(Sale $quote): void
    {
        if ($quote->effectiveQuoteStatus() !== QuoteStatus::SENT) {
            return;
        }

        $quote->forceFill(['quote_status' => QuoteStatus::VIEWED, 'viewed_at' => now()])->save();
    }

    // ------------------------------------------------------------------
    // Decisão do tutor
    // ------------------------------------------------------------------

    public function approve(Sale $quote, string $channel, ?string $ip, ?string $userAgent): Sale
    {
        return $this->decide($quote, true, null, $channel, $ip, $userAgent);
    }

    public function reject(Sale $quote, ?string $reason, string $channel, ?string $ip, ?string $userAgent): Sale
    {
        return $this->decide($quote, false, $reason, $channel, $ip, $userAgent);
    }

    /**
     * Decisão pelo link sem login. O token é relido COM LOCK dentro da transação: dois cliques
     * simultâneos no mesmo link não podem virar duas decisões ("o token não funciona duas
     * vezes"). Link inválido é `null` (o controller responde 404); link usado, 410.
     */
    public function decideByToken(string $plainToken, bool $approved, ?string $reason, ?string $ip, ?string $userAgent): ?Sale
    {
        return DB::transaction(function () use ($plainToken, $approved, $reason, $ip, $userAgent): ?Sale {
            $quote = $this->tokens->find($plainToken, lockForUpdate: true);

            if ($quote === null) {
                return null;
            }

            $this->assertPublicLinkUsable($quote);

            return $this->decide($quote, $approved, $reason, 'public_link', $ip, $userAgent);
        });
    }

    /**
     * Link público ainda serve? Usado (decidido, revisado, convertido) é 410; vencido também
     * — o token expira junto com a validade.
     */
    public function assertPublicLinkUsable(Sale $quote): void
    {
        if ($quote->public_token_used_at !== null) {
            throw QuoteTransitionException::linkUsed();
        }

        $status = $quote->effectiveQuoteStatus();

        if ($status === QuoteStatus::EXPIRED) {
            throw QuoteTransitionException::expired(410);
        }

        if (! $status?->isAwaitingDecision()) {
            throw QuoteTransitionException::linkUsed();
        }
    }

    // ------------------------------------------------------------------
    // Revisão e conversão (clínica)
    // ------------------------------------------------------------------

    /**
     * Nova versão a partir desta, com os mesmos itens e valores para a clínica ajustar. A
     * anterior NÃO é alterada nos valores — o tutor precisa comparar as duas (doc 24). Se ela
     * ainda aguardava decisão, vira `superseded` e o link dela morre; se já tinha sido aprovada
     * ou recusada, fica como está: é o registro do que o tutor decidiu sobre AQUELA versão.
     */
    public function revise(Sale $quote, User $user): Sale
    {
        $this->assertQuote($quote);

        if (in_array($quote->quote_status, [QuoteStatus::CONVERTED, QuoteStatus::SUPERSEDED], true)) {
            throw new QuoteTransitionException(
                'Este orçamento já foi convertido ou substituído; revise a versão mais recente.',
                'quote_not_revisable'
            );
        }

        return DB::transaction(function () use ($quote, $user): Sale {
            $rootId = $quote->rootQuoteId();
            // Lock na v1 serializa duas revisões simultâneas da mesma família (o Postgres não
            // aceita `FOR UPDATE` junto de `MAX`, então o lock vai na linha raiz).
            Sale::query()->whereKey($rootId)->lockForUpdate()->first();
            $latestVersion = (int) Sale::query()
                ->where(fn ($q) => $q->whereKey($rootId)->orWhere('root_quote_id', $rootId))
                ->max('version');

            $revision = $this->create($user, [
                'client_id' => $quote->client_id,
                'pet_id' => $quote->pet_id,
                'fiscal_operation' => $quote->fiscal_operation->value,
                'printed_notes' => $quote->printed_notes,
                'notes' => $quote->notes,
                // Validade vencida não passa para a nova versão: a clínica define outra, ou o
                // envio aplica a padrão.
                'valid_until' => $quote->isPastValidity() ? null : $quote->valid_until?->toDateString(),
                'version' => $latestVersion + 1,
                'parent_quote_id' => $quote->id,
                'root_quote_id' => $rootId,
                'medical_record_id' => $quote->medical_record_id,
                'hospitalization_id' => $quote->hospitalization_id,
            ]);

            $this->sales->copyItemsAndDiscount($quote, $revision);

            if ($quote->quote_status->canExpire()) {
                $quote->forceFill([
                    'quote_status' => QuoteStatus::SUPERSEDED,
                    'public_token_used_at' => $quote->public_token_hash !== null ? now() : null,
                ])->save();
            }

            return $revision;
        });
    }

    /**
     * Orçamento → venda. Permitido no aprovado e, como no balcão do SimplesVet, no ainda não
     * decidido quando o tutor autoriza pessoalmente. Nunca no vencido, recusado ou substituído.
     *
     * A venda nasce pelo mesmo `SaleService::convertQuoteToSale` (itens e valores copiados), e
     * cada recebimento informado passa por `SaleService::registerReceipt` — é ali que o caixa
     * ganha o movimento, o estoque baixa quando a venda quita, e o recebimento grava conta,
     * taxa e previsão de depósito (a parte financeira que existe hoje; o razão do doc 02 ainda
     * não existe). Sem recebimento, a venda fica em aberto para o balcão receber depois.
     *
     * Como toda venda do doc 01, exige o caixa ABERTO de quem converte
     * (`CashRegisterRequiredException`, 422 `cash_register_required`).
     *
     * @param  list<array<string, mixed>>  $receipts
     */
    public function convert(Sale $quote, User $user, array $receipts = []): Sale
    {
        $this->assertQuote($quote);

        $status = $quote->effectiveQuoteStatus();

        if ($status === QuoteStatus::EXPIRED) {
            throw QuoteTransitionException::expired();
        }

        if (! in_array($status, [QuoteStatus::APPROVED, QuoteStatus::DRAFT, QuoteStatus::SENT, QuoteStatus::VIEWED], true)) {
            throw new QuoteTransitionException(
                sprintf('Orçamento %s não pode ser convertido em venda.', mb_strtolower((string) $status?->label())),
                'quote_not_convertible'
            );
        }

        if (! $quote->items()->exists()) {
            throw new QuoteTransitionException('Orçamento sem itens não pode virar venda.', 'quote_without_items');
        }

        return DB::transaction(function () use ($quote, $user, $receipts): Sale {
            $sale = $this->sales->convertQuoteToSale($quote, $user);

            if ($quote->public_token_hash !== null && $quote->public_token_used_at === null) {
                $this->tokens->consume($quote);
            }

            foreach ($receipts as $receipt) {
                $this->sales->registerReceipt($sale, $user, $receipt);
                $sale->refresh();
            }

            return $sale->fresh(Sale::RESOURCE_RELATIONS);
        });
    }

    /**
     * Todas as versões da família, da v1 à mais recente — base da tela de comparação.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Sale>
     */
    public function versionsOf(Sale $quote, array $with = []): \Illuminate\Database\Eloquent\Collection
    {
        $rootId = $quote->rootQuoteId();

        return Sale::query()
            ->quotesOnly()
            ->where(fn ($q) => $q->whereKey($rootId)->orWhere('root_quote_id', $rootId))
            ->with($with)
            ->orderBy('version')
            ->get();
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function decide(Sale $quote, bool $approved, ?string $reason, string $channel, ?string $ip, ?string $userAgent): Sale
    {
        $this->assertQuote($quote);

        $decided = DB::transaction(function () use ($quote, $approved, $reason, $channel, $ip, $userAgent): Sale {
            /** @var Sale $locked */
            $locked = Sale::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $status = $locked->effectiveQuoteStatus();

            if ($status === QuoteStatus::EXPIRED) {
                throw QuoteTransitionException::expired($channel === 'public_link' ? 410 : 422);
            }

            if (! $status?->isAwaitingDecision()) {
                throw $channel === 'public_link'
                    ? QuoteTransitionException::linkUsed()
                    : new QuoteTransitionException('Este orçamento não está aguardando sua decisão.', 'quote_not_awaiting_decision');
            }

            $now = now();

            $locked->forceFill([
                'quote_status' => $approved ? QuoteStatus::APPROVED : QuoteStatus::REJECTED,
                'decided_at' => $now,
                'decided_by' => $locked->client_id,
                'decision_channel' => $channel,
                'decision_ip' => $ip,
                'rejection_reason' => $approved ? null : $reason,
                // Decidir pelo app também encerra o link: uma decisão por orçamento.
                'public_token_used_at' => $locked->public_token_hash !== null ? $now : null,
            ])->save();

            // Consentimento (ou recusa) sobre procedimento — mesma trilha imutável da LGPD
            // (`LgpdController::logConsent`). A chave carrega o id do orçamento; a versão e o
            // valor aprovados estão na própria linha de `sales`, que não muda mais.
            ConsentLog::create([
                'user_id' => $locked->client_id,
                'consent_key' => 'quote_approval:'.$locked->id,
                'granted' => $approved,
                'source' => 'quote_'.$channel,
                'ip_address' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
                'occurred_at' => $now,
            ]);

            return $locked;
        });

        $this->notifyClinic($decided, $approved);

        return $decided;
    }

    /**
     * Serviços contratados + cobranças lançadas no agendamento viram itens do rascunho, pelo
     * MESMO `SaleService::addItem` do balcão. O preço combinado no agendamento (snapshot) é
     * mantido quando o serviço permite; quando não permite, vale o de tabela — o
     * `PriceOverrideNotAllowedException` é justamente essa regra dizendo "use o cadastro".
     * Cobrança avulsa sem serviço de catálogo não vira item: `sale_items` exige um `Sellable`.
     */
    private function prefillFromAppointment(Sale $quote, User $user, ?Appointment $appointment): void
    {
        if ($appointment === null) {
            return;
        }

        $lines = $appointment->services()->with('service')->get()
            ->map(fn ($line) => [$line->service, (float) $line->quantity, (float) $line->unit_price, null])
            ->concat($appointment->charges()->whereNotNull('service_id')->with('service')->get()
                ->map(fn ($charge) => [$charge->service, (float) $charge->quantity, (float) $charge->unit_price, $charge->description]));

        foreach ($lines as [$service, $quantity, $unitPrice, $description]) {
            if ($service === null) {
                continue;
            }

            $attributes = [
                'sellable_type' => 'service',
                'sellable_id' => $service->id,
                'quantity' => $quantity > 0 ? $quantity : 1,
                'unit_price' => $unitPrice,
                'description' => $description,
            ];

            try {
                $this->sales->addItem($quote, $user, $attributes);
            } catch (PriceOverrideNotAllowedException) {
                $this->sales->addItem($quote, $user, [...$attributes, 'unit_price' => null]);
            }
        }
    }

    private function assertQuote(Sale $quote): void
    {
        if (! $quote->isQuote()) {
            throw new QuoteTransitionException('Esta venda não é um orçamento.', 'not_a_quote');
        }
    }

    private function notifyTutor(Sale $quote, string $plainToken): void
    {
        $client = $quote->client()->first();

        if ($client === null) {
            return;
        }

        $issuer = QuoteIssuer::for($quote);
        $petName = $quote->pet()->value('name');

        $this->safely(fn () => $this->notifications->sendNotification(
            $client,
            NotificationType::QUOTE_RECEIVED,
            'Novo orçamento de '.$issuer['name'],
            sprintf(
                'Orçamento nº %d%s: R$ %s, válido até %s. Toque para ver e aprovar.',
                $quote->number,
                $petName ? ' para '.$petName : '',
                number_format((float) $quote->total, 2, ',', '.'),
                $quote->valid_until?->format('d/m/Y')
            ),
            ['quote_id' => $quote->id],
            FrontendRoute::tutorQuote($quote->id),
            new QuoteReceivedMail($quote, $plainToken),
        ));
    }

    private function notifyClinic(Sale $quote, bool $approved): void
    {
        $recipient = $quote->createdBy()->first();

        if ($recipient === null) {
            return;
        }

        $petName = $quote->pet()->value('name');

        $this->safely(fn () => $this->notifications->sendNotification(
            $recipient,
            $approved ? NotificationType::QUOTE_APPROVED : NotificationType::QUOTE_REJECTED,
            $approved ? 'Orçamento aprovado' : 'Orçamento recusado',
            sprintf(
                'O tutor %s o orçamento nº %d%s.%s',
                $approved ? 'aprovou' : 'recusou',
                $quote->number,
                $petName ? ' ('.$petName.')' : '',
                ! $approved && $quote->rejection_reason ? ' Motivo: '.$quote->rejection_reason : ''
            ),
            ['quote_id' => $quote->id],
            FrontendRoute::professionalQuote($quote->id),
        ));
    }

    /**
     * Notificação é consequência, não parte da transação: falha de canal não pode desfazer um
     * envio ou uma aprovação já gravados.
     */
    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::error('Quote notification failed', ['error' => $e->getMessage()]);
        }
    }
}
