<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\PetController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProfessionalDashboardController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\MedicalRecordController;
use App\Http\Controllers\Api\VaccinationController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\RegistrationDraftController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
Route::post('/verify-email/{token}', [\App\Http\Controllers\EmailVerificationController::class, 'verify']);
Route::post('/resend-verification', [\App\Http\Controllers\EmailVerificationController::class, 'resend']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Registration Completion
    Route::post('/register/complete-tutor', [\App\Http\Controllers\RegistrationCompletionController::class, 'completeTutor']);
    Route::post('/register/complete-professional', [\App\Http\Controllers\RegistrationCompletionController::class, 'completeProfessional']);
    Route::post('/register/complete-company', [\App\Http\Controllers\RegistrationCompletionController::class, 'completeCompany']);

    // Registration Draft Auto-Save
    Route::post('/register/draft/professional', [RegistrationDraftController::class, 'saveProfessionalDraft']);
    Route::get('/register/draft/professional', [RegistrationDraftController::class, 'loadProfessionalDraft']);
    Route::delete('/register/draft/professional', [RegistrationDraftController::class, 'deleteProfessionalDraft']);

    Route::post('/register/draft/company', [RegistrationDraftController::class, 'saveCompanyDraft']);
    Route::get('/register/draft/company', [RegistrationDraftController::class, 'loadCompanyDraft']);
    Route::delete('/register/draft/company', [RegistrationDraftController::class, 'deleteCompanyDraft']);

    Route::post('/register/draft/tutor', [RegistrationDraftController::class, 'saveTutorDraft']);
    Route::get('/register/draft/tutor', [RegistrationDraftController::class, 'loadTutorDraft']);
    Route::delete('/register/draft/tutor', [RegistrationDraftController::class, 'deleteTutorDraft']);

    // Documents
    Route::post('/documents/upload', [\App\Http\Controllers\DocumentController::class, 'upload']);
    Route::delete('/documents/{id}', [\App\Http\Controllers\DocumentController::class, 'destroy']);

    // User profile routes
    Route::get('/profile', [UserController::class, 'profile']);
    Route::put('/profile', [UserController::class, 'updateProfile']);

    // Dashboard stats for tutor
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Pet routes
    Route::apiResource('pets', PetController::class);

    // Professional/Medical routes
    Route::prefix('professional')->group(function () {
        // Appointments
        Route::get('appointments/today', [AppointmentController::class, 'today']);
        Route::get('appointments/upcoming', [AppointmentController::class, 'upcoming']);
        Route::apiResource('appointments', AppointmentController::class);

        // Medical Records
        Route::apiResource('medical-records', MedicalRecordController::class);

        // Vaccinations
        Route::get('vaccinations/upcoming', [VaccinationController::class, 'upcoming']);
        Route::apiResource('vaccinations', VaccinationController::class);

        // Prescriptions
        Route::get('prescriptions/valid', [PrescriptionController::class, 'valid']);
        Route::apiResource('prescriptions', PrescriptionController::class);

        // Hospitalizations
        Route::apiResource('hospitalizations', \App\Http\Controllers\Api\HospitalizationController::class);

        // Surgeries
        Route::apiResource('surgeries', \App\Http\Controllers\Api\SurgeryController::class);

        // Invoices
        Route::apiResource('invoices', \App\Http\Controllers\Api\InvoiceController::class);

        // Services
        Route::apiResource('services', \App\Http\Controllers\Api\ServiceController::class);

        // Inventory
        Route::apiResource('inventory', \App\Http\Controllers\Api\InventoryController::class);

        // Clients
        Route::apiResource('clients', \App\Http\Controllers\Api\ProfessionalClientController::class);
        Route::get('clients/{id}/pets', [\App\Http\Controllers\Api\ProfessionalClientController::class, 'pets']);

        // Professional Dashboard Stats
        Route::get('dashboard/stats', [ProfessionalDashboardController::class, 'stats']);
    });

    // AI Guardian Route
    Route::post('/ai/analyze', [\App\Http\Controllers\Api\AiController::class, 'analyze']);

    // AI Business Insight Route
    Route::post('/ai/business-analyze', [\App\Http\Controllers\Api\AiBusinessController::class, 'analyze']);

    // AI Business Insights Dashboard
    Route::get('/professional/ai/insights', [\App\Http\Controllers\Api\AiBusinessInsightsController::class, 'generateInsights']);

    // Admin Routes
    Route::prefix('admin')->middleware(\App\Http\Middleware\AdminMiddleware::class)->group(function () {
        // Dashboard
        Route::get('/dashboard/stats', [\App\Http\Controllers\AdminController::class, 'stats']);

        // Company Management
        Route::get('/companies/pending', [\App\Http\Controllers\AdminController::class, 'pendingCompanies']);
        Route::post('/companies/{id}/approve', [\App\Http\Controllers\AdminController::class, 'approveCompany']);
        Route::post('/companies/{id}/reject', [\App\Http\Controllers\AdminController::class, 'rejectCompany']);

        // Document Verification
        Route::get('/documents/pending', [\App\Http\Controllers\AdminController::class, 'pendingDocuments']);
        Route::post('/documents/{id}/verify', [\App\Http\Controllers\AdminController::class, 'verifyDocument']);
        Route::post('/documents/{id}/reject', [\App\Http\Controllers\AdminController::class, 'rejectDocument']);

        // User Management
        Route::get('/users', [\App\Http\Controllers\AdminController::class, 'listUsers']);
        Route::get('/users/{id}', [\App\Http\Controllers\AdminController::class, 'showUser']);
        Route::put('/users/{id}', [\App\Http\Controllers\AdminController::class, 'updateUser']);
        Route::post('/users/{id}/suspend', [\App\Http\Controllers\AdminController::class, 'suspendUser']);
        Route::post('/users/{id}/activate', [\App\Http\Controllers\AdminController::class, 'activateUser']);
    });
});
