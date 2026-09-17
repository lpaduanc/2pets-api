<?php

namespace Tests\Unit;

use App\Enums\InvoiceStatus;
use PHPUnit\Framework\TestCase;

/**
 * Máquina de estados de `Invoice.status` — contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §2.1. Puro enum, sem
 * banco: roda como Unit, não Feature.
 */
class InvoiceStatusTest extends TestCase
{
    public function test_draft_can_move_to_pending_or_cancelled_only(): void
    {
        $this->assertTrue(InvoiceStatus::DRAFT->canTransitionTo(InvoiceStatus::PENDING));
        $this->assertTrue(InvoiceStatus::DRAFT->canTransitionTo(InvoiceStatus::CANCELLED));
        $this->assertFalse(InvoiceStatus::DRAFT->canTransitionTo(InvoiceStatus::PAID));
        $this->assertFalse(InvoiceStatus::DRAFT->canTransitionTo(InvoiceStatus::REFUNDED));
    }

    public function test_pending_can_move_to_paid_or_cancelled_only(): void
    {
        $this->assertTrue(InvoiceStatus::PENDING->canTransitionTo(InvoiceStatus::PAID));
        $this->assertTrue(InvoiceStatus::PENDING->canTransitionTo(InvoiceStatus::CANCELLED));
        $this->assertFalse(InvoiceStatus::PENDING->canTransitionTo(InvoiceStatus::DRAFT));
        $this->assertFalse(InvoiceStatus::PENDING->canTransitionTo(InvoiceStatus::REFUNDED));
    }

    /** Corrige o bug real de `PaymentService::refundPayment()` — seção 11/12.2 do contrato. */
    public function test_paid_can_only_move_to_refunded(): void
    {
        $this->assertTrue(InvoiceStatus::PAID->canTransitionTo(InvoiceStatus::REFUNDED));
        $this->assertFalse(InvoiceStatus::PAID->canTransitionTo(InvoiceStatus::CANCELLED));
        $this->assertFalse(InvoiceStatus::PAID->canTransitionTo(InvoiceStatus::PENDING));
    }

    public function test_terminal_statuses_have_no_outgoing_transition(): void
    {
        $this->assertSame([], InvoiceStatus::CANCELLED->allowedTransitions());
        $this->assertSame([], InvoiceStatus::REFUNDED->allowedTransitions());
        $this->assertSame([], InvoiceStatus::OVERDUE->allowedTransitions());
    }

    /** Reenviar o mesmo status (mark-as-paid idempotente por webhook) nunca é inválido. */
    public function test_self_transition_is_always_allowed(): void
    {
        foreach (InvoiceStatus::cases() as $status) {
            $this->assertTrue($status->canTransitionTo($status));
        }
    }

    public function test_values_matches_the_check_constraint_list(): void
    {
        $this->assertSame(
            ['draft', 'pending', 'paid', 'overdue', 'cancelled', 'refunded'],
            InvoiceStatus::values(),
        );
    }
}
