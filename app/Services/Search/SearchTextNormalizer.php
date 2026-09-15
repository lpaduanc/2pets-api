<?php

namespace App\Services\Search;

/**
 * Normalização textual PT-BR da busca. Os DOIS lados da comparação passam por aqui — termo
 * digitado e alias do vocabulário — porque é a única garantia de que "Banho e Tosa",
 * "banho e tosa" e "BANHO E TOSA" viram a mesma chave sem ninguém precisar lembrar disso.
 *
 * O acento é removido em PHP (e não só no banco por `immutable_unaccent`) porque a
 * interpretação do termo — achar o conceito, decidir se é typo — acontece antes do SQL.
 */
final class SearchTextNormalizer
{
    /**
     * Palavras de ligação que só existem para a frase fazer sentido em português e nunca
     * identificam um profissional. Manter "de"/"e" como termo obrigatório faria
     * "banho e tosa" exigir que alguém tivesse literalmente um "e" no perfil.
     */
    private const STOPWORDS = [
        'a', 'ao', 'aos', 'as', 'com', 'da', 'das', 'de', 'do', 'dos', 'e', 'em', 'na',
        'nas', 'no', 'nos', 'o', 'os', 'para', 'por', 'pra', 'que', 'um', 'uma', 'umas', 'uns',
    ];

    private const MIN_TOKEN_LENGTH = 2;

    /**
     * Mapa explícito em vez de `iconv('ASCII//TRANSLIT')`: o iconv depende do locale do
     * container e, quando ele não bate, devolve `?` ou `'a` no lugar da letra — falha
     * silenciosa que só aparece em produção.
     */
    private const ACCENT_MAP = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];

    /**
     * Minúsculas, sem acento, só letras e dígitos separados por um espaço. Pontuação vira
     * separador (e não some) para "fisioterapia/reabilitacao" virar duas palavras.
     */
    public function normalize(string $text): string
    {
        $lowercased = mb_strtolower(trim($text), 'UTF-8');
        $unaccented = strtr($lowercased, self::ACCENT_MAP);
        $alphanumericOnly = preg_replace('/[^a-z0-9]+/u', ' ', $unaccented) ?? '';

        return trim($alphanumericOnly);
    }

    /**
     * @return list<string>
     */
    public function tokenize(string $text): array
    {
        $normalized = $this->normalize($text);

        if ($normalized === '') {
            return [];
        }

        $significant = array_filter(
            explode(' ', $normalized),
            fn (string $token): bool => $this->isSignificant($token),
        );

        return array_values(array_map(fn (string $token): string => $this->singularize($token), $significant));
    }

    /**
     * Chave canônica de um alias/termo multi-palavra: o mesmo `tokenize` do termo digitado,
     * juntado por espaço. Sem isso, "banho e tosa" (alias) e "banho tosa" (o que sobra do
     * que o usuário digitou depois das stopwords) nunca se encontrariam.
     */
    public function canonicalKey(string $text): string
    {
        return implode(' ', $this->tokenize($text));
    }

    private function isSignificant(string $token): bool
    {
        return mb_strlen($token) >= self::MIN_TOKEN_LENGTH && ! in_array($token, self::STOPWORDS, true);
    }

    /**
     * Plural simples do português: "clinicas" → "clinica", "exames" → "exame". Não tenta
     * cobrir "cães"/"animais" — o trigrama já tolera essa diferença, e uma regra mais
     * esperta erraria mais do que acertaria. Aliases passam pela MESMA função, então os
     * dois lados sempre concordam, inclusive quando a regra está "errada".
     */
    private function singularize(string $token): string
    {
        if (mb_strlen($token) <= 3 || ! str_ends_with($token, 's') || str_ends_with($token, 'ss')) {
            return $token;
        }

        return mb_substr($token, 0, -1);
    }
}
