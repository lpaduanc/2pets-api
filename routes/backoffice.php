<?php

use App\Enums\BackofficePermission as P;
use App\Http\Controllers\Backoffice\AuthController;
use App\Http\Controllers\Backoffice\DashboardController;
use App\Http\Controllers\Backoffice\PlatformUserController;
use App\Http\Controllers\Backoffice\TeamController;
use App\Http\Controllers\ImpersonationSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 2pets Admin (backoffice)
|--------------------------------------------------------------------------
|
| Carregado por `routes/api.php` (prefixo `/api`). O catálogo de produtos continua em
| `/admin/catalog-*` (AdminMiddleware): o 2pets Admin consome aquelas rotas direto.
|
*/

Route::prefix('backoffice')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'backoffice', 'throttle:120,1'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/dashboard', DashboardController::class)->middleware('backoffice:'.P::USERS_VIEW->value);

        Route::middleware('backoffice:'.P::USERS_VIEW->value)->group(function () {
            Route::get('/users', [PlatformUserController::class, 'index']);
            Route::get('/users/{id}', [PlatformUserController::class, 'show'])->whereNumber('id');
        });

        Route::post('/users', [PlatformUserController::class, 'store'])->middleware('backoffice:'.P::USERS_CREATE->value);

        Route::middleware('backoffice:'.P::USERS_MANAGE->value)->group(function () {
            Route::put('/users/{id}', [PlatformUserController::class, 'update'])->whereNumber('id');
            Route::post('/users/{id}/suspend', [PlatformUserController::class, 'suspend'])->whereNumber('id');
            Route::post('/users/{id}/activate', [PlatformUserController::class, 'activate'])->whereNumber('id');
            Route::post('/users/{id}/verify-email', [PlatformUserController::class, 'verifyEmail'])->whereNumber('id');
            Route::post('/users/{id}/reset-password', [PlatformUserController::class, 'resetPassword'])->whereNumber('id');
        });

        Route::post('/users/{id}/impersonate', [PlatformUserController::class, 'impersonate'])
            ->whereNumber('id')
            ->middleware(['backoffice:'.P::IMPERSONATE->value, 'throttle:20,1']);

        Route::middleware('backoffice:'.P::TEAM_MANAGE->value)->group(function () {
            Route::get('/team/options', [TeamController::class, 'options']);
            Route::get('/team', [TeamController::class, 'index']);
            Route::post('/team', [TeamController::class, 'store']);
            Route::put('/team/{id}', [TeamController::class, 'update'])->whereNumber('id');
            Route::post('/team/{id}/reset-password', [TeamController::class, 'resetPassword'])->whereNumber('id');
            Route::delete('/team/{id}', [TeamController::class, 'destroy'])->whereNumber('id');
        });
    });
});

// Sessão impersonada, vista de dentro do app do tutor / 2pets Pro.
Route::prefix('auth/impersonation')->group(function () {
    Route::post('/exchange', [ImpersonationSessionController::class, 'exchange'])->middleware('throttle:20,1');

    Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
        Route::get('/users', [ImpersonationSessionController::class, 'users']);
        Route::post('/switch/{id}', [ImpersonationSessionController::class, 'switch'])->whereNumber('id');
        Route::post('/stop', [ImpersonationSessionController::class, 'stop']);
    });
});
