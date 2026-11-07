<?php

namespace App\Enums;

/**
 * `pet_transports.status` (PLANO 3.7): pendente → a caminho → coletado → entregue, mais falha
 * e cancelado. "Só levar" percorre os mesmos quatro passos (o contrato do front é um só).
 */
enum PetTransportStatus: string
{
    case PENDING = 'pending';
    case ON_THE_WAY = 'on_the_way';
    case PICKED_UP = 'picked_up';
    case DELIVERED = 'delivered';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::ON_THE_WAY => 'A caminho',
            self::PICKED_UP => 'Coletado',
            self::DELIVERED => 'Entregue',
            self::FAILED => 'Falhou',
            self::CANCELLED => 'Cancelado',
        };
    }

    /** Coluna de horário gravada ao entrar no status (`pending` não tem). */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::PENDING => null,
            self::ON_THE_WAY => 'on_the_way_at',
            self::PICKED_UP => 'picked_up_at',
            self::DELIVERED => 'delivered_at',
            self::FAILED => 'failed_at',
            self::CANCELLED => 'cancelled_at',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::PENDING => [self::ON_THE_WAY, self::CANCELLED, self::FAILED],
            self::ON_THE_WAY => [self::PICKED_UP, self::FAILED, self::CANCELLED],
            self::PICKED_UP => [self::DELIVERED, self::FAILED],
            self::DELIVERED, self::FAILED, self::CANCELLED => [],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
