<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo_url')->nullable();
            $table->text('description')->nullable();
            $table->string('api_endpoint')->nullable();
            $table->string('api_key')->nullable();
            $table->json('coverage_types')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pet_insurances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('insurance_provider_id')->constrained();
            $table->string('policy_number')->unique();
            $table->string('coverage_type'); // basic, premium, complete
            $table->decimal('monthly_premium', 10, 2);
            $table->decimal('deductible', 10, 2);
            $table->decimal('annual_limit', 10, 2)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->enum('status', ['active', 'pending', 'cancelled', 'expired'])->default('pending');
            $table->json('coverage_details')->nullable(); // What's covered
            $table->json('exclusions')->nullable(); // What's NOT covered
            $table->timestamps();

            $table->index(['pet_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('insurance_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_insurance_id')->constrained()->onDelete('cascade');
            $table->foreignId('appointment_id')->nullable()->constrained();
            $table->string('claim_number')->unique();
            $table->enum('claim_type', ['consultation', 'surgery', 'medication', 'emergency', 'hospitalization', 'exam']);
            $table->text('description');
            $table->decimal('claimed_amount', 10, 2);
            $table->decimal('approved_amount', 10, 2)->nullable();
            $table->decimal('reimbursed_amount', 10, 2)->nullable();
            $table->enum('status', ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'paid'])->default('draft');
            $table->json('documents')->nullable(); // Receipt, medical report, etc.
            $table->text('rejection_reason')->nullable();
            $table->date('incident_date');
            $table->date('submitted_at')->nullable();
            $table->date('processed_at')->nullable();
            $table->timestamps();

            $table->index(['pet_insurance_id', 'status']);
            $table->index('claim_number');
        });

        Schema::create('insurance_pre_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_insurance_id')->constrained()->onDelete('cascade');
            $table->foreignId('appointment_id')->nullable()->constrained();
            $table->string('authorization_number')->unique();
            $table->string('procedure_type');
            $table->text('procedure_description');
            $table->decimal('estimated_cost', 10, 2);
            $table->decimal('approved_amount', 10, 2)->nullable();
            $table->enum('status', ['pending', 'approved', 'denied', 'expired'])->default('pending');
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['pet_insurance_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_pre_authorizations');
        Schema::dropIfExists('insurance_claims');
        Schema::dropIfExists('pet_insurances');
        Schema::dropIfExists('insurance_providers');
    }
};

