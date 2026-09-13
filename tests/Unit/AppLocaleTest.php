<?php

namespace Tests\Unit;

use App\Enums\AppLocale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parsing puro de `Accept-Language` (RFC 7231), sem banco nem HTTP: roda como Unit.
 * Cobertura de HTTP fim a fim (middleware + cache por locale) está em
 * `Tests\Feature\ProfessionalSchemaLocaleTest`.
 */
class AppLocaleTest extends TestCase
{
    public static function headers(): array
    {
        return [
            'ausente cai em pt-BR' => [null, AppLocale::PT_BR],
            'vazio cai em pt-BR' => ['', AppLocale::PT_BR],
            'pt-BR explícito' => ['pt-BR', AppLocale::PT_BR],
            'en-US explícito' => ['en-US', AppLocale::EN_US],
            'en genérico' => ['en', AppLocale::EN_US],
            'locale desconhecido cai em pt-BR' => ['es-ES', AppLocale::PT_BR],
            'lista com q-value, inglês preferido' => ['pt-BR;q=0.5,en-US;q=0.9', AppLocale::EN_US],
            'lista com q-value, português preferido' => ['en-US;q=0.5,pt-BR;q=0.9', AppLocale::PT_BR],
            'inglês em segundo lugar sem q ainda resolve' => ['es-ES,en;q=0.8', AppLocale::EN_US],
        ];
    }

    #[DataProvider('headers')]
    public function test_resolves_accept_language_header_to_a_supported_locale(?string $header, AppLocale $expected): void
    {
        $this->assertSame($expected, AppLocale::fromAcceptLanguageHeader($header));
    }
}
