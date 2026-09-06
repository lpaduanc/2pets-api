<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('moderation_status', 20)->default('pending')->after('is_flagged');
            $table->text('moderation_note')->nullable()->after('moderation_status');
            $table->foreignId('moderated_by')->nullable()->after('moderation_note')->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable()->after('moderated_by');

            $table->index('moderation_status');
        });

        // Back-fill: existing rows default to approved so historical reviews stay visible.
        // is_visible already controls visibility — we only set the new status column.
        \DB::table('reviews')->where('is_visible', true)->update(['moderation_status' => 'approved']);
        \DB::table('reviews')->where('is_visible', false)->where('is_flagged', false)->update(['moderation_status' => 'pending']);
        \DB::table('reviews')->where('is_flagged', true)->update(['moderation_status' => 'flagged']);
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropForeign(['moderated_by']);
            $table->dropIndex(['moderation_status']);
            $table->dropColumn(['moderation_status', 'moderation_note', 'moderated_by', 'moderated_at']);
        });
    }
};
