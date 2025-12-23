<?php

use App\Http\Controllers\AccountGroupController;
use App\Http\Controllers\Api\RecurringTransactionsController;
use App\Http\Controllers\Api\ScheduledTransactionsController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\SimpleTransactionController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\Transaction\TransactionController;
use App\Http\Controllers\UserController;
use App\Models\Transaction;
use App\Services\Approval\SmallTransactionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::post('/login', [SessionController::class, 'create']);
Route::delete('/logout', [SessionController::class, 'destroy'])->middleware('auth:sanctum');
Route::get('/me', [SessionController::class, 'me'])->middleware('auth:sanctum');

// User Route
Route::controller(UserController::class)
    ->prefix('users')
    ->name('users.')
    ->middleware(['auth:sanctum', 'role:Admin,Teller,Manager'])
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{user_id}', 'show')->name('show');
        Route::post('/', 'create')->name('create');
        Route::post('/{user_id}', 'update')->name('update');
        Route::patch('/{user_id}/status', 'updateStatus')->name('updateStatus');
    });

// Account Routes
Route::controller(AccountGroupController::class)
    ->prefix('accounts')
    ->name('accounts.')
    ->middleware(['auth:sanctum', 'role:Admin,Teller,Manager'])
    ->group(function () {
        // Account Creation Data
        Route::get('/creation-data', 'getCreationData')->name('creation-data');

        // Account Groups
        Route::post('/groups', 'createGroup')->name('groups.create');
        Route::get('/groups', 'index')->name('groups.index');
        Route::get('/groups/{accountGroupId}', 'show')->name('groups.show');

        // Account Leaves
        Route::post('/leaves', 'createLeaf')->name('leaves.create');

        // State Management
        Route::patch('/{accountId}/state', 'updateState')->name('state.update');
    });
Route::controller(\App\Http\Controllers\Api\TransactionController::class)
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('transactions', 'show');
        Route::post('/transactions', 'store');
        Route::patch('transactions/{id}/status', 'updateStatus');
        Route::get('transactions/{id}', 'getTransaction');
    });



Route::middleware('auth:sanctum')->group(function () {

    //scheduled transactions
    Route::get('/scheduled-transactions', [ScheduledTransactionsController::class, 'index']);
    Route::get('/scheduled-transactions/{id}', [ScheduledTransactionsController::class, 'show']);
    Route::post('/transactions/schedule', [ScheduledTransactionsController::class, 'store']);
    Route::put('/scheduled-transactions/{id}', [ScheduledTransactionsController::class, 'update']);
    Route::delete('/scheduled-transactions/{id}', [ScheduledTransactionsController::class, 'destroy']);
    Route::post('/scheduled-transactions/{id}/retry', [ScheduledTransactionsController::class, 'retry']);

    //recurring transactions
    Route::get('/recurring-transactions', [RecurringTransactionsController::class, 'index']);
    Route::post('/transactions/recurring', [RecurringTransactionsController::class, 'store']);
    Route::patch('/recurring-transactions/{id}/toggle', [RecurringTransactionsController::class, 'toggle']);
    Route::put('/recurring-transactions/{id}', [RecurringTransactionsController::class, 'update']);
    Route::post('/recurring-transactions/{id}/terminate', [RecurringTransactionsController::class, 'terminate']);
    //    Route::get('/transactions', [RecurringTransactionsController::class, 'history'])->name('recurring.history');
});

// Ticket Routes
Route::controller(TicketController::class)
    ->prefix('tickets')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::post('/', 'store');
        Route::get('/', 'index');
        Route::patch('/{id}/status', 'updateStatus')->middleware('role:Admin,Manager,Teller');
    });
