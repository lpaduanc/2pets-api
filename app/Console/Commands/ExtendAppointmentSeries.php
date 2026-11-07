<?php

namespace App\Console\Commands;

use App\Models\AppointmentSeries;
use App\Services\Appointment\Recurrence\AppointmentSeriesService;
use Illuminate\Console\Command;

/**
 * Estende a janela materializada das séries de agendamento até hoje + 90 dias (data de negócio,
 * PLANO 3.1). Idempotente: ocorrência já criada (mesmo apagada), pulada ou depois do fim da série
 * não nasce de novo.
 */
class ExtendAppointmentSeries extends Command
{
    protected $signature = 'appointments:extend-series';

    protected $description = 'Materializa as ocorrências das séries de agendamento até 90 dias à frente.';

    public function handle(AppointmentSeriesService $series): int
    {
        $created = 0;
        AppointmentSeries::query()->where(fn ($q) => $q->whereNull('last_index')->orWhere('last_index', '>', 0))->orderBy('id')
            ->each(function (AppointmentSeries $item) use ($series, &$created): void {
                $created += $series->materialize($item);
            });

        $this->info("Ocorrências criadas: {$created}.");

        return self::SUCCESS;
    }
}
