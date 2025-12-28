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
use App\Http\Controllers\Api\Public\SearchController;
use Illuminate\Support\Facades\Route;

// Public routes - Professional Search & Discovery
Route::prefix('public')->group(function () {
    Route::get('/search', [SearchController::class, 'search']);
    Route::get('/nearby', [SearchController::class, 'nearby']);
    Route::get('/categories', [SearchController::class, 'categories']);
    Route::get('/professionals/{id}', [\App\Http\Controllers\Api\Public\ProfessionalController::class, 'show']);
    
    // Pet Digital Card (public access)
    Route::get('/pet-card/{publicId}', [\App\Http\Controllers\Api\Public\PetCardController::class, 'show']);
    
    // Booking endpoints (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/booking/availability', [\App\Http\Controllers\Api\Public\BookingController::class, 'availability']);
        Route::post('/booking', [\App\Http\Controllers\Api\Public\BookingController::class, 'book']);
        Route::post('/booking/{id}/cancel', [\App\Http\Controllers\Api\Public\BookingController::class, 'cancel']);
        Route::post('/booking/{id}/reschedule', [\App\Http\Controllers\Api\Public\BookingController::class, 'reschedule']);
        Route::post('/waitlist', [\App\Http\Controllers\Api\Public\BookingController::class, 'joinWaitlist']);
    });
});

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
Route::post('/verify-email/{token}', [\App\Http\Controllers\EmailVerificationController::class, 'verify']);
Route::post('/resend-verification', [\App\Http\Controllers\EmailVerificationController::class, 'resend']);

// Public Search & Discovery
Route::prefix('public')->group(function () {
    Route::get('/search', [\App\Http\Controllers\Api\Public\SearchController::class, 'search']);
    Route::get('/featured', [\App\Http\Controllers\Api\Public\SearchController::class, 'featured']);
    Route::get('/professionals/{id}', [\App\Http\Controllers\Api\Public\ProfessionalController::class, 'show']);
});

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

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
        Route::get('/unread', [\App\Http\Controllers\Api\NotificationController::class, 'unread']);
        Route::post('/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'markAllAsRead']);
        Route::get('/preferences', [\App\Http\Controllers\Api\NotificationController::class, 'getPreferences']);
        Route::put('/preferences', [\App\Http\Controllers\Api\NotificationController::class, 'updatePreferences']);
        Route::post('/devices/register', [\App\Http\Controllers\Api\NotificationController::class, 'registerDevice']);
        Route::post('/devices/unregister', [\App\Http\Controllers\Api\NotificationController::class, 'unregisterDevice']);
    });

    // Payments
    Route::prefix('payments')->group(function () {
        Route::post('/', [\App\Http\Controllers\Api\PaymentController::class, 'create']);
        Route::get('/{id}', [\App\Http\Controllers\Api\PaymentController::class, 'show']);
        Route::post('/{id}/refund', [\App\Http\Controllers\Api\PaymentController::class, 'refund']);
    });

    // Messages
    Route::prefix('messages')->group(function () {
        Route::get('/conversations', [\App\Http\Controllers\Api\MessageController::class, 'conversations']);
        Route::get('/conversations/{id}', [\App\Http\Controllers\Api\MessageController::class, 'show']);
        Route::post('/send', [\App\Http\Controllers\Api\MessageController::class, 'send']);
        Route::post('/conversations/{id}/read', [\App\Http\Controllers\Api\MessageController::class, 'markAsRead']);
        Route::get('/unread-count', [\App\Http\Controllers\Api\MessageController::class, 'unreadCount']);
    });

    // Reviews & Ratings
    Route::prefix('reviews')->group(function () {
        Route::get('/professional/{professionalId}', [\App\Http\Controllers\Api\ReviewController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\ReviewController::class, 'store']);
        Route::post('/{id}/response', [\App\Http\Controllers\Api\ReviewController::class, 'addResponse']);
        Route::post('/{id}/flag', [\App\Http\Controllers\Api\ReviewController::class, 'flag']);
        Route::post('/{id}/helpful', [\App\Http\Controllers\Api\ReviewController::class, 'toggleHelpful']);
        Route::post('/{id}/moderate', [\App\Http\Controllers\Api\ReviewController::class, 'moderate'])->middleware('admin');
    });

    // Health Reminders
    Route::prefix('reminders')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ReminderController::class, 'index']);
        Route::get('/pending', [\App\Http\Controllers\Api\ReminderController::class, 'pending']);
        Route::post('/{id}/snooze', [\App\Http\Controllers\Api\ReminderController::class, 'snooze']);
        Route::post('/{id}/dismiss', [\App\Http\Controllers\Api\ReminderController::class, 'dismiss']);
        Route::post('/{id}/complete', [\App\Http\Controllers\Api\ReminderController::class, 'complete']);
        Route::get('/preferences', [\App\Http\Controllers\Api\ReminderController::class, 'getPreferences']);
        Route::put('/preferences', [\App\Http\Controllers\Api\ReminderController::class, 'updatePreferences']);
    });

    // Pet Cards
    Route::prefix('pet-card')->group(function () {
        Route::get('/{petId}/qr-code', [\App\Http\Controllers\Api\PetCardController::class, 'getQRCode']);
        Route::post('/{petId}/mark-lost', [\App\Http\Controllers\Api\PetCardController::class, 'markLost']);
        Route::post('/{petId}/mark-found', [\App\Http\Controllers\Api\PetCardController::class, 'markFound']);
    });

    // Reports & PDF Downloads
    Route::prefix('reports')->group(function () {
        Route::get('/invoice/{invoiceId}/pdf', [\App\Http\Controllers\Api\ReportController::class, 'downloadInvoice']);
        Route::get('/prescription/{prescriptionId}/pdf', [\App\Http\Controllers\Api\ReportController::class, 'downloadPrescription']);
        Route::get('/medical-history/{petId}/pdf', [\App\Http\Controllers\Api\ReportController::class, 'downloadMedicalHistory']);
        Route::get('/revenue', [\App\Http\Controllers\Api\ReportController::class, 'getRevenueReport']);
    });

    // Lab Exams & Results
    Route::prefix('exams')->group(function () {
        Route::get('/pet/{petId}', [\App\Http\Controllers\Api\ExamController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\ExamController::class, 'store']);
        Route::post('/{examId}/results', [\App\Http\Controllers\Api\ExamController::class, 'addResults']);
        Route::post('/{examId}/images', [\App\Http\Controllers\Api\ExamController::class, 'addImages']);
        Route::get('/pet/{petId}/history/{parameter}', [\App\Http\Controllers\Api\ExamController::class, 'getHistory']);
    });

    // Subscriptions
    Route::prefix('subscriptions')->group(function () {
        Route::get('/plans', [\App\Http\Controllers\Api\SubscriptionController::class, 'plans']);
        Route::get('/current', [\App\Http\Controllers\Api\SubscriptionController::class, 'current']);
        Route::post('/subscribe', [\App\Http\Controllers\Api\SubscriptionController::class, 'subscribe']);
        Route::post('/upgrade', [\App\Http\Controllers\Api\SubscriptionController::class, 'upgrade']);
        Route::post('/cancel', [\App\Http\Controllers\Api\SubscriptionController::class, 'cancel']);
        Route::get('/check-feature/{feature}', [\App\Http\Controllers\Api\SubscriptionController::class, 'checkFeature']);
        Route::get('/check-usage/{feature}', [\App\Http\Controllers\Api\SubscriptionController::class, 'checkUsage']);
    });

    // Video Consultations
    Route::prefix('video-consultations')->group(function () {
        Route::post('/', [\App\Http\Controllers\Api\VideoConsultationController::class, 'create']);
        Route::post('/{id}/join', [\App\Http\Controllers\Api\VideoConsultationController::class, 'join']);
        Route::post('/{id}/start', [\App\Http\Controllers\Api\VideoConsultationController::class, 'start']);
        Route::post('/{id}/end', [\App\Http\Controllers\Api\VideoConsultationController::class, 'end']);
        Route::post('/recordings/{recordingId}/consent', [\App\Http\Controllers\Api\VideoConsultationController::class, 'grantRecordingConsent']);
        Route::delete('/recordings/{recordingId}/consent', [\App\Http\Controllers\Api\VideoConsultationController::class, 'revokeRecordingConsent']);
    });

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
