<?php

namespace App\Services\Reports;

use App\DataTransferObjects\Reports\ClinicEventFeedEntry;
use App\DataTransferObjects\Reports\ClinicEventFeedFilters;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Exam;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Models\Vaccination;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Feed unificado de eventos da clínica (atendimento, vacina, exame, documento emitido) —
 * contrato `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`. Escopo por EQUIPE
 * (`teamUserIds()`), organization-wide, não pet-a-pet como `PetTimelineService`. "Foto" fica
 * fora — não existe model de foto de pet no domínio (só `ReviewPhoto`, de avaliação). Uma
 * query por fonte + merge em memória; `from`/`to` são OBRIGATÓRIOS no controller.
 */
final class ClinicEventFeedService
{
    private const TYPE_APPOINTMENT = 'appointment';

    private const TYPE_VACCINATION = 'vaccination';

    private const TYPE_EXAM = 'exam';

    private const TYPE_DOCUMENT = 'document';

    public function __construct(
        private readonly CommercialScopeResolver $scopeResolver,
    ) {}

    /**
     * @param  list<string>  $eventTypes  vazio = todos os tipos
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forTeam(User $professional, array $eventTypes, ClinicEventFeedFilters $filters): array
    {
        $teamUserIds = $this->scopeResolver->teamUserIds($professional);

        $entries = $this->sources()
            ->filter(fn (string $type) => $eventTypes === [] || in_array($type, $eventTypes, true))
            ->flatMap(fn (string $type) => $this->entriesFor($type, $teamUserIds, $filters))
            ->sortByDesc(fn (ClinicEventFeedEntry $entry): int => $entry->date->getTimestamp())
            ->values();

        return [
            'items' => $entries->map(fn (ClinicEventFeedEntry $entry): array => $entry->toArray($filters->includeContact))->all(),
            'total' => $entries->count(),
        ];
    }

    /** @return Collection<int, string> */
    private function sources(): Collection
    {
        return collect([self::TYPE_APPOINTMENT, self::TYPE_VACCINATION, self::TYPE_EXAM, self::TYPE_DOCUMENT]);
    }

    /** @param  list<int>  $teamUserIds @return Collection<int, ClinicEventFeedEntry> */
    private function entriesFor(string $type, array $teamUserIds, ClinicEventFeedFilters $filters): Collection
    {
        return match ($type) {
            self::TYPE_APPOINTMENT => $this->appointmentEntries($teamUserIds, $filters),
            self::TYPE_VACCINATION => $this->vaccinationEntries($teamUserIds, $filters),
            self::TYPE_EXAM => $this->examEntries($teamUserIds, $filters),
            self::TYPE_DOCUMENT => $this->documentEntries($teamUserIds, $filters),
            default => collect(),
        };
    }

    /** @param  list<int>  $teamUserIds @return Collection<int, ClinicEventFeedEntry> */
    private function appointmentEntries(array $teamUserIds, ClinicEventFeedFilters $filters): Collection
    {
        $query = Appointment::query()
            ->whereIn('professional_id', $teamUserIds)
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->whereBetween('appointment_date', [$filters->from, $filters->to]);

        $this->applyPetFilters($query, $filters);

        return $query->with(['pet:id,name', 'client:id,name,phone,email', 'professional:id,name'])
            ->get()
            ->map(fn (Appointment $appointment): ClinicEventFeedEntry => new ClinicEventFeedEntry(
                ...$this->contactArgs($appointment->client),
                type: self::TYPE_APPOINTMENT,
                date: $appointment->appointment_date,
                id: $appointment->id,
                title: 'Atendimento concluído',
                petId: $appointment->pet?->id,
                petName: $appointment->pet?->name,
                professionalName: $appointment->professional?->name,
            ));
    }

    /** @param  list<int>  $teamUserIds @return Collection<int, ClinicEventFeedEntry> */
    private function vaccinationEntries(array $teamUserIds, ClinicEventFeedFilters $filters): Collection
    {
        $query = Vaccination::query()
            ->whereIn('professional_id', $teamUserIds)
            ->whereBetween('application_date', [$filters->from, $filters->to]);

        $this->applyPetFilters($query, $filters);

        return $query->with(['pet:id,name,user_id', 'pet.user:id,name,phone,email', 'professional:id,name'])
            ->get()
            ->map(fn (Vaccination $vaccination): ClinicEventFeedEntry => new ClinicEventFeedEntry(
                ...$this->contactArgs($vaccination->pet?->user),
                type: self::TYPE_VACCINATION,
                date: $vaccination->application_date,
                id: $vaccination->id,
                title: 'Vacina: '.$vaccination->vaccine_name,
                petId: $vaccination->pet?->id,
                petName: $vaccination->pet?->name,
                professionalName: $vaccination->professional?->name,
            ));
    }

    /** @param  list<int>  $teamUserIds @return Collection<int, ClinicEventFeedEntry> */
    private function examEntries(array $teamUserIds, ClinicEventFeedFilters $filters): Collection
    {
        $query = Exam::query()
            ->whereIn('professional_id', $teamUserIds)
            ->whereBetween('exam_date', [$filters->from, $filters->to]);

        $this->applyPetFilters($query, $filters);

        return $query->with(['pet:id,name,user_id', 'pet.user:id,name,phone,email', 'professional:id,name'])
            ->get()
            ->map(fn (Exam $exam): ClinicEventFeedEntry => new ClinicEventFeedEntry(
                ...$this->contactArgs($exam->pet?->user),
                type: self::TYPE_EXAM,
                date: $exam->exam_date,
                id: $exam->id,
                title: 'Exame: '.$exam->exam_name,
                petId: $exam->pet?->id,
                petName: $exam->pet?->name,
                professionalName: $exam->professional?->name,
            ));
    }

    /** @param  list<int>  $teamUserIds @return Collection<int, ClinicEventFeedEntry> */
    private function documentEntries(array $teamUserIds, ClinicEventFeedFilters $filters): Collection
    {
        $query = GeneratedDocument::query()
            ->whereIn('issued_by', $teamUserIds)
            ->whereBetween('issued_at', [$filters->from, $filters->to]);

        $this->applyPetFilters($query, $filters);

        return $query->with(['pet:id,name', 'client:id,name,phone,email', 'issuedBy:id,name'])
            ->get()
            ->map(fn (GeneratedDocument $document): ClinicEventFeedEntry => new ClinicEventFeedEntry(
                ...$this->contactArgs($document->client),
                type: self::TYPE_DOCUMENT,
                date: $document->issued_at,
                id: $document->id,
                title: 'Documento emitido',
                petId: $document->pet?->id,
                petName: $document->pet?->name,
                professionalName: $document->issuedBy?->name,
            ));
    }

    /**
     * Extraído para não repetir os 4 campos de contato do cliente nos 4 construtores acima —
     * o e-mail/telefone SEMPRE viaja no DTO, quem decide exibir ou não é `toArray()`.
     *
     * @return array{clientId: ?int, clientName: ?string, clientPhone: ?string, clientEmail: ?string}
     */
    private function contactArgs(?User $client): array
    {
        return [
            'clientId' => $client?->id,
            'clientName' => $client?->name,
            'clientPhone' => $client?->phone,
            'clientEmail' => $client?->email,
        ];
    }

    /**
     * Filtra por cliente/espécie sempre através do PET (não de uma coluna `client_id`
     * própria de cada tabela) — `Vaccination`/`Exam` nem têm essa coluna, e assim os 4
     * eventos usam sempre o mesmo caminho de filtro, sem casos especiais por fonte.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyPetFilters(Builder $query, ClinicEventFeedFilters $filters): void
    {
        if ($filters->clientId === null && $filters->species === null) {
            return;
        }

        $query->whereHas('pet', function (Builder $pets) use ($filters): void {
            if ($filters->clientId !== null) {
                $pets->where('user_id', $filters->clientId);
            }

            if ($filters->species !== null) {
                $pets->where('species', $filters->species);
            }
        });
    }
}
