<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decompõe `prescriptions.medications` (JSON legado, `{name, dosage, frequency, duration,
 * instructions}`) em linhas de `prescription_items` — contrato
 * docs/atendimento-veterinario/03-contrato-receituario.md §2 "Migração do JSON legado".
 *
 * Mapeamento, sem inventar nada que o vet não digitou:
 *   - `name`         → `commercial_name`
 *   - `dosage`       → tenta `dose_value`+`dose_unit` (regex "<número><unidade>" simples,
 *                       ex. "250mg", "2 comprimidos"); quando não casa, o texto original vai
 *                       para `instructions_for_tutor` em vez de ser descartado ou forçado
 *                       numa forma que não é.
 *   - `frequency`    → `frequency_notes` (texto livre), com `frequency = 'other'` — nunca
 *                       mapeado para um slug do enum, porque o texto legado não segue o
 *                       vocabulário SID/BID/TID/QID do contrato §4.
 *   - `duration`     → `duration_text`
 *   - `instructions` → `instructions_for_tutor`
 *   - `is_controlled` (nível da prescrição, coluna que já existia) é copiado para cada item
 *     dela — o sinal já existia, isto só o propaga para a granularidade nova, sem inventar
 *     informação.
 *   - `route`/`pharmaceutical_form`/`dose_per_kg` ficam NULL sempre: o formato antigo nunca
 *     capturou via de administração nem dose por peso, e o contrato proíbe explicitamente
 *     inventar esses dois campos na migração.
 *
 * Deliberadamente NÃO depende de `App\Models\Prescription`/`PrescriptionItem` — mesma regra
 * já seguida por `repair_double_encoded_prescription_medications`: migration não depende de
 * classe de `app/`, que pode mudar de forma incompatível com o dado histórico.
 *
 * `down()` recria a coluna `medications` vazia, mas NÃO tenta reconstruir o JSON a partir de
 * `prescription_items` — a mesma decisão já tomada nas migrations de reparo desta tabela
 * (`down()` vazio de propósito): reconstruir com fidelidade duvidosa é pior do que admitir
 * que o rollback desta migration é, na prática, de mão única.
 */
return new class extends Migration
{
    private const CHUNK_SIZE = 200;

    /** "250mg", "2 comprimidos", "10 ml" — número seguido de unidade curta em texto livre. */
    private const DOSAGE_PATTERN = '/^\s*([0-9]+(?:[.,][0-9]+)?)\s*([a-zA-Zµ\x{00C0}-\x{024F}]+)\s*$/u';

    public function up(): void
    {
        DB::table('prescriptions')
            ->orderBy('id')
            ->select(['id', 'medications', 'is_controlled'])
            ->chunkById(self::CHUNK_SIZE, function ($prescriptions): void {
                foreach ($prescriptions as $prescription) {
                    $this->migratePrescription($prescription);
                }
            });

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropColumn('medications');
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->json('medications')->nullable();
        });
    }

    private function migratePrescription(object $prescription): void
    {
        $medications = $this->decodeMedications($prescription->medications);
        $now = now();

        if ($medications === []) {
            $medications = [['name' => null, 'dosage' => null, 'frequency' => null, 'duration' => null, 'instructions' => null]];
        }

        $rows = [];
        foreach (array_values($medications) as $index => $medication) {
            if (! is_array($medication)) {
                continue;
            }

            $rows[] = $this->buildItemRow($prescription, $medication, $index + 1, $now);
        }

        if ($rows !== []) {
            DB::table('prescription_items')->insert($rows);
        }
    }

    /**
     * @param  array<string, mixed>  $medication
     * @return array<string, mixed>
     */
    private function buildItemRow(object $prescription, array $medication, int $position, \Illuminate\Support\Carbon $now): array
    {
        [$doseValue, $doseUnit, $unparsedDosageNote] = $this->parseDosage($medication['dosage'] ?? null);

        return [
            'prescription_id' => $prescription->id,
            'position' => $position,
            'commercial_name' => $this->nullableText($medication['name'] ?? null),
            'dose_value' => $doseValue,
            'dose_unit' => $doseUnit,
            'frequency' => filled($medication['frequency'] ?? null) ? 'other' : null,
            'frequency_notes' => $this->nullableText($medication['frequency'] ?? null),
            'duration_text' => $this->nullableText($medication['duration'] ?? null),
            'instructions_for_tutor' => $this->combinedInstructions($medication, $unparsedDosageNote),
            'is_controlled' => (bool) $prescription->is_controlled,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [dose_value, dose_unit, nota quando não parseou]
     */
    private function parseDosage(mixed $dosage): array
    {
        $text = $this->nullableText($dosage);
        if ($text === null) {
            return [null, null, null];
        }

        if (preg_match(self::DOSAGE_PATTERN, $text, $matches) === 1) {
            $value = str_replace(',', '.', $matches[1]);

            return [$value, mb_strtolower($matches[2]), null];
        }

        // Não parseável em valor+unidade: preserva o texto original em vez de inventar
        // uma quebra que o contrato proíbe.
        return [null, null, "Dosagem registrada originalmente: {$text}."];
    }

    private function combinedInstructions(array $medication, ?string $unparsedDosageNote): ?string
    {
        $parts = array_filter([
            $unparsedDosageNote,
            $this->nullableText($medication['instructions'] ?? null),
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Mesma defesa em 3 camadas já usada por `PrescriptionResource`/`PrescriptionPdfService`:
     * uma linha duplo-encodada (bug já corrigido em produção, mas o dado histórico pode ter
     * sobrevivido num ambiente que nunca rodou o reparo) devolve string em vez de array.
     *
     * @return array<int, mixed>
     */
    private function decodeMedications(mixed $value): array
    {
        $depth = 0;
        while (is_string($value) && $depth < 3) {
            $value = json_decode($value, true);
            $depth++;
        }

        if (! is_array($value)) {
            return [];
        }

        // Linha legada gravada como objeto único em vez de lista.
        return array_is_list($value) ? $value : [$value];
    }
};
