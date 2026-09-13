<?php

namespace App\Enums;

/**
 * Locale suportado pela API. Hoje só decide o idioma de
 * `GET /register/professional-schema` (`SetLocaleFromAcceptLanguage`) — não é um switch
 * global de i18n da aplicação, ver nota em `docs/` ou no relato da tarefa.
 *
 * pt-BR é o padrão (`APP_LOCALE` em `.env`) e também o fallback de qualquer locale que o
 * cliente peça e não exista aqui — nunca o `en` genérico de `config('app.fallback_locale')`.
 */
enum AppLocale: string
{
    case PT_BR = 'pt_BR';
    case EN_US = 'en_US';

    private const ENGLISH_PREFIX = 'en';

    private const PORTUGUESE_PREFIX = 'pt';

    /**
     * Resolve o cabeçalho `Accept-Language` (RFC 7231, com `q` opcional) para um dos
     * locales suportados, respeitando a ORDEM de preferência do cliente — não basta
     * checar se "en" aparece em algum lugar da lista, senão `"pt-BR;q=0.9,en;q=0.5"`
     * (português preferido, inglês só como segunda opção) resolveria errado para inglês.
     * A primeira tag reconhecida (comece com "en" ou "pt") vence; tag desconhecida
     * (`"es-ES"`, etc.) é pulada, não interrompe a busca. Sem nenhuma tag reconhecida,
     * cai em pt-BR.
     */
    public static function fromAcceptLanguageHeader(?string $header): self
    {
        return collect(self::preferredLanguageTags($header))
            ->map(self::fromLanguageTag(...))
            ->whereNotNull()
            ->first() ?? self::PT_BR;
    }

    private static function fromLanguageTag(string $tag): ?self
    {
        return match (true) {
            str_starts_with($tag, self::ENGLISH_PREFIX) => self::EN_US,
            str_starts_with($tag, self::PORTUGUESE_PREFIX) => self::PT_BR,
            default => null,
        };
    }

    /** @return list<string> tags em ordem de preferência (maior "q" primeiro), minúsculas */
    private static function preferredLanguageTags(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }

        $entries = array_map(self::parseLanguageEntry(...), explode(',', $header));
        usort($entries, static fn (array $a, array $b): int => $b['quality'] <=> $a['quality']);

        return array_column($entries, 'tag');
    }

    /** @return array{tag: string, quality: float} */
    private static function parseLanguageEntry(string $entry): array
    {
        [$tag, $quality] = array_pad(explode(';q=', trim($entry)), 2, '1');

        return ['tag' => strtolower(trim($tag)), 'quality' => (float) $quality];
    }
}
