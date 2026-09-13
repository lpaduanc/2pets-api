<?php

use App\Enums\OrganizationRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 do split Pessoa/Organização: convite por e-mail para um cargo dentro da organização.
 *
 * `token` é texto puro (ver `OrganizationInvitation`) — não precisa do hash+compare de
 * `password_reset_tokens` porque não é senha, é um link de uso único de validade curta.
 */
return new class extends Migration
{
    private const ROLE_CHECK_CONSTRAINT = 'organization_invitations_role_check';

    private const PENDING_UNIQUE_INDEX = 'organization_invitations_pending_unique';

    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20);
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'email']);
        });

        $this->addRoleCheckConstraint();
        $this->addPendingInvitationUniqueIndex();
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }

    private function addRoleCheckConstraint(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $allowedValues = implode(',', array_map(
            fn (string $value): string => "'{$value}'",
            OrganizationRole::values()
        ));

        DB::statement(
            'ALTER TABLE organization_invitations ADD CONSTRAINT '.self::ROLE_CHECK_CONSTRAINT.
            " CHECK (role IN ({$allowedValues}))"
        );
    }

    /**
     * Evita dois convites pendentes para o mesmo e-mail na mesma organização — mesmo padrão
     * de índice único parcial já usado em `pet_vet_access_live_unique`. Um convite expirado
     * (mas nunca revogado nem aceito) ainda ocupa o slot até o `OrganizationInvitationService`
     * reaproveitar a linha (nunca insere uma segunda enquanto a antiga não é revogada/aceita).
     */
    private function addPendingInvitationUniqueIndex(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::PENDING_UNIQUE_INDEX.
            ' ON organization_invitations (organization_id, email)
             WHERE accepted_at IS NULL AND revoked_at IS NULL'
        );
    }
};
