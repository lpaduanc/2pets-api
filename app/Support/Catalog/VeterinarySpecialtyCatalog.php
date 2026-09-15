<?php

namespace App\Support\Catalog;

use App\Enums\ServiceCategory;

/**
 * O catálogo canônico de especialidades veterinárias, como DADO — a fonte de onde a tabela
 * `specialties` é semeada (`SpecialtySeeder`) e de onde `ProfessionalOfferingMatrix` decide
 * que especialidade cada tipo de profissional pode declarar.
 *
 * Saiu de dentro do `SpecialtySeeder` (onde era array anônimo) porque passou a ter DOIS
 * consumidores: o seeder e a matriz de coerência. Catálogo que mora só no seeder não pode ser
 * consultado por teste nem por regra de negócio sem bater no banco.
 *
 * ── `name` é a forma canônica gravada em `professionals.specialties` ──────────────────
 * Sem acento e com a caixa do catálogo ("Diagnostico por Imagem", "Nutricao Animal"), que é
 * exatamente como as linhas já existem em `specialties.name`. Antes desta tarefa a coluna
 * misturava TRÊS taxonomias (`["Clínica Geral","Vacinação"]` em 45 mil linhas de benchmark,
 * `["clinica_geral"]`, `["general"]`) e nenhuma delas batia com o catálogo. A partir daqui
 * existe UMA forma, e ela é o `name` do catálogo.
 *
 * ── A lacuna de "Clínica Geral" ───────────────────────────────────────────────────────
 * Era o valor MAIS gravado da base e não existia no catálogo — ou seja, o rótulo mais comum
 * do país não tinha linha em `specialties`, e `?specialty=clinica geral` dependia inteiramente
 * do vocabulário de busca para não voltar vazio. Resolvido aqui: "Clinica Geral" virou linha
 * de catálogo de primeira classe. É a opção conservadora (adicionar o que o mercado usa) em
 * vez de reescrever 45 mil linhas para um rótulo que ninguém digita.
 *
 * ── `gate` — por que existe ───────────────────────────────────────────────────────────
 * Cada especialidade só faz sentido para quem pode prestar o ATO correspondente. Em vez de
 * uma segunda matriz escrita à mão (tipo × especialidade), que divergiria da matriz de
 * capacidades na primeira mudança, a elegibilidade é DERIVADA: a especialidade vale para o
 * tipo se, e só se, o tipo tem a `ServiceCategory` do `gate` em `ProfessionalCapabilityRegistry`.
 * Consequências que caem sozinhas e estão corretas: petshop, banho e tosa, hotel e
 * adestramento ficam sem especialidade nenhuma (não praticam ato veterinário — CFMV);
 * laboratório fica só com "Patologia Clinica" e "Diagnostico por Imagem"; o vet volante
 * perde cirurgia, ortopedia, anestesiologia e odontologia, que dependem de ambiente
 * controlado e não de aparelho portátil.
 */
final class VeterinarySpecialtyCatalog
{
    /**
     * @return list<array{name: string, label: string, description: string, gate: ServiceCategory}>
     */
    public static function all(): array
    {
        return [...self::clinical(), ...self::procedural()];
    }

    /**
     * Especialidades exercidas em consulta — o gate é `CONSULTATION`, então acompanham
     * quem pode consultar (vet volante e clínica).
     *
     * @return list<array{name: string, label: string, description: string, gate: ServiceCategory}>
     */
    private static function clinical(): array
    {
        $gate = ServiceCategory::CONSULTATION;

        return [
            self::entry('Clinica Geral', 'Clínica Geral', 'Atendimento clinico geral de pequenos animais, triagem e acompanhamento de rotina.', $gate),
            self::entry('Cardiologia', 'Cardiologia', 'Diagnostico e tratamento de doencas do coracao e do sistema cardiovascular.', $gate),
            self::entry('Dermatologia', 'Dermatologia', 'Diagnostico e tratamento de doencas da pele, pelos e unhas.', $gate),
            self::entry('Oftalmologia', 'Oftalmologia', 'Diagnostico e tratamento de doencas dos olhos e anexos oculares.', $gate),
            self::entry('Endocrinologia', 'Endocrinologia', 'Diagnostico e tratamento de doencas hormonais e do sistema endocrino.', $gate),
            self::entry('Gastroenterologia', 'Gastroenterologia', 'Diagnostico e tratamento de doencas do trato gastrointestinal, figado e pancreas.', $gate),
            self::entry('Nefrologia/Urologia', 'Nefrologia/Urologia', 'Diagnostico e tratamento de doencas dos rins e do trato urinario.', $gate),
            self::entry('Neurologia', 'Neurologia', 'Diagnostico e tratamento de doencas do sistema nervoso central e periferico.', $gate),
            self::entry('Oncologia', 'Oncologia', 'Diagnostico e tratamento de tumores e neoplasias em animais.', $gate),
            self::entry('Medicina Felina', 'Medicina Felina', 'Atendimento especializado exclusivo para gatos, considerando suas particularidades fisiologicas.', $gate),
            self::entry('Medicina de Animais Silvestres/Exoticos', 'Medicina de Animais Silvestres/Exóticos', 'Atendimento de aves, repteis, roedores e outros animais silvestres e exoticos.', $gate),
            self::entry('Reproducao Animal', 'Reprodução Animal', 'Acompanhamento reprodutivo, inseminacao artificial e neonatologia veterinaria.', $gate),
        ];
    }

    /**
     * Especialidades que dependem de um ato específico — cada uma amarrada à
     * `ServiceCategory` que a viabiliza. É o que impede um vet volante de declarar
     * "Cirurgia Geral" (sem centro cirúrgico) ou um petshop de declarar qualquer uma.
     *
     * @return list<array{name: string, label: string, description: string, gate: ServiceCategory}>
     */
    private static function procedural(): array
    {
        return [
            self::entry('Cirurgia Geral', 'Cirurgia Geral', 'Procedimentos cirurgicos gerais, incluindo castracao, remocao de tumores e cirurgias abdominais.', ServiceCategory::SURGERY),
            self::entry('Anestesiologia', 'Anestesiologia', 'Especializacao em anestesia e controle da dor durante procedimentos cirurgicos.', ServiceCategory::SURGERY),
            self::entry('Ortopedia', 'Ortopedia', 'Diagnostico e tratamento de doencas do sistema musculoesqueletico, incluindo fraturas e luxacoes.', ServiceCategory::SURGERY),
            self::entry('Odontologia Veterinaria', 'Odontologia Veterinária', 'Diagnostico e tratamento de doencas bucais, incluindo limpeza dentaria e exodontia.', ServiceCategory::DENTAL),
            self::entry('Diagnostico por Imagem', 'Diagnóstico por Imagem', 'Realizacao e interpretacao de exames de imagem: radiografia, ultrassonografia, tomografia e ressonancia.', ServiceCategory::IMAGING),
            self::entry('Patologia Clinica', 'Patologia Clínica', 'Analise e interpretacao de exames laboratoriais para auxilio diagnostico.', ServiceCategory::LABORATORY),
            self::entry('Nutricao Animal', 'Nutrição Animal', 'Orientacao nutricional, formulacao de dietas e acompanhamento alimentar.', ServiceCategory::NUTRITION),
            self::entry('Comportamento Animal', 'Comportamento Animal', 'Diagnostico e tratamento de disturbios comportamentais em animais domesticos.', ServiceCategory::BEHAVIORAL),
            self::entry('Fisioterapia/Reabilitacao', 'Fisioterapia/Reabilitação', 'Reabilitacao fisica pos-cirurgica, hidroterapia, acupuntura e terapias complementares.', ServiceCategory::REHABILITATION),
        ];
    }

    /**
     * `name` é a forma de ARMAZENAMENTO (sem acento, igual a `specialties.name` e ao que
     * `professionals.specialties` guarda); `label` é a forma de EXIBIÇÃO, em pt-BR correto.
     * As duas moram na MESMA linha de propósito: uma tabela de rótulos em outro arquivo
     * divergiria do catálogo na primeira especialidade nova, e a divergência apareceria
     * como filtro sem rótulo — ou pior, com o rótulo de outra especialidade.
     *
     * @return array{name: string, label: string, description: string, gate: ServiceCategory}
     */
    private static function entry(string $name, string $label, string $description, ServiceCategory $gate): array
    {
        return ['name' => $name, 'label' => $label, 'description' => $description, 'gate' => $gate];
    }
}
