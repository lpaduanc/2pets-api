<?php

namespace Tests\Unit;

use App\Support\Csv\CsvDownloadResponder;
use Tests\TestCase;

/**
 * CSV/Formula Injection (revisão de segurança, achado Médio 3) — `CsvDownloadResponder` é o
 * ponto único usado pelos 3 exports de `OperationalPanelExportController`
 * (`reports/immunization`, `reports/birthdays`, `reports/clinic-events`). Testado aqui
 * diretamente no responder (em vez de duplicar a fixture pesada de vacina/aniversário três
 * vezes) porque os três controllers só delegam a ele — provar a sanitização aqui prova os três.
 */
class CsvDownloadResponderTest extends TestCase
{
    public function test_neutralizes_a_tutor_name_that_looks_like_a_formula(): void
    {
        $responder = new CsvDownloadResponder;

        $response = $responder->stream('vacinacao.csv', ['pet', 'tutor', 'telefone'], [
            ['Rex', '=HYPERLINK("http://attacker.tld","clique aqui")', '11999999999'],
        ]);

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringNotContainsString(',=HYPERLINK(', $csv);
        $this->assertStringContainsString('\'=HYPERLINK(', $csv);
    }

    public function test_keeps_normal_rows_untouched(): void
    {
        $responder = new CsvDownloadResponder;

        $response = $responder->stream('vacinacao.csv', ['pet', 'tutor', 'telefone'], [
            ['Rex', 'Maria da Silva', '11999999999'],
        ]);

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('Rex', $csv);
        $this->assertStringContainsString('Maria da Silva', $csv);
        $this->assertStringNotContainsString("'Rex", $csv);
    }
}
