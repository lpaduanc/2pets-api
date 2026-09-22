<?php

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\LockedClinicalStock;
use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Pet;
use App\Models\PetDeworming;
use App\Models\PetMedication;
use App\Models\Surgery;
use App\Models\User;
use App\Models\Vaccination;
use App\Notifications\PetUpdatedByVet;
use App\Services\Inventory\ClinicalStockDeductionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Health records nested under a pet.
 *
 * Routes (auth required):
 *   GET    /pets/{pet}/health/{type}                              → owner or active vet grant (read)
 *   POST   /pets/{pet}/health/{type}                              → owner or vet with write|full grant
 *   PUT    /pets/{pet}/health/{type}/{record}                     → owner or vet with write|full grant
 *   DELETE /pets/{pet}/health/{type}/{record}                     → owner only (vets cannot hard-delete clinical history)
 *   PATCH  /pets/{pet}/health/medications/{record}/deactivate     → owner or vet with write|full grant
 *
 * Supported types: vaccinations, dewormings, medications, surgeries, exams.
 * Chronic conditions and food allergies live as JSON arrays on the pet row
 * (handled via PetController::update), not here.
 *
 * Accountability model: vets may add/edit freely but the tutor (pet owner) is
 * always notified (mail + database channel). Vets may never delete — owner
 * soft-deletes ("arquivar") which preserves the record for audit and lets us
 * restore if needed. See PetUpdatedByVet notification and GET /pets/{id}/audit.
 */
class PetHealthRecordsController extends Controller
{
    use AuthorizesPetAccess;

    public function __construct(
        private readonly ClinicalStockDeductionService $stockDeductionService,
    ) {}

    private static function types(): array
    {
        return [
            'vaccinations' => [
                'model' => Vaccination::class,
                'fields' => [
                    'vaccine_name' => ['required', 'string', 'max:120'],
                    'application_date' => ['required', 'date'],
                    'next_dose_date' => ['nullable', 'date', 'after_or_equal:application_date'],
                    'manufacturer' => ['nullable', 'string', 'max:120'],
                    'batch_number' => ['nullable', 'string', 'max:60'],
                    'expiry_date' => ['nullable', 'date'],
                    'dose_number' => ['nullable', 'integer', 'min:1'],
                    'notes' => ['nullable', 'string', 'max:1000'],
                ],
                'order' => ['application_date', 'desc'],
            ],
            'dewormings' => [
                'model' => PetDeworming::class,
                'fields' => [
                    'product_name' => ['required', 'string', 'max:120'],
                    'applied_date' => ['required', 'date'],
                    'next_date' => ['nullable', 'date', 'after_or_equal:applied_date'],
                    'weight_at_application' => ['nullable', 'numeric', 'min:0'],
                    'notes' => ['nullable', 'string', 'max:1000'],
                ],
                'order' => ['applied_date', 'desc'],
            ],
            'medications' => [
                'model' => PetMedication::class,
                'fields' => [
                    'name' => ['required', 'string', 'max:120'],
                    'dosage' => ['nullable', 'string', 'max:120'],
                    'frequency' => ['nullable', 'string', 'max:120'],
                    'start_date' => ['nullable', 'date'],
                    'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
                    'active' => ['nullable', 'boolean'],
                    'notes' => ['nullable', 'string', 'max:1000'],
                ],
                'order' => ['start_date', 'desc'],
            ],
            'surgeries' => [
                'model' => Surgery::class,
                'fields' => [
                    'surgery_type' => ['required', 'string', 'max:120'],
                    'surgery_date' => ['required', 'date'],
                    'procedure_description' => ['nullable', 'string', 'max:2000'],
                    'pre_op_notes' => ['nullable', 'string', 'max:2000'],
                    'post_op_notes' => ['nullable', 'string', 'max:2000'],
                    'anesthesia_used' => ['nullable', 'string', 'max:120'],
                    'complications' => ['nullable', 'string', 'max:2000'],
                    'status' => ['nullable', Rule::in(['scheduled', 'completed', 'cancelled'])],
                ],
                'order' => ['surgery_date', 'desc'],
            ],
            'exams' => [
                'model' => Exam::class,
                'fields' => [
                    'exam_type' => ['required', 'string', 'max:120'],
                    'exam_name' => ['required', 'string', 'max:120'],
                    'exam_date' => ['required', 'date'],
                    'notes' => ['nullable', 'string', 'max:2000'],
                    'status' => ['nullable', Rule::in(['requested', 'in_progress', 'completed', 'cancelled'])],
                ],
                'order' => ['exam_date', 'desc'],
            ],
        ];
    }

    public function index(Request $request, int $petId, string $type)
    {
        $pet = $this->resolvePetForRead($request, $petId);
        $config = $this->config($type);

        [$orderCol, $orderDir] = $config['order'];
        $query = $pet->{$this->relation($type)}()
            ->orderBy($orderCol, $orderDir)
            ->limit(200);

        // Eager-load attachments only for exams. Other health types have no attachments,
        // so we avoid the over-fetch.
        if ($type === 'exams') {
            $query->with('images');
        }

        $records = $query->get();

        return response()->json(['data' => $records]);
    }

    public function store(Request $request, int $petId, string $type)
    {
        $pet = $this->resolvePetForWrite($request, $petId);
        $config = $this->config($type);

        $rules = $this->rulesFor($config, $type);
        $data = Validator::make($request->all(), $rules)->validate();
        $changeReason = $data['change_reason'] ?? null;
        unset($data['change_reason']);

        $data['pet_id'] = $pet->id;

        if (in_array($type, ['vaccinations', 'surgeries'], true)) {
            $data['professional_id'] = $this->resolveProfessionalId($request, $pet);
        }

        $record = $this->createRecord($type, $config, $data, $request->user());

        // Attach the change reason to the creation activity (which Spatie's trait
        // logged automatically on the `created` event).
        $this->attachChangeReasonToLatestActivity($record, $changeReason);

        $this->notifyOwnerIfVet($request, $pet, 'health_added', [
            'resource_type' => $type,
            'record_id' => $record->id,
            'change_reason' => $changeReason,
        ]);

        return response()->json([
            'data' => $record->fresh(),
            'message' => 'Registro adicionado com sucesso.',
        ], 201);
    }

    public function update(Request $request, int $petId, string $type, int $recordId)
    {
        $pet = $this->resolvePetForWrite($request, $petId);
        $config = $this->config($type);

        $record = $config['model']::where('pet_id', $pet->id)->findOrFail($recordId);

        $rules = $this->relaxRequired($config['fields']);
        $rules['change_reason'] = ['nullable', 'string', 'max:1000'];
        $data = Validator::make($request->all(), $rules)->validate();
        $changeReason = $data['change_reason'] ?? null;
        unset($data['change_reason']);

        $record->update($data);

        $this->attachChangeReasonToLatestActivity($record, $changeReason);

        $this->notifyOwnerIfVet($request, $pet, 'health_edited', [
            'resource_type' => $type,
            'record_id' => $record->id,
            'change_reason' => $changeReason,
        ]);

        return response()->json([
            'data' => $record->fresh(),
            'message' => 'Registro atualizado com sucesso.',
        ]);
    }

    /**
     * Delete behavior:
     *   - Owner (tutor): soft-delete (arquivar) — keeps history for audit.
     *   - Vet (any access level): forbidden. Clinical history must survive the
     *     professional relationship. The vet's recourse is to ask the tutor to
     *     archive, or to edit/deactivate the record.
     */
    public function destroy(Request $request, int $petId, string $type, int $recordId)
    {
        $pet = Pet::findOrFail($petId);
        $user = $request->user();
        $config = $this->config($type);

        if (! $this->isPetOwner($user, $pet)) {
            abort(
                403,
                'Registros clínicos não podem ser excluídos por profissionais. Peça ao tutor arquivar o registro.'
            );
        }

        $record = $config['model']::where('pet_id', $pet->id)->findOrFail($recordId);
        $record->delete(); // soft-delete thanks to SoftDeletes trait on the model

        return response()->json(['message' => 'Registro removido com sucesso.']);
    }

    /**
     * PATCH /pets/{pet}/health/medications/{recordId}/deactivate
     *
     * Marks a medication as `active=false` and pins the `end_date` to today (or a
     * caller-specified date). Distinct from DELETE — the prescription stays in
     * the history, it just stops counting as "in use".
     */
    public function deactivateMedication(Request $request, int $petId, int $recordId)
    {
        $pet = $this->resolvePetForWrite($request, $petId);

        $data = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:1000'],
            'end_date' => ['nullable', 'date'],
        ])->validate();

        $medication = PetMedication::where('pet_id', $pet->id)->findOrFail($recordId);

        if ($medication->active === false) {
            return response()->json([
                'message' => 'Esta medicação já está inativa.',
            ], 422);
        }

        $endDate = $data['end_date'] ?? now()->toDateString();

        $medication->update([
            'active' => false,
            'end_date' => $endDate,
            'deactivation_reason' => $data['reason'] ?? null,
        ]);

        // Annotate the auto-logged `updated` activity with a custom `event` so the
        // audit timeline renders "desativou" instead of a generic "atualizou".
        $this->attachDeactivationContext($medication, $data['reason'] ?? null);

        $this->notifyOwnerIfVet($request, $pet, 'medication_deactivated', [
            'resource_type' => 'medications',
            'record_id' => $medication->id,
            'reason' => $data['reason'] ?? null,
            'end_date' => $endDate,
        ]);

        return response()->json([
            'data' => $medication->fresh(),
            'message' => 'Medicação desativada com sucesso.',
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Optional stock deduction — vaccinations/dewormings only.
    // Ver docs/vinculo-estoque-aplicacao-clinica.md.
    // ────────────────────────────────────────────────────────────────────

    /**
     * `vaccinations` e `dewormings` ganham `product_id`/`product_batch_id` e `confirm_expired`
     * — os únicos dois tipos de ato clínico atômico com baixa de estoque opcional (item 7 do
     * parecer: medicação é regime contínuo, não entra aqui).
     */
    private function rulesFor(array $config, string $type): array
    {
        $rules = $config['fields'];
        $rules['change_reason'] = ['nullable', 'string', 'max:1000'];

        if ($this->isStockLinkable($type)) {
            $rules['product_id'] = ['nullable', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')];
            $rules['product_batch_id'] = ['nullable', 'integer', Rule::exists('product_batches', 'id')];
            $rules['confirm_expired'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    private function isStockLinkable(string $type): bool
    {
        return in_array($type, ['vaccinations', 'dewormings'], true);
    }

    /**
     * Sem `product_id`: cria o registro normalmente, sem tocar em estoque — esse continua sendo
     * o caso normal (tutor auto-relato, campanha, vet volante sem controle de estoque na
     * plataforma). Ver item 2 do parecer.
     */
    private function createRecord(string $type, array $config, array $data, User $user): Model
    {
        if (! $this->isStockLinkable($type) || empty($data['product_id'])) {
            unset($data['confirm_expired'], $data['product_batch_id']);

            return $config['model']::create($data);
        }

        return $this->createRecordWithStockDeduction($type, $config, $data, $user);
    }

    /**
     * Lock + valida o produto (e o lote, quando informado) ANTES de criar o registro clínico:
     * se saldo insuficiente ou lote vencido sem confirmação, a transação inteira reverte e nada
     * é gravado — nem a vacinação/vermifugação nem o movimento (critério de aceite do item 1 do
     * parecer). As exceções de domínio (`InsufficientStockException`/`ExpiredBatchException`)
     * se autorrenderizam em 422 com `code` distinto — não precisam de catch aqui.
     */
    private function createRecordWithStockDeduction(string $type, array $config, array $data, User $user): Model
    {
        $applicationDate = Carbon::parse($data[$this->applicationDateField($type)]);
        $confirmExpired = (bool) ($data['confirm_expired'] ?? false);
        $productId = (int) $data['product_id'];
        $productBatchId = isset($data['product_batch_id']) ? (int) $data['product_batch_id'] : null;

        return DB::transaction(function () use ($type, $config, $data, $user, $applicationDate, $confirmExpired, $productId, $productBatchId): Model {
            $lock = $this->stockDeductionService->lockAndValidate($productId, $productBatchId, $user, $applicationDate, $confirmExpired);

            $record = $config['model']::create($this->recordDataFor($type, $data, $lock));

            $this->stockDeductionService->recordDeduction($lock, $record, $user->id, $confirmExpired);

            return $record;
        });
    }

    /**
     * `expiry_date` (só existe em `vaccinations`) herda do produto/lote quando o profissional
     * não informou — sugestão, não trava (item 3 do parecer).
     */
    private function recordDataFor(string $type, array $data, LockedClinicalStock $lock): array
    {
        unset($data['confirm_expired']);
        $data['product_batch_id'] = $lock->batch?->id;

        if ($type === 'vaccinations' && empty($data['expiry_date'])) {
            $data['expiry_date'] = $lock->expiryDate();
        }

        return $data;
    }

    private function applicationDateField(string $type): string
    {
        return match ($type) {
            'vaccinations' => 'application_date',
            'dewormings' => 'applied_date',
            default => abort(422, 'Tipo não suporta vínculo de estoque.'),
        };
    }

    // ────────────────────────────────────────────────────────────────────
    // Internals
    // ────────────────────────────────────────────────────────────────────

    private function config(string $type): array
    {
        $types = self::types();
        abort_unless(isset($types[$type]), 404, 'Tipo de registro de saúde inválido.');

        return $types[$type];
    }

    private function relation(string $type): string
    {
        return match ($type) {
            'vaccinations' => 'vaccinations',
            'dewormings' => 'dewormings',
            'medications' => 'medications',
            'surgeries' => 'surgeryRecords',
            'exams' => 'examRecords',
            default => abort(404),
        };
    }

    /**
     * On update, make required fields optional so partial updates work.
     * We still validate shape/type when present.
     */
    private function relaxRequired(array $rules): array
    {
        foreach ($rules as $field => $chain) {
            $rules[$field] = array_map(
                fn ($r) => $r === 'required' ? 'sometimes' : $r,
                $chain
            );
        }

        return $rules;
    }

    /**
     * `vaccinations` and `surgeries` still carry a `professional_id` column (a
     * leftover from the schema before dewormings/medications/exams made the vet
     * link nullable — see the 2026_09_06_000204 migration). Fill it only when the
     * author is actually a professional writing under a grant; a tutor-authored
     * record must never claim a professional applied it.
     *
     * Reuses the same "is the requester the owner" check as notifyOwnerIfVet so
     * both stay consistent with a single source of truth.
     */
    private function resolveProfessionalId(Request $request, Pet $pet): ?int
    {
        $user = $request->user();
        if ($user === null || $this->isPetOwner($user, $pet)) {
            return null;
        }

        return $user->id;
    }

    /**
     * Fire PetUpdatedByVet when the requester is not the pet's owner. Tutor edits
     * don't need to notify anyone.
     */
    private function notifyOwnerIfVet(Request $request, Pet $pet, string $eventType, array $payload = []): void
    {
        $user = $request->user();
        if (! $user || $this->isPetOwner($user, $pet)) {
            return;
        }

        try {
            $pet->loadMissing('user');
            $owner = $pet->user;
            if ($owner) {
                $owner->notify(new PetUpdatedByVet($pet, $user, $eventType, $payload));
            }
        } catch (\Throwable $e) {
            // Notification dispatch is best-effort — never block the write itself.
            Log::error('Failed to dispatch PetUpdatedByVet', [
                'pet_id' => $pet->id,
                'vet_id' => $user?->id,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Glue the caller-supplied `change_reason` onto the activity row Spatie just
     * created for this model's save. Done as an UPDATE on the latest log so
     * we don't emit a duplicate entry.
     */
    private function attachChangeReasonToLatestActivity($record, ?string $reason): void
    {
        if ($reason === null || $reason === '') {
            return;
        }

        $activity = $record->activities()->latest('id')->first();
        if (! $activity) {
            return;
        }

        $props = $activity->properties?->toArray() ?? [];
        $props['change_reason'] = $reason;
        $activity->properties = $props;
        $activity->save();
    }

    /**
     * Convert the auto-logged `updated` activity of a medication into a semantic
     * `deactivated` event so the audit UI can render it distinctly.
     */
    private function attachDeactivationContext(PetMedication $medication, ?string $reason): void
    {
        $activity = $medication->activities()->latest('id')->first();
        if (! $activity) {
            return;
        }

        $props = $activity->properties?->toArray() ?? [];
        if ($reason !== null && $reason !== '') {
            $props['change_reason'] = $reason;
        }
        $props['semantic_event'] = 'deactivated';
        $activity->properties = $props;
        $activity->event = 'deactivated';
        $activity->save();
    }
}
