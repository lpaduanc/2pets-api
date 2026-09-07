<?php

namespace App\Enums;

/**
 * Nível de acesso que o TUTOR concede a um veterinário sobre um pet.
 *
 * Quem escolhe o nível é sempre o tutor, no aceite (`POST /pet-vet-access/{id}/accept`) ou
 * depois, pelo `PATCH /pet-vet-access/{id}/level`. O que o vet manda na solicitação é apenas
 * indicação de necessidade (`pet_vet_accesses.requested_access_level`) e nunca vincula.
 *
 * Os três níveis são cumulativos e ordenáveis por privilégio (`rank()`): FULL cobre WRITE,
 * que cobre READ. Esta é a ÚNICA definição da semântica — todo gate da aplicação
 * (`PetPolicy`, `AuthorizesPetAccess`) pergunta aqui em vez de repetir a lista de valores.
 *
 * | nível   | prontuário/exames/vacinas | escrita clínica | dados cadastrais do pet |
 * |---------|---------------------------|-----------------|-------------------------|
 * | `read`  | lê                        | não             | não                     |
 * | `write` | lê                        | cria/edita      | não                     |
 * | `full`  | lê                        | cria/edita      | edita e exclui          |
 *
 * "Escrita clínica" = vacinas, vermífugos, medicações, cirurgias, exames e histórico de peso
 * pendurados no pet (`AuthorizesPetAccess::resolvePetForWrite`). "Dados cadastrais do pet" =
 * a linha do próprio pet: nome, raça, microchip, foto, exclusão
 * (`AuthorizesPetAccess::resolvePetForFullMutation` e `PetPolicy::update`/`delete`).
 */
enum VetAccessLevel: string
{
    case READ = 'read';
    case WRITE = 'write';
    case FULL = 'full';

    public function label(): string
    {
        return match ($this) {
            self::READ => 'Leitura',
            self::WRITE => 'Leitura e Escrita',
            self::FULL => 'Acesso Completo',
        };
    }

    /** Texto que o tutor lê antes de decidir. Fonte única do que cada nível permite. */
    public function description(): string
    {
        return match ($this) {
            self::READ => 'Pode consultar o prontuário, exames, vacinas e histórico do pet, sem alterar nada.',
            self::WRITE => 'Pode consultar tudo e também registrar vacinas, vermífugos, medicações, cirurgias, exames e peso.',
            self::FULL => 'Pode consultar e registrar dados clínicos e ainda editar ou excluir o cadastro do pet.',
        };
    }

    /** Ordem de privilégio. Só é comparada dentro do enum — não persista este número. */
    public function rank(): int
    {
        return match ($this) {
            self::READ => 1,
            self::WRITE => 2,
            self::FULL => 3,
        };
    }

    /** Este nível já entrega tudo o que `$other` entregaria? */
    public function covers(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public function isHigherThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    /** Existe nível acima deste para o vet pedir como upgrade? */
    public function allowsUpgrade(): bool
    {
        return $this !== self::FULL;
    }

    public function canWriteClinicalRecords(): bool
    {
        return $this->covers(self::WRITE);
    }

    public function canManagePetRecord(): bool
    {
        return $this->covers(self::FULL);
    }

    /**
     * Valores aceitáveis num `whereIn('access_level', ...)` para exigir no mínimo `$minimum`.
     *
     * Evita que cada gate repita a lista à mão — foi assim que `PetPolicy` e
     * `AuthorizesPetAccess` acabaram com duas expressões diferentes da mesma regra.
     *
     * @return list<string>
     */
    public static function valuesAtLeast(self $minimum): array
    {
        return array_values(array_map(
            static fn (self $level): string => $level->value,
            array_filter(self::cases(), static fn (self $level): bool => $level->covers($minimum))
        ));
    }
}
