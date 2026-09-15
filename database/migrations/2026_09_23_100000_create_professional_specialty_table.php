<?php

use App\Support\Catalog\SpecialtyPivotBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela pivô profissional ↔ especialidade, com FK dos dois lados.
 *
 * É a correção que `ProfessionalAttributeFilter` registrou como dívida quando decidiu
 * NORMALIZAR NA CONSULTA em vez de migrar os dados: normalizar na consulta resolve a leitura,
 * mas o caminho de ESCRITA continua gravando o que o cliente mandar em `professionals.specialties`
 * (TEXT com JSON dentro, sem FK, sem CHECK), então a taxonomia incoerente volta na semana
 * seguinte. Com FK, "Cardiologia Pediátrica" digitada à mão deixa de ser possível.
 *
 * ── O que esta migration NÃO faz ──────────────────────────────────────────────────────
 * NÃO derruba `professionals.specialties`. Ela continua sendo a fonte de leitura de todo
 * mundo que ainda a lê (a busca, três API Resources, o rascunho de cadastro) e as duas passam
 * a ser escritas juntas — ver `App\Observers\ProfessionalSpecialtyObserver`. Derrubar uma
 * coluna com 7.001 linhas no mesmo passo em que se cria a pivô não deixa caminho de volta se
 * o backfill errar.
 *
 * ── Backfill ──────────────────────────────────────────────────────────────────────────
 * `SpecialtyPivotBackfill` casa rótulo gravado com `specialties.name` NORMALIZADO, e devolve
 * a contagem de rótulos que não casaram. Eles são registrados em log e impressos: rótulo
 * perdido em silêncio vira busca que devolve de menos, e ninguém liga uma coisa à outra meses
 * depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_specialty', function (Blueprint $table): void {
            $table->foreignId('professional_id')->constrained('professionals')->cascadeOnDelete();
            $table->foreignId('specialty_id')->constrained('specialties')->cascadeOnDelete();
            $table->timestamps();

            // Chave primária composta: identifica a linha, impede duplicata e já serve como
            // índice do lado `professional_id` (coluna líder). Pivô não precisa de id
            // próprio — a linha É o par.
            $table->primary(['professional_id', 'specialty_id']);

            // O outro lado precisa do índice próprio: "quem tem esta especialidade" é a
            // pergunta da busca, e no Postgres uma FK NÃO cria índice sozinha.
            $table->index('specialty_id');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_specialty');
    }

    private function backfill(): void
    {
        $report = (new SpecialtyPivotBackfill)->run();

        $this->report('Vinculos criados: '.$report['linked']);

        if ($report['unmapped'] === []) {
            $this->report('Todos os rotulos gravados casaram com o catalogo `specialties`.');

            return;
        }

        Log::warning('Rotulos de especialidade sem linha correspondente em `specialties`.', [
            'unmapped' => $report['unmapped'],
        ]);

        $this->reportUnmapped($report['unmapped']);
    }

    /**
     * @param  array<string, int>  $unmapped
     */
    private function reportUnmapped(array $unmapped): void
    {
        $this->report('ATENCAO: '.count($unmapped).' rotulo(s) sem correspondencia no catalogo (nao migrados):');

        foreach ($unmapped as $label => $professionals) {
            $this->report(sprintf('  - %-50s %d profissional(is)', $label, $professionals));
        }
    }

    private function report(string $line): void
    {
        fwrite(STDOUT, '  '.$line.PHP_EOL);
    }
};
