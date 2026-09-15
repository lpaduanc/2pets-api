<?php

namespace App\Support\Search;

use App\Enums\ProfessionalType;
use App\Enums\ServiceCategory;

/**
 * Vocabulário canônico PT-BR da busca de profissionais, como DADO puro.
 *
 * Fica no código (versionado, revisável em PR, testável sem banco) e não numa tabela:
 * sinônimo de busca é regra de produto, não cadastro de usuário — uma linha solta no banco
 * de produção não aparece em code review e some no `RefreshDatabase` da suíte.
 *
 * Cada entrada casa UM conceito com:
 * - `terms`: as formas canônicas, escritas JÁ normalizadas (minúsculo, sem acento). Servem
 *   para duas coisas: (a) agulha extra do casamento fuzzy no SQL e (b) forma aceita pelo
 *   filtro exato `?specialty=`, comparada contra `professionals.specialties` depois de
 *   normalizar `_`, `-` e `/` para espaço — é isso que faz `clinica_geral`, `Clínica Geral`
 *   e `Fisioterapia/Reabilitacao` caírem no mesmo conceito sem migration de dados.
 * - `aliases`: tudo que o usuário pode digitar. Passam pelo MESMO pipeline de normalização
 *   do termo buscado (`SearchTextNormalizer`), então podem ser escritos com acento, plural
 *   e palavra de ligação ("banho e tosa" vira a chave "banho tosa" dos dois lados).
 * - `professional_type` / `service_category`: quando o conceito também é estrutural, o
 *   casamento deixa de depender de texto livre e vira comparação de coluna — é o que faz
 *   "clínica" encontrar quem é `professional_type = clinic` mesmo sem a palavra no perfil.
 *
 * Um alias NÃO pode se repetir entre conceitos: a busca escolhe um conceito por termo, e
 * duas entradas com o mesmo alias tornariam o resultado dependente da ordem do array.
 * `SearchVocabularyTest` falha se isso acontecer.
 */
final class SearchConceptDefinitions
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [...self::specialties(), ...self::businessTypes(), ...self::services()];
    }

    /**
     * As 20 linhas do catálogo `specialties` (sem acento, como estão gravadas) mais os
     * termos que o tutor realmente digita — "cardiologista", "dentista", "fisio".
     * `SearchVocabularyTest` garante que nenhuma linha do catálogo fique sem conceito.
     *
     * @return list<array<string, mixed>>
     */
    private static function specialties(): array
    {
        return [
            self::concept('anestesiologia', ['anestesiologia', 'anestesia'], ['anestesista', 'anestesiologista']),
            self::concept('cardiologia', ['cardiologia'], ['cardiologista', 'cardio', 'cardiaco', 'coracao']),
            // "Clínica Geral" NÃO está no catálogo `specialties` (o catálogo tem
            // "Patologia Clinica", "Cirurgia Geral", mas não esta), e mesmo assim é o valor
            // mais gravado em `professionals.specialties` na base — nas três grafias:
            // `"Clínica Geral"`, `"clinica_geral"` e `"general"`. Sem esta entrada,
            // `?specialty=clinica geral` não resolveria nenhuma delas.
            self::concept('clinica_geral', ['clinica geral', 'general'], ['geral', 'generalista', 'clinico geral', 'clinica medica']),
            self::concept('cirurgia_geral', ['cirurgia geral', 'cirurgia'], ['cirurgião', 'operação', 'castração', 'esterilização', 'surgery'], serviceCategory: ServiceCategory::SURGERY),
            self::concept('comportamento_animal', ['comportamento animal', 'comportamento'], ['comportamental', 'etologia', 'etologista', 'behavioral'], serviceCategory: ServiceCategory::BEHAVIORAL),
            self::concept('dermatologia', ['dermatologia'], ['dermatologista', 'dermato', 'pele', 'dermatology']),
            self::concept('diagnostico_por_imagem', ['diagnostico por imagem', 'imagem'], ['raio x', 'raiox', 'radiografia', 'ultrassom', 'ultrassonografia', 'ecografia', 'tomografia', 'radiologia', 'imaging'], serviceCategory: ServiceCategory::IMAGING),
            self::concept('endocrinologia', ['endocrinologia'], ['endocrinologista', 'endocrino', 'hormônio', 'tireoide', 'diabetes']),
            self::concept('fisioterapia', ['fisioterapia reabilitacao', 'fisioterapia'], ['fisio', 'fisioterapeuta', 'reabilitação', 'hidroterapia', 'rehabilitation'], serviceCategory: ServiceCategory::REHABILITATION),
            self::concept('gastroenterologia', ['gastroenterologia'], ['gastro', 'gastroenterologista', 'estômago', 'intestino']),
            self::concept('silvestres_exoticos', ['medicina de animais silvestres exoticos', 'silvestres', 'exoticos'], ['silvestre', 'exótico', 'aves', 'pássaro', 'réptil', 'roedor']),
            self::concept('medicina_felina', ['medicina felina', 'felina'], ['felino', 'gato', 'gateiro', 'feline']),
            self::concept('nefrologia_urologia', ['nefrologia urologia', 'nefrologia', 'urologia'], ['nefrologista', 'urologista', 'rim', 'urinário']),
            self::concept('neurologia', ['neurologia'], ['neurologista', 'neuro', 'neurológico', 'epilepsia', 'convulsão']),
            self::concept('nutricao_animal', ['nutricao animal', 'nutricao'], ['nutricionista', 'nutrologia', 'dieta', 'alimentação', 'nutrition'], serviceCategory: ServiceCategory::NUTRITION),
            self::concept('odontologia', ['odontologia veterinaria', 'odontologia'], ['odonto', 'dentista', 'dente', 'tártaro', 'dental'], serviceCategory: ServiceCategory::DENTAL),
            self::concept('oftalmologia', ['oftalmologia'], ['oftalmologista', 'oftalmo', 'olho', 'catarata', 'ocular']),
            self::concept('oncologia', ['oncologia'], ['oncologista', 'câncer', 'tumor', 'quimioterapia']),
            self::concept('ortopedia', ['ortopedia'], ['ortopedista', 'ortopédico', 'osso', 'fratura', 'luxação', 'orthopedics']),
            self::concept('patologia_clinica', ['patologia clinica', 'patologia'], ['patologista', 'hemograma', 'exame de sangue'], serviceCategory: ServiceCategory::LABORATORY),
            self::concept('reproducao_animal', ['reproducao animal', 'reproducao'], ['reprodutiva', 'obstetrícia', 'ginecologia', 'inseminação', 'cruzamento']),
        ];
    }

    /**
     * Os 7 `ProfessionalType`. O rótulo pt-BR do tipo entra como alias, então "clínica"
     * encontra `professional_type = clinic` sem depender de a palavra aparecer no nome
     * fantasia ou na descrição — que é justamente o buraco que fazia a busca por competência
     * devolver menos do que deveria.
     *
     * @return list<array<string, mixed>>
     */
    private static function businessTypes(): array
    {
        return [
            self::concept('veterinario', ['veterinario', 'veterinaria'], ['vet', 'médico veterinário', 'veterinário volante', 'volante'], professionalType: ProfessionalType::VET),
            self::concept('clinica', ['clinica veterinaria', 'clinica'], ['hospital veterinário', 'hospital'], professionalType: ProfessionalType::CLINIC),
            self::concept('laboratorio', ['laboratorio', 'exames laboratoriais'], ['lab', 'exame', 'análises clínicas'], professionalType: ProfessionalType::LABORATORY, serviceCategory: ServiceCategory::LABORATORY),
            self::concept('petshop', ['petshop', 'pet shop'], ['loja', 'ração', 'acessórios', 'agropecuária'], professionalType: ProfessionalType::PETSHOP),
            self::concept('hospedagem', ['hospedagem', 'hotel'], ['hotelzinho', 'creche', 'pet hotel', 'day care', 'daycare'], professionalType: ProfessionalType::PET_HOTEL, serviceCategory: ServiceCategory::BOARDING),
            self::concept('banho_e_tosa', ['banho e tosa', 'tosa'], ['banho', 'tosador', 'groomer', 'estética animal', 'grooming'], professionalType: ProfessionalType::GROOMING, serviceCategory: ServiceCategory::GROOMING),
            self::concept('adestramento', ['adestramento'], ['adestrador', 'adestrar', 'treinamento', 'training'], professionalType: ProfessionalType::TRAINING, serviceCategory: ServiceCategory::TRAINING),
        ];
    }

    /**
     * `ServiceCategory` que nenhuma especialidade nem tipo de negócio já cobre. "check up"
     * está aqui porque é um dos nomes de serviço mais comuns da base e não casaria com nada
     * do catálogo de especialidades.
     *
     * @return list<array<string, mixed>>
     */
    private static function services(): array
    {
        return [
            self::concept('consulta', ['consulta'], ['consultar', 'atendimento'], serviceCategory: ServiceCategory::CONSULTATION),
            self::concept('emergencia', ['emergencia', 'urgencia'], ['plantão', '24h', '24 horas', 'pronto socorro', 'emergencial'], serviceCategory: ServiceCategory::EMERGENCY),
            self::concept('vacinacao', ['vacinacao', 'vacina'], ['imunização', 'antirrábica', 'v10', 'v8'], serviceCategory: ServiceCategory::VACCINATION),
            self::concept('internacao', ['internacao', 'uti'], ['internar', 'hospitalização', 'terapia intensiva'], serviceCategory: ServiceCategory::HOSPITALIZATION),
            self::concept('check_up', ['check up', 'checkup'], ['exame de rotina', 'rotina'], serviceCategory: ServiceCategory::CONSULTATION),
        ];
    }

    /**
     * `terms` sempre entra também como alias: a forma canônica é, por definição, algo que o
     * usuário pode digitar. Manter as duas listas à mão convidaria ao esquecimento.
     *
     * @param  list<string>  $terms
     * @param  list<string>  $aliases
     * @return array<string, mixed>
     */
    private static function concept(
        string $key,
        array $terms,
        array $aliases,
        ?ProfessionalType $professionalType = null,
        ?ServiceCategory $serviceCategory = null,
    ): array {
        return [
            'key' => $key,
            'terms' => $terms,
            'aliases' => [...$terms, ...$aliases],
            'professional_type' => $professionalType,
            'service_category' => $serviceCategory,
        ];
    }
}
