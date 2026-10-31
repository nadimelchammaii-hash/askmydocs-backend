<?php

use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes  (prefix: /api/v1 — see bootstrap/app.php)
|--------------------------------------------------------------------------
|
| Contract: docs/api.md. The SPA authenticates with Sanctum session cookies;
| it must call GET /sanctum/csrf-cookie before the first state-changing request.
|
*/

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('register', RegisterController::class)->middleware('throttle:6,1')->name('register');
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login');
    Route::post('logout', [SessionController::class, 'destroy'])->middleware('auth:sanctum')->name('logout');

    Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:6,1')->name('password.email');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1')->name('password.reset');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('user', [ProfileController::class, 'show'])->name('user.show');
    Route::patch('user', [ProfileController::class, 'update'])->name('user.update');
    Route::put('user/password', [ProfileController::class, 'updatePassword'])->name('user.password');

    Route::prefix('documents')->name('documents.')->controller(DocumentController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->middleware('throttle:uploads')->name('store');
        Route::get('{document}', 'show')->can('view', 'document')->name('show');
        Route::get('{document}/file', 'file')->can('view', 'document')->name('file');
        Route::get('{document}/pages/{pageNumber}', 'page')->whereNumber('pageNumber')->can('view', 'document')->name('pages.show');
        Route::post('{document}/retry', 'retry')->middleware('throttle:uploads')->can('retry', 'document')->name('retry');
        Route::delete('{document}', 'destroy')->can('delete', 'document')->name('destroy');
    });

    Route::controller(ConversationController::class)->group(function () {
        Route::post('documents/{document}/conversations', 'store')
            ->middleware('throttle:questions')->can('view', 'document')->name('conversations.store');
        Route::post('conversations/{conversation}/questions', 'ask')
            ->middleware('throttle:questions')->can('ask', 'conversation')->name('conversations.ask');
        // scopeBindings: the message must belong to this conversation, or 404.
        Route::get('conversations/{conversation}/messages/{message}', 'message')
            ->scopeBindings()->can('view', 'conversation')->name('conversations.messages.show');
    });
});
