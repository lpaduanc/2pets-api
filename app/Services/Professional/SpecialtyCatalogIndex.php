<?php

namespace App\Services\Professional;

use App\Models\Specialty;
use App\Services\Search\SearchTextNormalizer;
use App\Support\Catalog\SpecialtyAliasCatalog;

/**
 * Resolve um valor vindo do cliente para uma linha do catálogo `specialties`.
 *
 * É o portão do caminho de ESCRITA: antes desta classe, `professionals.specialties` aceitava
 * o que o front mandasse, e foi assim que a mesma base acumulou `"Clínica Geral"`,
 * `"clinica_geral"` e `"general"` como se fossem três coisas. Com a resolução obrigatória,
 * ou o valor vira uma linha do catálogo ou vira 422 — nunca uma linha de texto livre nova.
 *
 * ── Por que a comparação é normalizada, e não `=` ─────────────────────────────────────
 * O mesmo conceito chega de três lugares com três grafias legítimas: o rótulo exibido
 * ("Diagnóstico por Imagem", com acento), o `value` de `GET /public/categories`
 * ("diagnostico por imagem") e o slug legado do cadastro ("clinica_geral"). Exigir a grafia
 * exata do catálogo transformaria compatibilidade em erro do usuário.
 *
 * ⚠️ Usa `normalize()` e NÃO `canonicalKey()`: a chave canônica do vocabulário de busca
 * remove stopword e singulariza ("Medicina de Animais" → "medicina animai"), o que é certo
 * para casar o que alguém DIGITOU e errado para casar um item de catálogo fechado.
 * `SpecialtyPivotBackfill` normaliza em SQL exatamente do mesmo jeito.
 *
 * ── Alias ─────────────────────────────────────────────────────────────────────────────
 * O índice também responde pelos slugs em inglês do formulário do app
 * (`SpecialtyAliasCatalog`): sem eles, fechar a escrita no catálogo fechava o cadastro de
 * veterinário inteiro, porque o cliente nunca mandou a grafia do catálogo. O alias resolve
 * para a MESMA linha — nada de conceito novo.
 */
final class SpecialtyCatalogIndex
{
    /** @var array<string, Specialty>|null */
    private ?array $specialtiesByNormalizedName = null;

    /** @var list<string>|null */
    private ?array $normalizedMisfiledAliases = null;

    public function __construct(private readonly SearchTextNormalizer $normalizer) {}

    public function resolve(string $value): ?Specialty
    {
        return $this->index()[$this->normalizer->normalize($value)] ?? null;
    }

    public function isInCatalog(string $value): bool
    {
        return $this->resolve($value) !== null;
    }

    /**
     * O valor é um dos que o formulário manda como especialidade sem ser especialidade
     * (hoje só `emergency`). Quem chama descarta e registra — ver `SpecialtyAliasCatalog`.
     */
    public function isMisfiled(string $value): bool
    {
        return in_array($this->normalizer->normalize($value), $this->misfiledAliases(), true);
    }

    /**
     * Os ids do catálogo para uma lista de valores, sem repetição.
     *
     * @param  list<string>  $values
     * @return list<int>
     */
    public function idsFor(array $values): array
    {
        return array_map(static fn (Specialty $specialty): int => $specialty->id, $this->resolveAll($values));
    }

    /**
     * Cada valor na forma canônica de `specialties.name` — "clinica_geral" e
     * "Diagnóstico por Imagem" viram "Clinica Geral" e "Diagnostico por Imagem".
     *
     * Valor que não resolve passa INTACTO, e isso é essencial: descartá-lo aqui
     * transformaria payload inválido em payload válido antes de `ValidSpecialty` ter a
     * chance de recusá-lo. Canonicalizar é padronizar grafia, nunca filtrar conteúdo.
     *
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    public function canonicalize(array $values): array
    {
        return array_values(array_unique(array_map(
            fn (mixed $value): mixed => is_string($value) ? ($this->resolve($value)?->name ?? $value) : $value,
            $values,
        ), SORT_REGULAR));
    }

    /**
     * Valor que não resolve é ignorado AQUI de propósito: quem deve rejeitá-lo é a validação
     * (`App\Rules\ValidSpecialty`), e repetir a rejeição na persistência transformaria um
     * payload já aceito em erro 500.
     *
     * @param  list<string>  $values
     * @return list<Specialty>
     */
    private function resolveAll(array $values): array
    {
        $resolved = [];

        foreach ($values as $value) {
            $specialty = $this->resolve($value);

            if ($specialty !== null) {
                $resolved[$specialty->id] = $specialty;
            }
        }

        return array_values($resolved);
    }

    /**
     * @return array<string, Specialty>
     */
    private function index(): array
    {
        return $this->specialtiesByNormalizedName ??= $this->buildIndex();
    }

    /**
     * Nome do catálogo POR ÚLTIMO no merge: se algum dia um alias colidir com um `name`
     * real, quem manda é o catálogo, não a lista de compatibilidade.
     *
     * @return array<string, Specialty>
     */
    private function buildIndex(): array
    {
        $byName = $this->indexByName();

        return array_merge($this->aliasEntries($byName), $byName);
    }

    /**
     * @return array<string, Specialty>
     */
    private function indexByName(): array
    {
        $index = [];

        foreach (Specialty::query()->get() as $specialty) {
            $index[$this->normalizer->normalize($specialty->name)] = $specialty;
        }

        return $index;
    }

    /**
     * Alias cujo alvo não existe na tabela é ignorado (banco sem o seed completo, por
     * exemplo) — ele simplesmente não resolve, exatamente como antes de existir.
     *
     * @param  array<string, Specialty>  $byName
     * @return array<string, Specialty>
     */
    private function aliasEntries(array $byName): array
    {
        return collect(SpecialtyAliasCatalog::canonicalNameByAlias())
            ->mapWithKeys(fn (string $canonicalName, string $alias): array => [
                $this->normalizer->normalize($alias) => $byName[$this->normalizer->normalize($canonicalName)] ?? null,
            ])
            ->filter()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function misfiledAliases(): array
    {
        return $this->normalizedMisfiledAliases ??= array_map(
            fn (string $alias): string => $this->normalizer->normalize($alias),
            SpecialtyAliasCatalog::misfiledAliases(),
        );
    }
}
