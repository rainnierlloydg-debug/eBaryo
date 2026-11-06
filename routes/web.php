<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\ResidentSettingsController;
use App\Http\Controllers\PasswordResetController;

Route::view('/login', 'app')->name('login');
Route::get('/reset-password', fn () => response()->view('app')->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store'))->name('password.reset');
Route::view('/{path?}', 'app')->where('path', 'login|register|forgot-password|dashboard|request-document|request-history|notifications|profile|settings|admin(?:/.*)?');

Route::prefix('api')->group(function (): void {
    Route::post('/forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:5,1');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:15,1');
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');

    Route::middleware(['auth', 'active'])->group(function (): void {
        Route::get('/me', fn (\Illuminate\Http\Request $request) => array_merge($request->user()->only(['id', 'name', 'email', 'address', 'phone', 'role', 'account_status', 'verification_status']), ['profile_photo_url' => $request->user()->profile_photo_url]));
        Route::get('/profile', [ResidentSettingsController::class, 'profile']);
        Route::get('/profile-photo', [ResidentSettingsController::class, 'profilePhoto'])->name('profile.photo');
        Route::get('/dashboard', [RequestController::class, 'dashboard']);
        Route::patch('/profile', [ResidentSettingsController::class, 'updateProfile']);
        Route::put('/password', [ResidentSettingsController::class, 'changePassword']);
        Route::patch('/notification-preferences', [ResidentSettingsController::class, 'updatePreferences']);
        Route::get('/documents', [RequestController::class, 'documents']);
        Route::get('/documents/{document}/template', [RequestController::class, 'documentTemplate']);
        Route::get('/requests', [RequestController::class, 'index']);
        Route::post('/requests', [RequestController::class, 'store']);
        Route::patch('/requests/{documentRequest}/cancel', [RequestController::class, 'cancel']);
        Route::post('/requests/{documentRequest}/resubmit', [RequestController::class, 'resubmit']);
        Route::get('/notifications', [RequestController::class, 'notifications']);
        Route::get('/notifications/unread-count', [RequestController::class, 'unreadNotificationCount']);
        Route::get('/notifications/{notification}/documents/{index}', [RequestController::class, 'notificationDocument'])->whereNumber('index');
        Route::patch('/notifications/read-all', [RequestController::class, 'markAllNotificationsRead']);
        Route::patch('/notifications/{notification}/read', [RequestController::class, 'markNotificationRead']);
        Route::get('/uploads/{uploadId}', [RequestController::class, 'upload']);

        Route::prefix('admin')->middleware('admin')->group(function (): void {
            Route::get('/dashboard', [AdminController::class, 'dashboard']);
            Route::get('/navigation-counts', [AdminController::class, 'navigationCounts']);
            Route::get('/requests', [AdminController::class, 'requests']);
            Route::get('/requests/{documentRequest}', [AdminController::class, 'showRequest']);
            Route::get('/requests/{documentRequest}/document-draft', [AdminController::class, 'documentDraft']);
            Route::get('/requests/{documentRequest}/word-template', [AdminController::class, 'documentWordTemplate']);
            Route::put('/requests/{documentRequest}/document-draft', [AdminController::class, 'saveDocumentDraft']);
            Route::patch('/requests/{documentRequest}/status', [AdminController::class, 'updateStatus']);
            Route::patch('/requests/{documentRequest}/updates', [AdminController::class, 'updateRequest']);
            Route::post('/requests/{documentRequest}/payment', [AdminController::class, 'recordPayment']);
            Route::post('/requests/{documentRequest}/generate', [AdminController::class, 'generateDocument']);
            Route::get('/requests/{documentRequest}/generated-document', [AdminController::class, 'generatedDocument']);
            Route::get('/documents', [AdminController::class, 'documents']);
            Route::post('/documents', [AdminController::class, 'storeDocument']);
            Route::post('/documents/{document}/template', [AdminController::class, 'uploadDocumentTemplate']);
            Route::get('/documents/{document}/template-draft', [AdminController::class, 'templateDraft']);
            Route::put('/documents/{document}/template-draft', [AdminController::class, 'saveTemplateDraft']);
            Route::patch('/documents/{document}', [AdminController::class, 'updateDocument']);
            Route::delete('/documents/{document}', [AdminController::class, 'deleteDocument']);
            Route::get('/residents', [AdminController::class, 'residents']);
            Route::get('/residents/{resident}/national-id', [AdminController::class, 'residentNationalId']);
            Route::post('/residents/{resident}/approve-registration', [AdminController::class, 'approveRegistration']);
            Route::post('/residents/{resident}/reject-registration', [AdminController::class, 'rejectRegistration']);
            Route::post('/notifications', [AdminController::class, 'sendNotification']);
            Route::get('/notifications', [AdminController::class, 'sentNotifications']);
            Route::get('/reports', [AdminController::class, 'reports']);
            Route::get('/reports/{documentRequest}', [AdminController::class, 'reportContent'])->whereNumber('documentRequest');
            Route::patch('/reports/read-all', [AdminController::class, 'markReportsRead']);
        });
    });
});
