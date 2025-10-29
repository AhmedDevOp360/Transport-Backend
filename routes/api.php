<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\MoveRequestController;

Route::prefix('/auth')->group(function () {
    Route::post('/signup', [AuthController::class, 'signup']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/verify-forgot-password-otp', [AuthController::class, 'verifyForgotPasswordOtp']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('/profile')->group(function () {
    Route::get('/', [ProfileController::class, 'index']);
    Route::post('/update', [ProfileController::class, 'update']);
});

    Route::get('/move-requests', [MoveRequestController::class, 'index']);
    Route::post('/move-requests', [MoveRequestController::class, 'store']);
    Route::post('/move-requests/{id}/apply', [MoveRequestController::class, 'apply']);
    Route::post('/move-requests/{id}/status', [MoveRequestController::class, 'updateMoveRequestStatus']);
    Route::get('/move-requests/{id}/applications', [MoveRequestController::class, 'listApplications']);
    Route::get('/move-requests/{id}/applications/{application_id}', [MoveRequestController::class, 'viewApplication']);
    Route::get('/move-requests/{id}/applications/{application_id}/detail', [MoveRequestController::class, 'applicationDetail']);
    Route::post('/move-requests/{id}/applications/{application_id}', [MoveRequestController::class, 'updateApplication']);
    Route::post('/move-requests/{id}/applications/{application_id}/status', [MoveRequestController::class, 'updateApplicationStatus']);

    Route::get('/active-jobs', [MoveRequestController::class, 'activeJobs']);
});

