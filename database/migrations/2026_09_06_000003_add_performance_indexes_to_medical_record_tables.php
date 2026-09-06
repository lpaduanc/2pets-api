<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 do plano de otimizacao — dominio "prontuario" (medical_records, vaccinations,
 * prescriptions, exams, surgeries, hospitalizations, pet_vet_accesses).
 *
 * `medical_records.pet_id`, `vaccinations.pet_id`, `pet_dewormings.pet_id` e
 * `pet_medications.pet_id` ja tem indice lider full (nao-parcial) desde migrations
 * anteriores a esta fase — nao sao tocados aqui.
 *
 * `CONCURRENTLY` nao pode rodar dentro de uma transacao.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->createChildRecordPetIdIndexes();
        $this->createProfessionalIdIndexes();
        $this->createAppointmentLinkedIndexes();
        $this->createPrescriptionMedicalRecordIndex();
        $this->createPetVetAccessCompositeIndex();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_pet_vet_accesses_pet_veterinarian');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_prescriptions_medical_record_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_prescriptions_appointment_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_medical_records_appointment_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_vaccinations_appointment_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_vaccinations_professional_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_hospitalizations_professional_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_surgeries_professional_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_hospitalizations_pet_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_surgeries_pet_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_exams_pet_id');
    }

    /**
     * exams.pet_id, surgeries.pet_id e hospitalizations.pet_id nao tinham indice nenhum.
     * Query nomeada: app/Http/Controllers/Api/PetAuditController.php:150-158
     * (collectChildSubjectIds) — `$class::query()->where('pet_id', $petId)->withTrashed()`
     * para Vaccination, PetDeworming, PetMedication, Surgery, Exam e Hospitalization.
     *
     * IMPORTANTE — NAO parcial: essa query usa withTrashed(), que remove o scope global de
     * SoftDeletes (deleted_at IS NULL). As tres tabelas abaixo TEM deleted_at, entao um
     * indice `WHERE deleted_at IS NULL` NAO seria usado por esse caminho (o predicado da
     * query nao implica o do indice) e o audit trail de pet arquivado voltaria a fazer seq
     * scan. Vaccination/PetDeworming/PetMedication ja tinham indice full (nao-parcial) em
     * pet_id de migrations anteriores — por isso so as tres tabelas abaixo precisam de indice
     * novo aqui, e todas as tres tem que ser full pelo mesmo motivo.
     */
    private function createChildRecordPetIdIndexes(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_exams_pet_id ON exams (pet_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_surgeries_pet_id ON surgeries (pet_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_hospitalizations_pet_id ON hospitalizations (pet_id)');
    }

    /**
     * surgeries.professional_id, hospitalizations.professional_id e vaccinations.professional_id
     * nao tinham indice (medical_records.professional_id e prescriptions.professional_id ja
     * tinham). Queries nomeadas, todas Eloquent padrao (sem withTrashed, entao o scope global
     * de SoftDeletes ja filtra deleted_at IS NULL sozinho — indice parcial e correto e menor):
     *
     * - app/Http/Controllers/Api/SurgeryController.php:15,51,58,81
     * - app/Http/Controllers/Api/HospitalizationController.php:15,56,63,84
     * - app/Http/Controllers/Api/VaccinationController.php:18,63,71,92,101 (vaccinations: 500k
     *   linhas no benchmark — a maior das tres)
     *
     * Todas seguem o mesmo padrao: `Model::where('professional_id', $request->user()->id)`
     * na listagem do proprio veterinario e em cada find/update/delete por id.
     */
    private function createProfessionalIdIndexes(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_surgeries_professional_id
            ON surgeries (professional_id)
            WHERE deleted_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_hospitalizations_professional_id
            ON hospitalizations (professional_id)
            WHERE deleted_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_vaccinations_professional_id
            ON vaccinations (professional_id)
            WHERE deleted_at IS NULL
            SQL);
    }

    /**
     * vaccinations.appointment_id e medical_records.appointment_id nao tinham indice.
     * Query nomeada: app/Http/Controllers/Api/AppointmentController.php:55 —
     * `Appointment::with(['client', 'pet', 'professional', 'medicalRecords', 'prescriptions',
     * 'vaccinations'])->findOrFail($id)`, tela de detalhe do agendamento. Cada relacao gera
     * `SELECT * FROM <tabela> WHERE appointment_id = ?` (hasMany em Appointment.php:63-70).
     * medical_records.appointment_id tem citacao extra em
     * app/Services/Report/MedicalHistoryPdfService.php:16 (`'medicalRecords.professional'`).
     *
     * vaccinations tem deleted_at e a query acima e Eloquent padrao (sem withTrashed) —
     * parcial. medical_records NAO tem deleted_at — indice cheio.
     */
    private function createAppointmentLinkedIndexes(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_vaccinations_appointment_id
            ON vaccinations (appointment_id)
            WHERE deleted_at IS NULL
            SQL);

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_medical_records_appointment_id ON medical_records (appointment_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_prescriptions_appointment_id ON prescriptions (appointment_id)');
    }

    /**
     * prescriptions.medical_record_id nao tinha indice e nao ha WHERE explicito por essa
     * coluna em codigo hoje (grep confirmado — so aparece em $fillable). Justificativa por
     * criterio (b)+(c): FK `ON DELETE SET NULL` para medical_records
     * (database/migrations/2025_11_22_234413_create_prescriptions_table.php:15) numa tabela
     * de 500k linhas (benchmark-seeder.md) — apagar/anonimizar um medical_record sem indice
     * forca varredura sequencial de prescriptions inteira para colocar medical_record_id=NULL.
     */
    private function createPrescriptionMedicalRecordIndex(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_prescriptions_medical_record_id ON prescriptions (medical_record_id)');
    }

    /**
     * pet_vet_accesses(pet_id, veterinarian_id) nao tinha indice full — so o unique parcial
     * `pet_vet_access_live_unique` (WHERE status IN ('pending','accepted')), que nao cobre a
     * query abaixo porque ela filtra por is_active, um predicado diferente.
     *
     * Query nomeada: app/Http/Controllers/Api/PetVetAccessController.php:54-57 e 121-123 —
     * `PetVetAccess::where('pet_id', $pet->id)->where('veterinarian_id', $vet->id)
     * ->where('is_active', true)->first()`, chamada em toda concessao/revogacao de acesso.
     * Tabela de acesso a dado sensivel de pet (LGPD) — path de seguranca quente mesmo com
     * volume baixo.
     */
    private function createPetVetAccessCompositeIndex(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_pet_vet_accesses_pet_veterinarian ON pet_vet_accesses (pet_id, veterinarian_id)');
    }
};
