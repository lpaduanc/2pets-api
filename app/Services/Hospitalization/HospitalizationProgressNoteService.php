<?php

namespace App\Services\Hospitalization;

use App\Models\Hospitalization;
use App\Models\HospitalizationProgressNote;
use App\Models\User;
use App\Services\Pet\PetWeightHistoryWriter;
use Illuminate\Support\Facades\DB;

/**
 * Contrato docs/atendimento-veterinario/12-modulo-clinico-internacao.md §1.
 *
 * Autorização (`HospitalizationPolicy::writeProgressNote`) já foi checada pelo controller
 * antes de chegar aqui — este service cuida só da regra de negócio: a estadia precisa estar
 * `active` (§7), e o peso informado (se houver) alimenta `PetWeightHistory` pelo mesmo
 * mecanismo já usado ao finalizar um `MedicalRecord`.
 */
final class HospitalizationProgressNoteService
{
    public function __construct(private readonly PetWeightHistoryWriter $weightHistoryWriter) {}

    /**
     * @param  array{body: string, recorded_at: ?string, temperature: ?float, heart_rate: ?int, respiratory_rate: ?int, weight: ?float, corrects_id: ?int}  $data
     */
    public function create(Hospitalization $hospitalization, User $author, array $data): HospitalizationProgressNote
    {
        $this->assertActive($hospitalization);

        return DB::transaction(function () use ($hospitalization, $author, $data): HospitalizationProgressNote {
            $note = HospitalizationProgressNote::create([
                'hospitalization_id' => $hospitalization->id,
                'author_id' => $author->id,
                'recorded_at' => $data['recorded_at'] ?? now(),
                'body' => $data['body'],
                'temperature' => $data['temperature'] ?? null,
                'heart_rate' => $data['heart_rate'] ?? null,
                'respiratory_rate' => $data['respiratory_rate'] ?? null,
                'weight' => $data['weight'] ?? null,
                'corrects_id' => $data['corrects_id'] ?? null,
            ]);

            $this->weightHistoryWriter->recordIfPresent(
                $hospitalization->pet_id,
                $author,
                $note->weight,
                $note->recorded_at,
            );

            return $note;
        });
    }

    private function assertActive(Hospitalization $hospitalization): void
    {
        if (! $hospitalization->isActive()) {
            abort(422, 'Só é possível registrar evolução numa internação ativa.');
        }
    }
}
