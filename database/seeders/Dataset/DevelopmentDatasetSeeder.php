<?php

namespace Database\Seeders\Dataset;

use App\Support\Catalog\SpecialtyPivotBackfill;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Dataset PADRÃO de desenvolvimento: ~7.000 profissionais (1.000 de cada um dos 7 tipos) e
 * ~1.000 tutores com pets, todos coerentes com a matriz de domínio.
 *
 * ⚠️ **É DESTRUTIVO.** Apaga usuários gerados e tudo que pende deles antes de recriar.
 * Autorizado explicitamente pelo dono do produto para o banco de desenvolvimento.
 *
 *   docker compose exec backend php artisan db:seed --class="Database\Seeders\Dataset\DevelopmentDatasetSeeder"
 *
 * ── Diferença para o `BenchmarkSeeder` ────────────────────────────────────────────────
 * `Database\Seeders\Benchmark\BenchmarkSeeder` continua existindo e continua útil: ele gera
 * 200 mil users / 2 milhões de agendamentos para medir PERFORMANCE sob volume. O que ele não
 * pode mais ser é o dado padrão de desenvolvimento, porque sorteia nome, tipo, especialidade
 * e serviço de forma INDEPENDENTE — produzindo cadastros que se contradizem ("Hospital
 * Veterinário Freitas" com `professional_type = grooming` e serviços de clínica geral). Dado
 * absurdo torna impossível distinguir busca ruim de cadastro ruim.
 *
 * Regra prática: **este seeder para desenvolver e avaliar relevância; o BenchmarkSeeder para
 * medir latência sob volume.** Rodar os dois em sequência é válido (o benchmark acrescenta,
 * não apaga) desde que a avaliação de relevância aconteça antes.
 *
 * ── O que é preservado ────────────────────────────────────────────────────────────────
 * As contas de demonstração documentadas no `CLAUDE.md` (`tutor@`, `vet@`, `admin@2pets.com.br`,
 * senha `password`) sobrevivem: são apagadas junto com o resto e recriadas ao final pelo
 * `DemoDataSeeder`, que é idempotente (`updateOrCreate`). Qualquer conta criada à mão com
 * e-mail fora dos domínios gerados também sobrevive — ver `wipeGeneratedAccounts()`.
 */
class DevelopmentDatasetSeeder extends Seeder
{
    /**
     * Semente fixa: rodar duas vezes produz o MESMO dataset. É o que permite um teste de
     * relevância afirmar "esta busca devolve exatamente estes profissionais" sem que a
     * próxima execução do seeder o invalide.
     */
    private const RANDOM_SEED = 20260914;

    /**
     * Tabelas ligadas a `users` por referência POLIMÓRFICA do Spatie — sem FK de verdade, e
     * por isso invisíveis para o `CASCADE` do TRUNCATE. Sem limpá-las à mão, sobram
     * atribuições de papel apontando para ids de usuário que não existem mais, e o próximo
     * seed dá a esses papéis a usuários novos por coincidência de id.
     */
    private const POLYMORPHIC_TABLES = ['model_has_roles', 'model_has_permissions'];

    public function run(): void
    {
        $this->guardAgainstUnsafeEnvironment();
        DatasetRandom::seed(self::RANDOM_SEED);

        $startedAt = microtime(true);
        $passwordHash = Hash::make('password');

        $this->wipeGeneratedAccounts();

        $this->runNestedSeeder(ProfessionalDatasetSeeder::class, ['passwordHash' => $passwordHash]);
        $this->runNestedSeeder(TutorDatasetSeeder::class, ['passwordHash' => $passwordHash]);
        $this->call(DemoDataSeeder::class);

        $this->backfillSpecialtyPivot();
        $this->analyze();
        $this->command?->info(sprintf('DevelopmentDatasetSeeder concluído em %.1fs.', microtime(true) - $startedAt));
    }

    /**
     * Os profissionais entram por `insert` em lote, que NÃO dispara evento Eloquent e por
     * isso não passa por `ProfessionalSpecialtyObserver`. Sem este passo a pivô
     * `professional_specialty` nasce vazia num banco recém-semeado, e todo filtro que
     * dependa dela devolveria zero — exatamente a classe de furo que este dataset existe
     * para não ter. Mesma regra em SQL que a migration de criação da pivô usou.
     */
    private function backfillSpecialtyPivot(): void
    {
        $report = (new SpecialtyPivotBackfill)->run();

        $this->command?->info(sprintf(
            'Pivô de especialidades: %d vínculo(s); %d rótulo(s) sem catálogo.',
            $report['linked'],
            count($report['unmapped']),
        ));
    }

    /**
     * Guard duplo e deliberado: ambiente `local` E driver `pgsql`. O primeiro impede que um
     * `db:seed` distraído apague um banco que não é de desenvolvimento; o segundo impede que
     * a suíte (que também roda em Postgres, mas no banco `twopets_test` compartilhado entre
     * sessões) caia aqui por engano — a verificação do NOME do banco é a que realmente
     * protege, porque `twopets_test` é o banco que outras sessões estão usando.
     */
    private function guardAgainstUnsafeEnvironment(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DevelopmentDatasetSeeder é destrutivo: só roda com APP_ENV=local.');
        }

        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('DevelopmentDatasetSeeder requer PostgreSQL (geography/GIST e SQL do driver).');
        }

        $database = DB::getDatabaseName();

        if (str_ends_with((string) $database, '_test')) {
            throw new RuntimeException("Recusando apagar o banco de testes '{$database}'.");
        }
    }

    /**
     * Zera `users` e, por `CASCADE`, todo o grafo pendurado nela (`professionals`,
     * `services`, `pets`, `appointments`, `notifications`...). Nenhuma lista de tabelas aqui:
     * ela ficaria desatualizada no primeiro model novo, e é o `CASCADE` que mantém a
     * cobertura correta sozinho. Tabelas de referência (`breeds`, `specialties`, `roles`,
     * `vaccine_catalog`, `subscription_plans`) não têm FK para `users` e sobrevivem.
     *
     * ⚠️ TRUNCATE apaga TUDO, inclusive conta criada à mão. É o que o dono do produto
     * autorizou explicitamente ("pode apagar tudo e recriar"), e as contas de demonstração
     * do `CLAUDE.md` voltam logo em seguida pelo `DemoDataSeeder`.
     *
     * TRUNCATE e não DELETE, e a diferença é de ORDEM DE GRANDEZA — medida aqui, não
     * estimada: `DELETE FROM users` sobre a base de benchmark (200 mil usuários, 2 milhões
     * de agendamentos, 3 milhões de notificações) passou de **12 minutos sem terminar**,
     * porque cada FK `ON DELETE CASCADE` vira uma varredura por linha apagada. TRUNCATE
     * descarta os arquivos das tabelas de uma vez: ~1 s para o mesmo volume.
     *
     * `RESTART IDENTITY` zera as sequences — sem isso os ids novos continuariam de 200.050 e
     * qualquer expectativa de teste ancorada em id viraria loteria.
     */
    private function wipeGeneratedAccounts(): void
    {
        $tables = implode(', ', ['users', ...self::POLYMORPHIC_TABLES]);

        DB::statement("TRUNCATE TABLE {$tables} RESTART IDENTITY CASCADE");

        $this->command?->warn('  base de usuários zerada (TRUNCATE ... CASCADE).');
    }

    /**
     * Sem `ANALYZE`, o planner opera com a estatística da base ANTERIOR — 45 mil
     * profissionais que não existem mais. A busca é toda decidida por estimativa de
     * cardinalidade (ver `busca-semantica-e-performance.md`), então medir latência antes do
     * ANALYZE mede um plano escolhido com dado errado.
     */
    private function analyze(): void
    {
        foreach (['users', 'professionals', 'services', 'pets'] as $table) {
            DB::statement("ANALYZE {$table}");
        }
    }

    /**
     * @param  class-string<Seeder>  $seeder
     * @param  array<string, mixed>  $parameters
     */
    private function runNestedSeeder(string $seeder, array $parameters): void
    {
        $instance = app()->makeWith($seeder, $parameters);

        if ($this->command !== null) {
            $instance->setCommand($this->command);
        }

        $instance->__invoke();
    }
}
