<?php

use App\Http\Controllers\SessionController;
use App\Http\Controllers\UserController;
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
