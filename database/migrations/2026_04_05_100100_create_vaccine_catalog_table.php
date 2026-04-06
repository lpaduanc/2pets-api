<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vaccine_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('species'); // dog, cat
            $table->integer('doses_required')->default(1);
            $table->integer('interval_days')->nullable(); // days between doses
            $table->integer('booster_interval_days')->nullable(); // annual booster interval
            $table->text('description')->nullable();
            $table->boolean('required')->default(false); // legally required?
            $table->timestamps();

            $table->index('species');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaccine_catalog');
    }
};
