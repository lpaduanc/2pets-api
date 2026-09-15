<?php

namespace Database\Seeders\Dataset;

use App\Enums\ProfessionalType;
use App\Enums\ServiceCategory;

/**
 * Texto livre do perfil, montado a partir da oferta REAL do cadastro.
 *
 * A descrição é a camada mais fraca da busca (filtra, não pontua — `SearchField`), e é
 * justamente por isso que ela precisa ser coerente: descrição genérica citando serviço que o
 * profissional não presta reintroduz, pela porta dos fundos, o falso-positivo que a
 * hierarquia de relevância existe para eliminar. Aqui ela só cita o que está em
 * `CoherentOffering`.
 */
final class ProfessionalDescription
{
    public static function for(ProfessionalType $type, CoherentOffering $offering): string
    {
        return self::opening($type).' '.self::offerSentence($offering).' '.self::closing();
    }

    private static function opening(ProfessionalType $type): string
    {
        return match ($type) {
            ProfessionalType::VET => 'Médico veterinário autônomo com atendimento domiciliar na região.',
            ProfessionalType::CLINIC => 'Clínica veterinária com equipe própria e responsável técnico com CRMV ativo.',
            ProfessionalType::LABORATORY => 'Laboratório veterinário com coleta própria e laudos assinados por responsável técnico.',
            ProfessionalType::PETSHOP => 'Petshop de bairro com loja física e equipe treinada.',
            ProfessionalType::PET_HOTEL => 'Hospedagem e creche para pets com acompanhamento diário.',
            ProfessionalType::GROOMING => 'Espaço de banho e tosa com profissionais especializados em estética animal.',
            ProfessionalType::TRAINING => 'Adestramento com foco em reforço positivo e convívio em família.',
        };
    }

    private static function offerSentence(CoherentOffering $offering): string
    {
        $labels = array_map(
            static fn (ServiceCategory $category): string => mb_strtolower($category->label()),
            $offering->categories,
        );

        return 'Serviços oferecidos: '.implode(', ', $labels).'.';
    }

    private static function closing(): string
    {
        return DatasetRandom::pick([
            'Agendamento online disponível.',
            'Atendimento com hora marcada.',
            'Aceitamos cartão e Pix.',
            'Retorno sem custo em até 15 dias.',
            'Atendimento humanizado para cães e gatos.',
        ]);
    }
}
