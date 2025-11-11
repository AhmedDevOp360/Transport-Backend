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
    Route::post('/move-requests/{id}/assign', [MoveRequestController::class, 'assignDriver']);
    Route::get('/move-requests/{id}/applications', [MoveRequestController::class, 'listApplications']);
    Route::get('/move-requests/{id}/applications/{application_id}', [MoveRequestController::class, 'viewApplication']);
    Route::get('/move-requests/{id}/applications/{application_id}/detail', [MoveRequestController::class, 'applicationDetail']);
    Route::post('/move-requests/{id}/applications/{application_id}', [MoveRequestController::class, 'updateApplication']);
    Route::post('/move-requests/{id}/applications/{application_id}/status', [MoveRequestController::class, 'updateApplicationStatus']);

    Route::get('/active-jobs', [MoveRequestController::class, 'activeJobs']);
    Route::get('/active-moves', [MoveRequestController::class, 'activeMoves']);
    Route::get('/move-history', [MoveRequestController::class, 'moveHistory']);

    // Driver/Team Management Routes
    Route::get('/drivers', [\App\Http\Controllers\DriverController::class, 'index']);
    Route::post('/drivers', [\App\Http\Controllers\DriverController::class, 'store']);
    Route::get('/drivers/{id}', [\App\Http\Controllers\DriverController::class, 'show']);
    Route::post('/drivers/{id}', [\App\Http\Controllers\DriverController::class, 'update']);
    Route::delete('/drivers/{id}', [\App\Http\Controllers\DriverController::class, 'destroy']);
    Route::post('/drivers/{id}/status', [\App\Http\Controllers\DriverController::class, 'updateStatus']);
    Route::post('/drivers/{id}/assign-vehicle', [\App\Http\Controllers\DriverController::class, 'assignVehicle']);
    Route::post('/drivers/{id}/unassign-vehicle', [\App\Http\Controllers\DriverController::class, 'unassignVehicle']);
    Route::get('/drivers-alerts', [\App\Http\Controllers\DriverController::class, 'getAlerts']);
    Route::get('/drivers-performance', [\App\Http\Controllers\DriverController::class, 'getPerformance']);

    // Vehicle Management Routes
    Route::get('/vehicles', [\App\Http\Controllers\VehicleController::class, 'index']);
    Route::post('/vehicles', [\App\Http\Controllers\VehicleController::class, 'store']);
    Route::get('/vehicles/{id}', [\App\Http\Controllers\VehicleController::class, 'show']);
    Route::post('/vehicles/{id}', [\App\Http\Controllers\VehicleController::class, 'update']);
    Route::delete('/vehicles/{id}', [\App\Http\Controllers\VehicleController::class, 'destroy']);
    Route::post('/vehicles/{id}/status', [\App\Http\Controllers\VehicleController::class, 'updateStatus']);
    Route::get('/vehicles-alerts', [\App\Http\Controllers\VehicleController::class, 'getAlerts']);
    Route::get('/vehicles-performance', [\App\Http\Controllers\VehicleController::class, 'getPerformance']);
});

