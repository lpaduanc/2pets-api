<?php

namespace Database\Seeders\Dataset;

use App\Enums\ProfessionalType;

/**
 * Nome de exibição e nome fantasia coerentes com o tipo — com uma minoria DELIBERADA de
 * nomes neutros.
 *
 * Ver `BusinessNamePools` para o porquê dos neutros. Em resumo: se todo cadastro tivesse o
 * tipo no nome, a busca pareceria correta acertando pelo motivo errado (casamento textual do
 * nome cobrindo o buraco da evidência de competência), e o primeiro cadastro real chamado
 * "Amigo Fiel" sumiria do resultado.
 */
final class ProfessionalNaming
{
    /**
     * `vet` é pessoa física: não tem nome fantasia, e forçar um transformaria o veterinário
     * volante num negócio que ele não é (o cadastro dele é por CPF + CRMV).
     */
    public static function businessNameFor(ProfessionalType $type): ?string
    {
        if ($type === ProfessionalType::VET) {
            return null;
        }

        if (DatasetRandom::chance(BusinessNamePools::NEUTRAL_SHARE)) {
            return DatasetRandom::pick(BusinessNamePools::NEUTRAL).' '.DatasetRandom::pick(BusinessNamePools::QUALIFIERS);
        }

        return DatasetRandom::pick(BusinessNamePools::prefixesFor($type)).' '.DatasetRandom::pick(BusinessNamePools::QUALIFIERS);
    }

    /**
     * `users.name` é o que a busca textual cobre e o que o card de resultado mostra. Para PJ
     * é o nome fantasia (senão o card exibiria o nome do sócio); para o vet volante é o nome
     * da pessoa, com o pronome de tratamento que o mercado usa.
     */
    public static function displayNameFor(ProfessionalType $type, ?string $businessName): string
    {
        if ($type !== ProfessionalType::VET) {
            return (string) $businessName;
        }

        return self::veterinarianName();
    }

    /**
     * Pronome de tratamento e primeiro nome saem do MESMO sorteio, nunca de dois
     * independentes. Sorteados à parte, o dataset gerava "Dra. Vinícius Lima",
     * "Dra. Otávio Melo" e "Dra. Igor Dias" — defeito pequeno, mas que aparece na cara do
     * usuário em toda busca por nome.
     */
    private static function veterinarianName(): string
    {
        [$title, $firstNames] = DatasetRandom::chance(2)
            ? ['Dr.', BusinessNamePools::MASCULINE_FIRST_NAMES]
            : ['Dra.', BusinessNamePools::FEMININE_FIRST_NAMES];

        return sprintf(
            '%s %s %s',
            $title,
            DatasetRandom::pick($firstNames),
            DatasetRandom::pick(BusinessNamePools::LAST_NAMES),
        );
    }
}
