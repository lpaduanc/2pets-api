<?php

use App\Enums\ServiceCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Alinha o CHECK de `services.category` com `App\Enums\ServiceCategory`.
 *
 * A tabela nasceu com 5 valores (`consultation, surgery, exam, grooming, other`) enquanto o
 * enum tem 15. Consequência medida: `?service_category=vaccination` (e `boarding`, `imaging`,
 * `dental`, `laboratory`...) NUNCA devolvia nada, porque o valor não podia sequer ser
 * gravado — e o `getServiceCategories()` da busca pública oferecia 10 opções das quais
 * metade era inalcançável. Era dívida registrada; virou bloqueio ao semear categorias
 * coerentes por tipo de profissional.
 *
 * Duas pontas mudam aqui e a terceira (a lista oferecida pela API) passou a derivar do enum
 * em `SearchController::getServiceCategories()` — as três nunca mais são redigitadas.
 *
 * ── `exam` não existe no enum ──────────────────────────────────────────────────────────
 * É valor legado, produzido só pelo `BenchmarkSeeder` (36 mil linhas na base de dev). O enum
 * quebra "exame" em `laboratory` (sangue/urina/fezes) e `imaging` (raio-X/ultrassom), e a
 * linha antiga não carrega informação que permita decidir entre os dois. A conversão é para
 * `laboratory` — o mais comum dos dois no mercado e o que não promete equipamento de imagem
 * que o cadastro pode não ter. Conservador de propósito: prometer menos do que o
 * profissional oferece degrada o recall; prometer mais é dado absurdo, que foi justamente a
 * queixa que originou esta tarefa.
 */
return new class extends Migration
{
    private const LEGACY_CATEGORY = 'exam';

    private const LEGACY_REPLACEMENT = 'laboratory';

    private const CONSTRAINT = 'services_category_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);

        DB::update(
            'UPDATE services SET category = ? WHERE category = ?',
            [self::LEGACY_REPLACEMENT, self::LEGACY_CATEGORY],
        );

        DB::statement($this->checkStatement(ServiceCategory::values()));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);

        DB::update(
            'UPDATE services SET category = ? WHERE category NOT IN (?, ?, ?, ?)',
            ['other', 'consultation', 'surgery', 'grooming', 'other'],
        );

        DB::statement($this->checkStatement(['consultation', 'surgery', self::LEGACY_CATEGORY, 'grooming', 'other']));
    }

    /**
     * Valores vêm do enum, nunca de uma lista escrita à mão — mas como CHECK não aceita
     * binding, cada um é escapado por `DB::getPdo()->quote()`. Entrada de usuário não existe
     * aqui (é enum de código), e ainda assim nada de concatenar string crua em DDL.
     *
     * @param  list<string>  $categories
     */
    private function checkStatement(array $categories): string
    {
        $quoted = implode(', ', array_map(
            static fn (string $category): string => DB::getPdo()->quote($category),
            $categories,
        ));

        return 'ALTER TABLE services ADD CONSTRAINT '.self::CONSTRAINT
            ." CHECK (category::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
