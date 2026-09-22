<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * `staff_time_off` (singular) → `staff_time_offs` (plural) — não é uma tabela ausente,
 * era uma COLISÃO DE NOME: `App\Models\StaffTimeOff` sempre esperou o nome default do
 * Eloquent (`staff_time_offs`), mas a tabela real, criada em
 * `2025_12_27_214000_create_staff_tables.php`, nasceu no singular. Mesmo `getTable()` vs
 * `pg_tables` que achou `push_subscriptions`/`scheduled_notifications` ausentes marcou este
 * como "ausente" também — só que aqui a tabela já existia (0 linhas, `StaffService` sem
 * rota, conforme `busca-pet-vet-indices.md`/auditoria da Fase 8).
 *
 * Rename puro, sem recriar índices/constraints: mesmo padrão já usado em
 * `2026_09_12_120100_rename_staff_to_organization_members_table.php` (`staff` →
 * `organization_members`) — o Postgres preserva PK, índices, CHECKs e FKs através de um
 * `RENAME TABLE`, incluindo a FK `staff_id` → `organization_members(id)` (que já sobreviveu
 * ao rename anterior de `staff`, porque a constraint aponta para o OID da tabela, não para
 * o nome). Zero risco de dado: tabela vazia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('staff_time_off', 'staff_time_offs');
    }

    public function down(): void
    {
        Schema::rename('staff_time_offs', 'staff_time_off');
    }
};
