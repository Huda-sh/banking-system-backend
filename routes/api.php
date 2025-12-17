<?php

use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [SessionController::class, 'create']);
Route::delete('/logout', [SessionController::class, 'destroy'])->middleware('auth:sanctum');
Route::get('/me', [SessionController::class, 'me'])->middleware('auth:sanctum');