<?php

namespace App\Http\Resources\Professional;

use App\Models\Appointment;
use App\Support\PetAgeCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Um candidato do 409 de pet possivelmente duplicado — contrato
 * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §4, formato FIXADO (o
 * frontend já implementou contra ele — não alterar nome de campo sem atualizar o contrato e
 * avisar). Espécie entra de propósito: nome sozinho não desambigua ("Bob" pode ser cão ou gato
 * do mesmo tutor).
 */
class PetDuplicateCandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'species' => $this->species,
            'age' => $this->birth_date ? PetAgeCalculator::calculate($this->birth_date)['label'] : null,
            'last_appointment_at' => $this->lastAppointmentDate(),
        ];
    }

    /**
     * `max()` é agregação do query builder — devolve a string crua da coluna, não um `Carbon`
     * (o cast `datetime` do Eloquent só se aplica a atributo de model hidratado).
     */
    private function lastAppointmentDate(): ?string
    {
        $date = Appointment::where('pet_id', $this->id)->max('appointment_date');

        return $date === null ? null : Carbon::parse($date)->toISOString();
    }
}
