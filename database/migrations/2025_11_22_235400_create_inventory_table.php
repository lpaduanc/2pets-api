<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained('users')->onDelete('cascade');
            $table->string('item_name');
            $table->enum('category', ['medication', 'vaccine', 'supply', 'equipment']);
            $table->integer('quantity')->default(0);
            $table->string('unit')->nullable();
            $table->integer('min_quantity')->default(5);
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->decimal('selling_price', 10, 2)->nullable();
            $table->string('supplier')->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
