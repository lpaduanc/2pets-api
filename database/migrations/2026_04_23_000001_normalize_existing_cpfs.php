<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Strips formatting from any CPF already stored with mask (e.g. `123.456.789-00` → `12345678900`).
 *
 * The `cpf` mutator on User guarantees every new write is already clean; this
 * one-shot update brings legacy rows in line so `where('cpf', $digits)` works
 * for everyone without escaping tricks.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `regexp_replace` is PostgreSQL-only. The test suite runs on sqlite :memory:
        // (see phpunit.xml), where every migration is replayed against a fresh schema
        // that has no legacy rows to normalize — so skipping is correct, not a shortcut.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("UPDATE users SET cpf = regexp_replace(cpf, '\\D', '', 'g') WHERE cpf IS NOT NULL AND cpf <> regexp_replace(cpf, '\\D', '', 'g')");
    }

    public function down(): void
    {
        // No-op: reformatting digits back to a masked form would be guessing,
        // and the new contract is "CPF is stored clean". Leave rows as-is.
    }
};
