<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `staff` (`professional_id` → `users`) vira `organization_members` (`organization_id` →
 * `organizations`) — Fase 1 do split Pessoa/Organização.
 *
 * `professional_id` hoje é a conta que loga como se fosse a empresa; o vínculo correto é
 * pessoa (`user_id`) × organização (`organization_id`). Papel `owner` é novo — a tabela só
 * tinha papéis de funcionário (`veterinarian, assistant, receptionist, groomer, technician`).
 *
 * Sem migration de compatibilidade: confirmado que NENHUMA rota usa `staff`/`StaffService`
 * (`grep -in staff routes/api.php` vazio) — é rename direto, não uma migração de dado em uso.
 */
return new class extends Migration
{
    private const OLD_FOREIGN_KEY = 'staff_professional_id_foreign';

    private const NEW_FOREIGN_KEY = 'organization_members_organization_id_foreign';

    private const OLD_ROLE_CHECK = 'staff_role_check';

    private const NEW_ROLE_CHECK = 'organization_members_role_check';

    private const OLD_COLUMN_INDEX = 'staff_professional_id_is_active_index';

    private const NEW_COLUMN_INDEX = 'organization_members_organization_id_is_active_index';

    /** @var list<string> */
    private const EMPLOYEE_ROLES = ['veterinarian', 'assistant', 'receptionist', 'groomer', 'technician'];

    /** @var list<string> */
    private const ALL_ROLES = ['owner', ...self::EMPLOYEE_ROLES];

    public function up(): void
    {
        Schema::rename('staff', 'organization_members');

        Schema::table('organization_members', function (Blueprint $table): void {
            $table->renameColumn('professional_id', 'organization_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->repointForeignKeyToOrganizations();
        $this->allowOwnerRole();
        $this->renameColumnIndex(self::OLD_COLUMN_INDEX, self::NEW_COLUMN_INDEX);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->renameColumnIndex(self::NEW_COLUMN_INDEX, self::OLD_COLUMN_INDEX);
            $this->restrictBackToEmployeeRoles();
            $this->repointForeignKeyToUsers();
        }

        Schema::table('organization_members', function (Blueprint $table): void {
            $table->renameColumn('organization_id', 'professional_id');
        });

        Schema::rename('organization_members', 'staff');
    }

    private function repointForeignKeyToOrganizations(): void
    {
        DB::statement('ALTER TABLE organization_members DROP CONSTRAINT '.self::OLD_FOREIGN_KEY);
        DB::statement(
            'ALTER TABLE organization_members ADD CONSTRAINT '.self::NEW_FOREIGN_KEY.
            ' FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE'
        );
    }

    private function repointForeignKeyToUsers(): void
    {
        DB::statement('ALTER TABLE organization_members DROP CONSTRAINT '.self::NEW_FOREIGN_KEY);
        DB::statement(
            'ALTER TABLE organization_members ADD CONSTRAINT '.self::OLD_FOREIGN_KEY.
            ' FOREIGN KEY (organization_id) REFERENCES users(id) ON DELETE CASCADE'
        );
    }

    private function allowOwnerRole(): void
    {
        DB::statement('ALTER TABLE organization_members DROP CONSTRAINT '.self::OLD_ROLE_CHECK);
        DB::statement(
            'ALTER TABLE organization_members ADD CONSTRAINT '.self::NEW_ROLE_CHECK.
            ' CHECK (role IN ('.$this->quotedRoles(self::ALL_ROLES).'))'
        );
    }

    private function restrictBackToEmployeeRoles(): void
    {
        DB::statement('ALTER TABLE organization_members DROP CONSTRAINT '.self::NEW_ROLE_CHECK);
        DB::statement(
            'ALTER TABLE organization_members ADD CONSTRAINT '.self::OLD_ROLE_CHECK.
            ' CHECK (role IN ('.$this->quotedRoles(self::EMPLOYEE_ROLES).'))'
        );
    }

    private function renameColumnIndex(string $from, string $to): void
    {
        DB::statement("ALTER INDEX {$from} RENAME TO {$to}");
    }

    /**
     * @param  list<string>  $roles
     */
    private function quotedRoles(array $roles): string
    {
        return implode(',', array_map(fn (string $role): string => "'{$role}'", $roles));
    }
};
