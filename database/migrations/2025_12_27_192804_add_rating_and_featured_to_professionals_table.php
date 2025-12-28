<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->decimal('average_rating', 3, 2)->default(0)->after('service_radius_km');
            $table->integer('total_reviews')->default(0)->after('average_rating');
            $table->boolean('is_featured')->default(false)->after('total_reviews');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->dropColumn(['average_rating', 'total_reviews', 'is_featured']);
        });
    }
};
