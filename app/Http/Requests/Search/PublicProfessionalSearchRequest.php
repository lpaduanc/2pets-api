<?php

namespace App\Http\Requests\Search;

use App\Enums\PetSpecies;
use App\Enums\ProfessionalType;
use App\Enums\ServiceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação da busca pública de profissionais (`GET /api/public/search`).
 *
 * ⚠️ Parâmetro que não aparece em `rules()` é DESCARTADO por `validated()` EM SILÊNCIO —
 * sem erro, sem log. Já custou horas neste mesmo endpoint (o site mandava `q`, a validação
 * esperava `query`, e toda busca do site voltava o catálogo inteiro). Filtro novo no
 * contrato precisa entrar aqui, em `SearchFiltersDTO::fromRequest()` e em
 * `ProfessionalSearchCache::buildCacheKey()` — os três, sempre.
 *
 * ── Multi-seleção com compatibilidade de escalar ──────────────────────────────────────
 * As quatro dimensões de `MULTI_VALUE_FILTERS` aceitam as DUAS formas de fio:
 *   - escalar legado: `?professional_type=vet` (é o que o `2pets-site` manda hoje);
 *   - array:          `?professional_type[]=vet&professional_type[]=clinic`.
 * `prepareForValidation()` colapsa a primeira na segunda, então `rules()` tem um caso só e
 * `validated()` devolve sempre lista. Semântica: OR dentro da dimensão, AND entre dimensões.
 */
class PublicProfessionalSearchRequest extends FormRequest
{
    /** @var list<string> */
    private const MULTI_VALUE_FILTERS = ['professional_type', 'service_category', 'specialty', 'species'];

    /**
     * Teto da ÚNICA dimensão de texto livre. Não é burocracia: cada especialidade extra
     * multiplica os predicados do OR de `ProfessionalAttributeFilter` (uma forma armazenada
     * por sinônimo), e uma URL com 200 valores viraria um WHERE que nenhum índice poda.
     *
     * ⚠️ Taxonomia FECHADA não usa este teto — usa o tamanho do próprio enum
     * (`closedListRules()`). Um teto abaixo do catálogo transforma um estado alcançável pela
     * UI em 422: a busca oferece as 14 categorias de `ServiceCategory`, e com teto fixo de 10
     * o usuário que marcasse a 11ª levava "não deve ter mais de 10 itens" — numa tela que
     * não lê `errors` e mostra só um estado de erro genérico. Marcar todas é inócuo e
     * indexável (vira um `whereIn` sobre a coluna), então o limite certo é o catálogo.
     */
    private const MAX_FREE_TEXT_VALUES = 10;

    private const MAX_SPECIALTY_LENGTH = 120;

    /** Rota pública: o controle de acesso é o throttle nomeado `public-search`, não auth. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->multiValueFilters());
    }

    /**
     * @return array<string, array<int, string|array<string, mixed>>>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'integer', 'min:1', 'max:100'],
            'professional_type' => $this->closedListRules(ProfessionalType::class),
            'professional_type.*' => [Rule::enum(ProfessionalType::class)],
            'service_category' => $this->closedListRules(ServiceCategory::class),
            'service_category.*' => [Rule::enum(ServiceCategory::class)],
            // Especialidade é texto livre de propósito: o vocabulário de busca aceita
            // rótulo, slug e sinônimo indistintamente (`SearchVocabulary::storedFormsFor`),
            // então prender a um `in:` do catálogo quebraria o filtro para qualquer forma
            // que o front mandasse fora do rótulo exato.
            'specialty' => $this->freeTextListRules(),
            'specialty.*' => ['string', 'max:'.self::MAX_SPECIALTY_LENGTH],
            // Espécie, ao contrário, é taxonomia fechada — valor fora do enum é erro do
            // cliente, e devolver 422 é melhor do que devolver lista vazia sem explicação.
            'species' => $this->closedListRules(PetSpecies::class),
            'species.*' => [Rule::enum(PetSpecies::class)],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'query' => ['nullable', 'string', 'max:255'],
            'sort_by' => ['nullable', 'string', 'in:distance,rating,relevance,price_low,price_high'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'available_now' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * O erro de um item chega como `professional_type.0`; sem isto a mensagem sairia com o
     * nome técnico do índice no lugar do rótulo.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'professional_type.*' => 'tipo de profissional',
            'service_category.*' => 'categoria de serviço',
            'specialty.*' => 'especialidade',
            'species.*' => 'espécie',
        ];
    }

    /**
     * Teto = tamanho do catálogo, derivado do enum e nunca redigitado. Enum que ganhe um caso
     * novo sobe o teto junto, sem ninguém lembrar de mexer aqui.
     *
     * @param  class-string<\BackedEnum>  $enum
     * @return list<string>
     */
    private function closedListRules(string $enum): array
    {
        return ['nullable', 'array', 'max:'.count($enum::cases())];
    }

    /**
     * @return list<string>
     */
    private function freeTextListRules(): array
    {
        return ['nullable', 'array', 'max:'.self::MAX_FREE_TEXT_VALUES];
    }

    /**
     * Escalar vira lista de um item; item vazio é descartado AQUI, antes das regras.
     *
     * O descarte do vazio não é detalhe: `?species=` (que é o que um `<select>` sem escolha
     * manda) chegaria como `[null]` e `Rule::enum` devolveria 422 para o usuário que
     * simplesmente não escolheu nada — regressão silenciosa em relação ao `nullable` do
     * contrato escalar anterior. ⚠️ Chega como `null`, não como `''`: o middleware global
     * `ConvertEmptyStringsToNull` já converteu antes de a validação existir, então testar só
     * por string vazia NÃO pega o caso (medido por curl, não por leitura).
     * `SearchFiltersDTO` repete a normalização de propósito: ele é construído também fora de
     * um request (`nearby()`, testes), e a garantia não pode depender de quem chama.
     *
     * @return array<string, list<mixed>>
     */
    private function multiValueFilters(): array
    {
        $filters = [];

        foreach (self::MULTI_VALUE_FILTERS as $filter) {
            if (! $this->has($filter)) {
                continue;
            }

            $filters[$filter] = $this->presentValuesOf($this->input($filter));
        }

        return $filters;
    }

    /**
     * Valor não escalar (`?species[][]=x`) é PRESERVADO para que `rules()` o rejeite com
     * 422 — descartá-lo aqui transformaria requisição malformada em busca sem filtro.
     *
     * @return list<mixed>
     */
    private function presentValuesOf(mixed $rawValue): array
    {
        $values = is_array($rawValue) ? array_values($rawValue) : [$rawValue];

        return array_values(array_filter(
            array_map(static fn (mixed $value): mixed => is_scalar($value) ? trim((string) $value) : $value, $values),
            static fn (mixed $value): bool => $value !== '' && $value !== null,
        ));
    }
}
