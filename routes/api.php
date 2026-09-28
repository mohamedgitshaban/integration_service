<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\Instructor;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\Student;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('login', [AuthController::class, 'login'])->name('login');
    });

    Route::get('plans', PlanController::class)->name('plans.index');
    Route::apiResource('courses', CourseController::class)->only(['index', 'show']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        Route::middleware('role:student')->prefix('student')->name('student.')->group(function () {
            Route::apiResource('subscriptions', Student\SubscriptionController::class)->only(['index', 'store', 'show']);
            Route::post('subscriptions/{subscription}/courses', [Student\SubscriptionCourseController::class, 'store'])
                ->name('subscriptions.courses.store');
            Route::delete('subscriptions/{subscription}/courses/{course}', [Student\SubscriptionCourseController::class, 'destroy'])
                ->name('subscriptions.courses.destroy');
            Route::post('subscriptions/{subscription}/refund', [Student\SubscriptionRefundController::class, 'store'])
                ->name('subscriptions.refund');
        });

        Route::middleware('role:instructor')->prefix('instructor')->name('instructor.')->group(function () {
            Route::get('balance', [Instructor\BalanceController::class, 'show'])->name('balance.show');
            Route::get('ledger', [Instructor\LedgerController::class, 'index'])->name('ledger.index');
            Route::apiResource('payouts', Instructor\PayoutController::class)->only(['index', 'show']);
            Route::post('withdrawals', [Instructor\WithdrawalController::class, 'store'])
                ->middleware('throttle:5,1')
                ->name('withdrawals.store');
            Route::get('payout-details', [Instructor\PayoutDetailsController::class, 'show'])->name('payout-details.show');
            Route::put('payout-details', [Instructor\PayoutDetailsController::class, 'update'])->name('payout-details.update');
            Route::apiResource('courses', Instructor\CourseController::class)->only(['index', 'store', 'update']);
        });
    });
});
