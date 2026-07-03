<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\InstallmentController;
use Illuminate\Support\Facades\Route;

// Public Routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::apiResource('accounts', App\Http\Controllers\Api\AccountController::class)->only(['update', 'destroy']);
    Route::apiResource('transactions', App\Http\Controllers\Api\TransactionController::class)->only(['update', 'destroy']);
    Route::apiResource('categories', CategoryController::class);
    // Add this line to your routes/api.php
Route::delete('installments/{id}', [App\Http\Controllers\Api\InstallmentController::class, 'destroy']);

    // Ledgers & Sharing
    Route::get('/ledgers', [LedgerController::class, 'index']);
    Route::post('/ledgers', [LedgerController::class, 'store']);
    Route::post('/ledgers/{ledger}/users', [LedgerController::class, 'addUser']);
    Route::get('ledgers/{ledger}/active-months', [\App\Http\Controllers\Api\TransactionController::class, 'activeMonths']);
    Route::get('ledgers/{ledger}/net-worth-trend', [\App\Http\Controllers\Api\TransactionController::class, 'netWorthTrend']);

    // Categories
    Route::get('/ledgers/{ledger}/categories', [CategoryController::class, 'index']);
    Route::post('/ledgers/{ledger}/categories', [CategoryController::class, 'store']);
    Route::delete('/categories/{category}', function (\App\Models\Category $category) {
        $category->delete();
        return response()->json(['message' => 'Deleted']);
    });

    // Transactions & Dashboard
    Route::get('/ledgers/{ledger}/transactions', [TransactionController::class, 'index']);
    Route::post('/ledgers/{ledger}/transactions', [TransactionController::class, 'store']);
    Route::get('/ledgers/{ledger}/summary', [ReportController::class, 'summary']);
    Route::get('/ledgers/{ledger}/accounts', [App\Http\Controllers\Api\AccountController::class, 'index']);
Route::post('/ledgers/{ledger}/accounts', [App\Http\Controllers\Api\AccountController::class, 'store']);

   // Installment Routes
    Route::get('ledgers/{ledger}/installments', [App\Http\Controllers\Api\InstallmentController::class, 'index']);
    Route::post('ledgers/{ledger}/installments', [App\Http\Controllers\Api\InstallmentController::class, 'store']);
    Route::post('installments/{installment}/pay', [App\Http\Controllers\Api\InstallmentController::class, 'pay']);

    Route::post('/accounts/reorder', [App\Http\Controllers\Api\AccountController::class, 'reorder']);
});