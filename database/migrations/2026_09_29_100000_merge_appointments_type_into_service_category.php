<?php

use App\Enums\ServiceCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fusão de `appointments.type` com `App\Enums\ServiceCategory` — contrato
 * docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md §3.
 *
 * Duas taxonomias paralelas (`appointments.type` com 7 valores livres x `ServiceCategory`
 * com 15) bloqueavam o fluxo de faturamento para qualquer categoria fora das 7 originais —
 * um agendamento `exam` de "Eletrocardiograma (ECG)" batia 422 ao finalizar porque
 * `MedicalRecordEncounterResolver` só conhecia `grooming` como exceção e exigia
 * diagnóstico de quem só executou um ECG.
 *
 * Contagem real em dev, confirmada nesta sessão antes de escrever esta migration (o
 * pedido citava 12 linhas; o banco já tinha crescido para 16 com os testes ao vivo):
 * 9 `consultation`, 5 `grooming`, 1 `vaccination`, 1 `exam` — nenhum `checkup`.
 *
 * `checkup` sai por ser redundante com `chief_complaint = routine_checkup`
 * (`01-contrato-api-e-taxonomia.md` §5.4), não por estar errado. `exam` genérico é curado
 * pela categoria do serviço realmente anexado (`appointment_services`) — sem regra
 * automática segura na direção contrária (o dado antigo não diz se foi laboratório ou
 * imagem), então quando não dá para inferir, a linha cai no mesmo default conservador de
 * `2026_09_22_100000_align_services_category_check_with_enum.php` (`laboratory`) e fica
 * registrada em log para revisão manual — curadoria, não script cego.
 *
 * `sqlite` (suíte de teste) não suporta `CHECK` via `ALTER TABLE` — mesmo guard das
 * migrations irmãs (`2026_09_22_100000`, `2026_09_27_100002`).
 */
return new class extends Migration
{
    private const CONSTRAINT = 'appointments_type_check';

    private const LEGACY_CHECKUP = 'checkup';

    private const LEGACY_EXAM = 'exam';

    /** @var list<string> */
    private const LEGACY_TYPES = ['consultation', 'surgery', 'vaccination', 'exam', 'emergency', 'grooming', 'checkup'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);

        $this->backfillCheckup();
        $this->backfillExam();

        DB::statement($this->checkStatement(ServiceCategory::values()));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);

        // Defensivo, igual às migrations irmãs: nenhuma categoria nova pode sobreviver a um
        // rollback que reduz o CHECK de volta à lista antiga, senão a própria migration quebraria.
        $placeholders = implode(', ', array_fill(0, count(self::LEGACY_TYPES), '?'));
        DB::update(
            "UPDATE appointments SET type = ? WHERE type NOT IN ({$placeholders})",
            [self::LEGACY_EXAM, ...self::LEGACY_TYPES]
        );

        DB::statement($this->checkStatement(self::LEGACY_TYPES));
    }

    /**
     * `checkup` vira `consultation`; o prontuário vinculado (se existir e ainda não tiver
     * queixa principal) herda `routine_checkup` — o dado que `checkup` carregava não se perde,
     * só para de viver em dois campos ao mesmo tempo.
     */
    private function backfillCheckup(): void
    {
        $appointmentIds = DB::table('appointments')->where('type', self::LEGACY_CHECKUP)->pluck('id');

        if ($appointmentIds->isEmpty()) {
            return;
        }

        DB::table('appointments')->whereIn('id', $appointmentIds)
            ->update(['type' => ServiceCategory::CONSULTATION->value]);

        DB::table('medical_records')->whereIn('appointment_id', $appointmentIds)
            ->where(fn ($query) => $query->whereNull('chief_complaint')->orWhere('chief_complaint', ''))
            ->update(['chief_complaint' => 'routine_checkup']);
    }

    /**
     * `exam` não existe em `ServiceCategory` — cada linha é revisada individualmente pela
     * categoria do serviço anexado, nunca por um único `UPDATE` cego.
     */
    private function backfillExam(): void
    {
        foreach (DB::table('appointments')->where('type', self::LEGACY_EXAM)->get(['id']) as $appointment) {
            DB::table('appointments')->where('id', $appointment->id)
                ->update(['type' => $this->resolveExamCategory($appointment->id)]);
        }
    }

    /**
     * Ordem de evidência: 1) serviço `laboratory`/`imaging` já anexado ao agendamento
     * (mais preciso — é o que o profissional realmente vendeu); 2) `exams.exam_type` de um
     * `Exam` que já esteja ligado a este `appointment_id` (dado solto de antes desta
     * correção existir); 3) default conservador com log para revisão manual.
     */
    private function resolveExamCategory(int $appointmentId): string
    {
        return $this->categoryFromAttachedService($appointmentId)
            ?? $this->categoryFromLinkedExam($appointmentId)
            ?? $this->conservativeDefault($appointmentId);
    }

    private function categoryFromAttachedService(int $appointmentId): ?string
    {
        return DB::table('appointment_services')
            ->join('services', 'services.id', '=', 'appointment_services.service_id')
            ->where('appointment_services.appointment_id', $appointmentId)
            ->whereIn('services.category', [ServiceCategory::LABORATORY->value, ServiceCategory::IMAGING->value])
            ->value('services.category');
    }

    private function categoryFromLinkedExam(int $appointmentId): ?string
    {
        $examType = DB::table('exams')->where('appointment_id', $appointmentId)->value('exam_type');

        return in_array($examType, [ServiceCategory::LABORATORY->value, ServiceCategory::IMAGING->value], true)
            ? $examType
            : null;
    }

    private function conservativeDefault(int $appointmentId): string
    {
        Log::warning(
            'Backfill appointments.type=exam: nenhum serviço/Exam laboratory|imaging associado — '.
            'assumindo laboratory por padrão conservador (mesma decisão de 2026_09_22_100000). '.
            'Revisar manualmente.',
            ['appointment_id' => $appointmentId]
        );

        return ServiceCategory::LABORATORY->value;
    }

    /**
     * Valores vêm do enum, nunca de uma lista redigitada à mão — mesmo padrão de
     * `2026_09_22_100000_align_services_category_check_with_enum.php` e
     * `2026_09_27_100002_expand_invoices_status_check.php`. CHECK não aceita binding, então
     * cada valor é escapado por `DB::getPdo()->quote()`.
     *
     * @param  list<string>  $types
     */
    private function checkStatement(array $types): string
    {
        $quoted = implode(', ', array_map(
            static fn (string $type): string => DB::getPdo()->quote($type),
            $types,
        ));

        return 'ALTER TABLE appointments ADD CONSTRAINT '.self::CONSTRAINT
            ." CHECK (type::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
