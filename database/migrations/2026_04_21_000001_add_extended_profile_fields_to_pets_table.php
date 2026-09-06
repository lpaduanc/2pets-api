<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->foreignId('breed_id')->nullable()->after('breed')->constrained('breeds')->nullOnDelete();
            $table->json('coat_colors')->nullable()->after('color');
            $table->string('size', 20)->nullable()->after('weight');
            $table->string('neutered_status', 20)->nullable()->after('neutered');
            $table->string('microchip_number', 100)->nullable()->after('neutered_status');

            $table->json('food_types')->nullable()->after('allergies');
            $table->string('food_brand', 150)->nullable()->after('food_types');
            $table->json('dietary_restrictions')->nullable()->after('food_brand');
            $table->json('food_allergies')->nullable()->after('dietary_restrictions');
            $table->text('food_allergies_other')->nullable()->after('food_allergies');

            $table->json('chronic_conditions')->nullable()->after('chronic_diseases');
            $table->json('surgeries')->nullable()->after('chronic_conditions');
            $table->boolean('previous_hospitalizations')->nullable()->after('surgeries');

            $table->string('does_exercise', 10)->nullable()->after('social_with');
            $table->json('exercise_types')->nullable()->after('does_exercise');
            $table->string('exercise_frequency', 30)->nullable()->after('exercise_types');
            $table->boolean('daily_walk')->nullable()->after('exercise_frequency');
            $table->string('docile_with_strangers', 20)->nullable()->after('daily_walk');
            $table->string('docile_with_animals', 20)->nullable()->after('docile_with_strangers');

            $table->index('microchip_number');
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropForeign(['breed_id']);
            $table->dropIndex(['microchip_number']);
            $table->dropColumn([
                'breed_id',
                'coat_colors',
                'size',
                'neutered_status',
                'microchip_number',
                'food_types',
                'food_brand',
                'dietary_restrictions',
                'food_allergies',
                'food_allergies_other',
                'chronic_conditions',
                'surgeries',
                'previous_hospitalizations',
                'does_exercise',
                'exercise_types',
                'exercise_frequency',
                'daily_walk',
                'docile_with_strangers',
                'docile_with_animals',
            ]);
        });
    }
};
