<?php

namespace App\DataTransferObjects\Search;

use App\Enums\ProfessionalType;
use App\Enums\ServiceCategory;

/**
 * Um conceito do vocabulário de busca já resolvido: "o usuário quis dizer cardiologia".
 *
 * Imutável de propósito — é o resultado da interpretação do termo, e nada depois da
 * interpretação tem o direito de reescrever o que o usuário pediu.
 */
final readonly class SearchConcept
{
    /**
     * @param  list<string>  $terms  formas canônicas normalizadas (sem acento, minúsculas)
     */
    public function __construct(
        public string $key,
        public array $terms,
        public ?ProfessionalType $professionalType = null,
        public ?ServiceCategory $serviceCategory = null,
    ) {}

    /**
     * Se este conceito exige EVIDÊNCIA DE COMPETÊNCIA para qualificar um profissional — ou
     * seja, se o `professional_type` sozinho deixa de bastar.
     *
     * A regra, e ela tem UMA exceção só: **exige evidência sempre, menos quando o conceito é
     * um tipo de negócio PURO** — um tipo sem nenhuma categoria de serviço correspondente.
     *
     * Exige (o caso comum):
     * - conceito de especialidade — "cardiologia", "ortopedia", "dermatologia". Não têm
     *   categoria de serviço nem tipo próprio, e mesmo assim são afirmações de competência:
     *   só qualifica quem declarou a especialidade ou tem um serviço com aquele nome.
     * - conceito com categoria de serviço — "banho e tosa", "vacinação", "hospedagem",
     *   "adestramento", "laboratório". Aqui existe o verificável ("tem serviço ativo de banho
     *   e tosa", "declarou banho no cadastro") e o tipo vira o que sempre foi: promessa. É
     *   exatamente a queixa do dono do produto — `professional_type = grooming` com serviços
     *   de clínica geral e vacinação aparecia em "banho e tosa".
     *
     * NÃO exige (a exceção): conceito que é SÓ tipo de negócio — "clínica", "petshop",
     * "veterinário". Não existe serviço chamado "ser uma clínica", então o tipo é a evidência
     * mais dura que pode existir. Negar a qualificação aqui tornaria o termo inbuscável, e a
     * cura seria pior que a doença.
     */
    public function requiresCompetenceEvidence(): bool
    {
        return $this->serviceCategory !== null || $this->professionalType === null;
    }

    /**
     * UMA única forma canônica vai para o SQL, e é a MAIS CURTA.
     *
     * As duas decisões são de latência, medida: cada agulha extra multiplica os predicados
     * do WHERE por seis (um por campo coberto), e foi exatamente isso que fez "banho e tosa"
     * levar 14,7 s — três agulhas ("banho tosa" digitada + "banho e tosa" + "tosa") viraram
     * 18 predicados por unidade.
     *
     * A mais curta é também a que casa MAIS: `word_similarity` procura a agulha dentro do
     * campo, então "odontologia" encontra "Odontologia Veterinaria" (1,000) enquanto
     * "odontologia veterinaria" não encontra "Odontologia" (0,571). Cortar pela mais curta
     * ganha latência sem perder recall — as formas longas continuam servindo o filtro exato
     * `?specialty=`, que é outro caminho e não paga esse custo.
     *
     * @return list<string>
     */
    public function sqlTerms(): array
    {
        $shortest = $this->terms;
        usort($shortest, static fn (string $first, string $second): int => mb_strlen($first) <=> mb_strlen($second));

        return array_slice($shortest, 0, 1);
    }

    /**
     * Formas aceitas pelo filtro exato `?specialty=` — aqui SEM corte, porque o filtro
     * compara palavra inteira contra o texto gravado (não é fuzzy) e cada forma extra é
     * exatamente o que permite casar a taxonomia incoerente de `professionals.specialties`
     * (`clinica_geral` e `Clínica Geral` na mesma base).
     *
     * @return list<string>
     */
    public function storedForms(): array
    {
        return $this->terms;
    }
}
