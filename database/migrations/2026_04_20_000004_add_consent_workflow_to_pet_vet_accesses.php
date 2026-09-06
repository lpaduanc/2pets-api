<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regra crítica CLAUDE.md §3: veterinário pode iniciar vínculo com pet pelo CPF do tutor,
 * mas o acesso aos dados só é liberado após aceite explícito do tutor (LGPD).
 *
 * Workflow:
 *   pending → accepted | rejected
 *   accepted → revoked (tutor pode a qualquer momento)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_vet_accesses', function (Blueprint $table) {
            $table->string('status', 20)->default('accepted')->after('access_level');
            $table->timestamp('requested_at')->nullable()->after('status');
            $table->timestamp('responded_at')->nullable()->after('requested_at');
            $table->text('rejection_reason')->nullable()->after('responded_at');
            $table->text('revocation_reason')->nullable()->after('rejection_reason');
            $table->foreignId('revoked_by')->nullable()->after('revocation_reason')->constrained('users')->nullOnDelete();

            $table->index('status');
        });

        // Existing rows are treated as already-accepted (backward compat).
        \DB::table('pet_vet_accesses')->where('is_active', true)->update(['status' => 'accepted']);
        \DB::table('pet_vet_accesses')->whereNotNull('revoked_at')->update(['status' => 'revoked']);
    }

    public function down(): void
    {
        Schema::table('pet_vet_accesses', function (Blueprint $table) {
            $table->dropForeign(['revoked_by']);
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'requested_at', 'responded_at', 'rejection_reason', 'revocation_reason', 'revoked_by']);
        });
    }
};
