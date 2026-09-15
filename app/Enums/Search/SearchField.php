<?php

namespace App\Enums\Search;

/**
 * Os campos que a busca textual cobre, com o peso e o limiar de cada um.
 *
 * O limiar é POR CONTEXTO de propósito. Um valor único e global (o `0.2` que o projeto
 * usava para o operador `%`) é permissivo demais para campo longo: qualquer descrição com
 * texto suficiente acaba tendo algum trecho parecido com qualquer coisa. Descrição exige
 * 0,75 porque menção de verdade pontua 1,0 (a palavra aparece inteira) — subir o corte ali
 * custa quase nada de recall e corta muito ruído.
 *
 * Calibração medida contra pares reais do catálogo (`word_similarity`, com unaccent):
 * - "nutricao"/"Nutricionista Animal" = 0,667 → precisa casar;
 * - "neurologia"/"Nefrologia" = 0,571 → NÃO pode casar (são especialidades diferentes);
 * - "cardiologia"/"Dermatologia" = 0,417 → longe.
 * 0,60 é o corte que separa esses dois primeiros casos.
 */
enum SearchField: string
{
    case USER_NAME = 'users.name';
    case BUSINESS_NAME = 'professionals.business_name';
    case SPECIALTIES = 'professionals.specialties';
    case PROFESSIONAL_DESCRIPTION = 'professionals.description';
    case SERVICE_NAME = 'services.name';
    case SERVICE_DESCRIPTION = 'services.description';

    /**
     * A camada da hierarquia de relevância a que o campo pertence — ver `RelevanceLayer`,
     * onde a ordem e o porquê de cada uma estão documentados.
     *
     * ⚠️ Esta atribuição INVERTEU em 2026-09-14. `users.name`/`business_name` eram os campos
     * de maior peso e passaram para a penúltima camada; nome de serviço e especialidade, que
     * eram os mais fracos dos que pontuavam, passaram a ser a evidência mais forte. A busca
     * do 2pets é por competência ("quem faz banho e tosa perto de mim"), não por razão
     * social — e ranquear pelo nome fazia o resultado premiar quem se chamava "Clínica
     * Veterinária X" acima de quem realmente presta o serviço.
     */
    public function layer(): RelevanceLayer
    {
        return match ($this) {
            self::SERVICE_NAME, self::SPECIALTIES => RelevanceLayer::HARD_EVIDENCE,
            self::USER_NAME, self::BUSINESS_NAME => RelevanceLayer::NAME,
            self::PROFESSIONAL_DESCRIPTION, self::SERVICE_DESCRIPTION => RelevanceLayer::DESCRIPTION,
        };
    }

    /**
     * Peso do campo no ranking. `null` = o campo FILTRA mas não pontua.
     *
     * É o peso da CAMADA menos um desempate interno — o peso não é escolhido campo a campo,
     * para não voltar a existir uma hierarquia implícita de campo brigando com a hierarquia
     * explícita de camada.
     */
    public function relevanceWeight(): ?float
    {
        $layerWeight = $this->layer()->weight();

        return $layerWeight === null ? null : $layerWeight - $this->intraLayerPenalty();
    }

    /**
     * Desempate DENTRO da camada, nunca entre camadas (por isso 0,05, muito menor que o
     * intervalo de 0,25 entre camadas).
     *
     * `services.name` acima de `specialties`: serviço ativo é oferta com preço e duração,
     * agendável hoje; especialidade é uma declaração de formação. As duas são evidência
     * dura, mas a primeira é mais verificável.
     * `users.name` acima de `business_name`: é o nome que o card de resultado exibe.
     */
    private function intraLayerPenalty(): float
    {
        return match ($this) {
            self::SPECIALTIES, self::BUSINESS_NAME => 0.05,
            default => 0.0,
        };
    }

    /**
     * Termo curto já é filtrado por `STRICT_WORD` (fronteira de palavra obrigatória), que
     * é bem mais rigoroso: manter 0,60 ali rejeitaria "gato" contra "Gatos e Cães" (0,571
     * no modo estrito) sem motivo. O rigor muda de instrumento, não some.
     */
    public function threshold(WordSimilarityMode $mode): float
    {
        return match ($this) {
            self::PROFESSIONAL_DESCRIPTION, self::SERVICE_DESCRIPTION => $mode === WordSimilarityMode::STRICT_WORD ? 0.60 : 0.75,
            default => $mode === WordSimilarityMode::STRICT_WORD ? 0.50 : 0.60,
        };
    }
}
