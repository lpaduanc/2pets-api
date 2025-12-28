<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->after('id');
            $table->boolean('is_lost')->default(false)->after('public_id');
            $table->text('lost_alert_message')->nullable()->after('is_lost');
            $table->timestamp('lost_since')->nullable()->after('lost_alert_message');
        });

        // Generate UUIDs for existing pets
        DB::table('pets')->whereNull('public_id')->get()->each(function ($pet) {
            DB::table('pets')
                ->where('id', $pet->id)
                ->update(['public_id' => Str::uuid()]);
        });

        // After populating, make it unique
        Schema::table('pets', function (Blueprint $table) {
            $table->uuid('public_id')->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['public_id', 'is_lost', 'lost_alert_message', 'lost_since']);
        });
    }
};

