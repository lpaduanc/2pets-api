<?php

namespace App\Enums;

/**
 * Estado do ciclo de vida de uma fatura (`invoices.status`). Contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §2.1/§12.2.
 *
 * `invoices.status` é `varchar` + CHECK (não enum nativo do Postgres) — a migration que
 * expande a lista é `ALTER TABLE ... DROP/ADD CONSTRAINT`, não `ALTER TYPE`. Mesmo padrão de
 * `AppointmentStatus`: não é usado como `casts()` do model, porque `PaymentController` e
 * relatórios (`RevenueReportService`, `ProfessionalDashboardStatsService`) já comparam o
 * valor como string crua — trocar para enum quebraria essas comparações. Este enum é a fonte
 * única da MÁQUINA DE ESTADOS para quem precisa validar transição.
 *
 * `overdue` nunca é escrito por código novo (§12.3): é derivado em leitura
 * (`Invoice::isOverdue()`/`InvoiceResource.is_overdue`). O valor continua na lista só por
 * compatibilidade com linha antiga.
 */
enum InvoiceStatus: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case PAID = 'paid';
    case OVERDUE = 'overdue';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::PENDING => 'Pendente',
            self::PAID => 'Paga',
            self::OVERDUE => 'Vencida',
            self::CANCELLED => 'Cancelada',
            self::REFUNDED => 'Reembolsada',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::PENDING, self::CANCELLED],
            self::PENDING => [self::PAID, self::CANCELLED],
            self::PAID => [self::REFUNDED],
            self::OVERDUE, self::CANCELLED, self::REFUNDED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return $this === $target || in_array($target, $this->allowedTransitions(), true);
    }

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
