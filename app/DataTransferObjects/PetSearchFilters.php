<?php

namespace App\DataTransferObjects;

/**
 * Filtros da busca de pets que o veterinário faz antes de pedir acesso.
 *
 * Só identificadores EXATOS entram aqui (CPF do tutor, microchip). É uma decisão de
 * privacidade, não uma limitação técnica: busca por nome sobre a base inteira permitiria a
 * qualquer conta de vet enumerar tutores e pets da plataforma. Busca textual existe, mas só
 * dentro da carteira de pacientes do próprio vet (`PetVetAccess` já concedido).
 */
final readonly class PetSearchFilters
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function __construct(
        public ?Cpf $tutorCpf,
        public ?string $microchipNumber,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $microchip = trim((string) ($validated['microchip_number'] ?? ''));

        return new self(
            tutorCpf: Cpf::tryParse($validated['tutor_cpf'] ?? null),
            microchipNumber: $microchip === '' ? null : $microchip,
            perPage: min(
                (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
                self::MAX_PER_PAGE
            ),
        );
    }
}
