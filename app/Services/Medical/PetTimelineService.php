<?php

namespace App\Services\Medical;

use App\DataTransferObjects\PetTimelineEntry;
use App\Enums\MedicalRecordStatus;
use App\Enums\QuoteStatus;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetWeightHistory;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vaccination;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Support\Collection;

/**
 * Agregado de linha do tempo do pet — item 11 do MVP (diferencial #1 do
 * docs/atendimento-veterinario/00-dominio-e-escopo.md: histórico atravessa clínicas).
 *
 * Design deliberado para o MVP: UMA query por fonte (atendimento, vacina, peso, orçamento — não há
 * fonte "patologia" própria, ver `PetTimelineController::pathologiesSnapshot`), sem
 * `view` materializada. O merge e a paginação acontecem em memória depois das 3 queries.
 *
 * Isso é seguro no volume esperado de um pet individual (dezenas a poucas centenas de
 * eventos ao longo da vida do animal — não é uma busca global). Se um pet acumular milhares
 * de linhas em alguma das fontes, o corte precisa migrar para uma query SQL única com
 * `UNION ALL` + `ORDER BY`/`LIMIT`/`OFFSET` no banco; o comentário fica aqui para quem for
 * medir isso depois, conforme docs/atendimento-veterinario/00-dominio-e-escopo.md §6 item 11.
 */
final class PetTimelineService
{
    public function __construct(private readonly CommercialScopeResolver $commercialScope) {}

    private const TYPE_MEDICAL_RECORD = 'medical_record';

    private const TYPE_VACCINATION = 'vaccination';

    private const TYPE_WEIGHT = 'weight';

    private const TYPE_QUOTE = 'quote';

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function forPet(Pet $pet, int $page, int $perPage, ?User $viewer = null): array
    {
        $entries = $this->medicalRecordEntries($pet->id)
            ->concat($this->vaccinationEntries($pet->id))
            ->concat($this->weightEntries($pet->id))
            ->concat($this->quoteEntries($pet, $viewer))
            ->sortByDesc(fn (PetTimelineEntry $entry): int => $entry->date->getTimestamp())
            ->values();

        $total = $entries->count();

        $items = $entries->forPage($page, $perPage)
            ->map(fn (PetTimelineEntry $entry): array => $entry->toArray())
            ->values()
            ->all();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * @return Collection<int, PetTimelineEntry>
     */
    private function medicalRecordEntries(int $petId): Collection
    {
        return MedicalRecord::query()
            ->where('pet_id', $petId)
            ->where('status', MedicalRecordStatus::FINALIZED->value)
            ->with('professional:id,name')
            ->get()
            ->map(fn (MedicalRecord $record): PetTimelineEntry => new PetTimelineEntry(
                type: self::TYPE_MEDICAL_RECORD,
                date: $record->record_date,
                id: $record->id,
                title: $record->chief_complaint ?? 'Atendimento',
                summary: $record->summary_for_tutor ?: $record->diagnosis,
                professionalName: $record->professional?->name,
            ));
    }

    /**
     * @return Collection<int, PetTimelineEntry>
     */
    private function vaccinationEntries(int $petId): Collection
    {
        return Vaccination::query()
            ->where('pet_id', $petId)
            ->get()
            ->map(fn (Vaccination $vaccination): PetTimelineEntry => new PetTimelineEntry(
                type: self::TYPE_VACCINATION,
                date: $vaccination->application_date,
                id: $vaccination->id,
                title: $vaccination->vaccine_name,
                summary: null,
                professionalName: null,
            ));
    }

    /**
     * Orçamentos ENVIADOS ao tutor (docs/gap-simplesvet/24-orcamentos.md: "aparece no
     * histórico/linha do tempo do animal"). Rascunho não entra — ainda é documento interno da
     * clínica —, nem versão substituída, que só polui a linha com o mesmo procedimento duas
     * vezes; a versão vigente carrega a história. Só número, versão, total e status.
     *
     * @return Collection<int, PetTimelineEntry>
     */
    private function quoteEntries(Pet $pet, ?User $viewer): Collection
    {
        // Quem vê: o tutor dono (orçamentos de qualquer clínica) e a clínica que emitiu. O
        // vet com PetVetAccess de OUTRA clínica lê o histórico clínico, mas não quanto a
        // concorrente cobrou — mesma régua de `PetQuotesController`. Sem leitor, nada.
        if ($viewer === null) {
            return collect();
        }

        $query = Sale::query()->quotesOnly()->where('pet_id', $pet->id);

        if ($pet->user_id === $viewer->id) {
            $query->where('client_id', $viewer->id);
        } else {
            $this->commercialScope->scopeQuery($query, $viewer);
        }

        return $query
            ->whereNotNull('sent_at')
            ->whereNotIn('quote_status', [QuoteStatus::DRAFT->value, QuoteStatus::SUPERSEDED->value])
            ->with('createdBy:id,name')
            ->get()
            ->map(fn (Sale $quote): PetTimelineEntry => new PetTimelineEntry(
                type: self::TYPE_QUOTE,
                date: $quote->sent_at,
                id: $quote->id,
                title: sprintf('Orçamento nº %d%s', $quote->number, $quote->version > 1 ? " (v{$quote->version})" : ''),
                summary: sprintf(
                    'R$ %s — %s',
                    number_format((float) $quote->total, 2, ',', '.'),
                    $quote->effectiveQuoteStatus()?->label()
                ),
                professionalName: $quote->createdBy?->name,
            ));
    }

    /**
     * @return Collection<int, PetTimelineEntry>
     */
    private function weightEntries(int $petId): Collection
    {
        return PetWeightHistory::query()
            ->where('pet_id', $petId)
            ->get()
            ->map(fn (PetWeightHistory $weight): PetTimelineEntry => new PetTimelineEntry(
                type: self::TYPE_WEIGHT,
                date: $weight->measured_at,
                id: $weight->id,
                title: $weight->weight.' kg',
                summary: null,
                professionalName: null,
            ));
    }
}
