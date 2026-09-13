<?php

namespace App\Support\Registration\Draft;

/**
 * Extrai, de um payload de rascunho já validado, só as chaves que o formulário realmente
 * preencheu — o mesmo filtro que os 3 fluxos de `RegistrationDraftController` repetiam
 * (`isset($data[$campo]) && $data[$campo] !== ''`) antes desta classe existir.
 *
 * `isset()` já é `false` para valor `null`, então não precisa de checagem própria para
 * `null` — o middleware global `ConvertEmptyStringsToNull` (padrão do Laravel) converte
 * string vazia em `null` antes de chegar aqui, mas o filtro de string vazia é mantido por
 * segurança para quem chamar fora do ciclo HTTP (ex.: um teste que monte o array na mão).
 */
final class DraftFieldExtractor
{
    /**
     * @param  array<string, mixed>  $data  payload de origem (já validado pelo Form Request)
     * @param  array<string, string>  $fieldMap  coluna de destino => chave de origem em $data
     * @return array<string, mixed>
     */
    public static function extract(array $data, array $fieldMap): array
    {
        $extracted = [];

        foreach ($fieldMap as $targetKey => $sourceKey) {
            if (self::isPresent($data, $sourceKey)) {
                $extracted[$targetKey] = $data[$sourceKey];
            }
        }

        return $extracted;
    }

    /**
     * Remove valores nulos ou string vazia de um array já montado — usado ao ler de volta um
     * model para restaurar o rascunho, onde um atributo ausente não deve poluir a resposta.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withoutEmptyValues(array $data): array
    {
        return array_filter($data, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private static function isPresent(array $data, string $key): bool
    {
        return isset($data[$key]) && $data[$key] !== '';
    }
}
