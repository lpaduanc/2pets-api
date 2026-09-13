<?php

namespace App\DataTransferObjects;

use App\Enums\VetAccessLevel;

/**
 * Entrada validada de `POST /pet-vet-access/request`.
 *
 * Dois caminhos mutuamente exclusivos, garantidos por `RequestVetAccessRequest`:
 *   - `petId`     → o pet já existe na plataforma.
 *   - `tutorCpf`  → o vet só tem o CPF do tutor; o pet é criado em nome dele.
 *
 * `requestedAccessLevel` é INDICAÇÃO DE NECESSIDADE do vet, nunca concessão: quem define o
 * nível é o tutor, no aceite. Ele é gravado em `pet_vet_accesses.requested_access_level`.
 */
final readonly class VetAccessRequestData
{
    /**
     * @param  array{name?: string, species?: string, breed?: string|null, birth_date?: string|null, gender?: string}  $petData
     */
    public function __construct(
        public ?int $petId,
        public ?Cpf $tutorCpf,
        public array $petData,
        public VetAccessLevel $requestedAccessLevel,
        public ?string $message,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            petId: isset($validated['pet_id']) ? (int) $validated['pet_id'] : null,
            tutorCpf: Cpf::tryParse($validated['tutor_cpf'] ?? null),
            petData: $validated['pet_data'] ?? [],
            requestedAccessLevel: VetAccessLevel::tryFrom(
                (string) ($validated['requested_access_level'] ?? '')
            ) ?? VetAccessLevel::READ,
            message: $validated['message'] ?? null,
        );
    }

    public function isForExistingPet(): bool
    {
        return $this->petId !== null;
    }
}
