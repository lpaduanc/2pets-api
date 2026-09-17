<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceStatus;
use App\Models\Appointment;
use App\Models\AppointmentCharge;
use App\Models\AppointmentService;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.1/§13.5.
 *
 * O lançamento nasce automático e `pending` ao INICIAR o atendimento, com itens vindos
 * dos serviços do agendamento (`AppointmentService`) — não existe mais `draft` invisível
 * nem `issue()` para fatura de atendimento (isso sobrevive só para a fatura manual).
 * Itens podem ser acrescentados (via `AppointmentCharge`) enquanto a fatura não foi
 * paga; eles só congelam no pagamento (invariante 13), nunca na emissão.
 */
final class AppointmentInvoiceService
{
    public function __construct(private readonly InvoiceTotalsCalculator $totalsCalculator) {}

    /**
     * Chamado só por `ConsultationService::start()`. Idempotente: uma segunda chamada no
     * mesmo agendamento (reabrir consulta) devolve a mesma fatura, nunca cria uma segunda.
     * Sem serviço nenhum no agendamento, não lança nada (invariante 11) — o vet ainda
     * pode lançar depois via `/charges`, que cria a fatura sob demanda (`syncItems`).
     */
    public function ensurePendingInvoice(Appointment $appointment): ?Invoice
    {
        $existing = Invoice::where('appointment_id', $appointment->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $items = $this->buildItems($appointment);

        if (empty($items)) {
            return null;
        }

        return $this->createInvoice($appointment, $items);
    }

    /**
     * Chamado por `AppointmentChargeService` depois de criar/editar/remover uma linha.
     * Cria a fatura sob demanda quando o primeiro lançamento acontece sem serviço
     * nenhum no agendamento; quando ela já existe e está `pending`, só recalcula os
     * itens. Fatura paga/cancelada não é tocada — itens congelam no pagamento
     * (invariante 13), correção é cancelar e reemitir, nunca editar aqui.
     *
     * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §4/§6:
     * o `discount`/`tax` JÁ gravados na fatura (via `PUT /invoices/{id}`, editável enquanto
     * `pending`) são preservados aqui — antes este método recalculava sempre com
     * `discount = 0.0` fixo, o que descartava em silêncio um desconto aplicado antes da
     * próxima diária/cobrança ser lançada.
     */
    public function syncItems(Appointment $appointment): void
    {
        $items = $this->buildItems($appointment);
        $invoice = Invoice::where('appointment_id', $appointment->id)->first();

        if ($invoice === null) {
            if (! empty($items)) {
                $this->createInvoice($appointment, $items);
            }

            return;
        }

        if (! $invoice->isPending()) {
            return;
        }

        $invoice->update($this->totalsCalculator->recalculate($items, (float) $invoice->discount, (float) $invoice->tax));
    }

    /**
     * @return array<int, array{service_id: ?int, description: string, quantity: float, unit_price: float}>
     */
    private function buildItems(Appointment $appointment): array
    {
        $appointment->loadMissing('services.service', 'charges');

        return [
            ...$this->serviceLinesToItems($appointment->services),
            ...$this->chargesToItems($appointment->charges),
        ];
    }

    /**
     * @param  Collection<int, AppointmentService>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function serviceLinesToItems(Collection $lines): array
    {
        return $lines->map(fn (AppointmentService $line): array => [
            'service_id' => $line->service_id,
            'description' => $line->service?->name ?? 'Serviço',
            'quantity' => (float) $line->quantity,
            'unit_price' => (float) $line->unit_price,
        ])->all();
    }

    /**
     * @param  Collection<int, AppointmentCharge>  $charges
     * @return array<int, array<string, mixed>>
     */
    private function chargesToItems(Collection $charges): array
    {
        return $charges->map(fn (AppointmentCharge $charge): array => [
            'service_id' => $charge->service_id,
            'description' => $charge->description,
            'quantity' => (float) $charge->quantity,
            'unit_price' => (float) $charge->unit_price,
        ])->all();
    }

    /**
     * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §6:
     * o índice único de `invoices.appointment_id` torna a corrida (duas requisições
     * lançando charge ao mesmo tempo, ambas veem `Invoice::where(...)->first() === null`)
     * um erro de integridade em vez de uma segunda fatura — SQLSTATE 23505 aqui não é uma
     * falha real, é a prova de que a outra requisição venceu a corrida primeiro; devolve a
     * fatura que ela criou em vez de propagar erro ao chamador.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function createInvoice(Appointment $appointment, array $items): Invoice
    {
        $totals = $this->totalsCalculator->recalculate($items, 0.0, 0.0);
        $appointment->loadMissing('pet', 'professional');
        $issueDate = Carbon::today();

        try {
            return Invoice::create([
                ...$totals,
                'professional_id' => $appointment->professional_id,
                'organization_id' => $appointment->professional?->activeOrganizationId(),
                // Invariante 7 do contrato: pagador é sempre o dono do pet, nunca aceito de payload.
                'client_id' => $appointment->pet->user_id,
                'appointment_id' => $appointment->id,
                'invoice_number' => 'INV-'.strtoupper(Str::random(8)),
                'issue_date' => $issueDate,
                'due_date' => $issueDate,
                'status' => InvoiceStatus::PENDING->value,
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            return Invoice::where('appointment_id', $appointment->id)->firstOrFail();
        }
    }
}
