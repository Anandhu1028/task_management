<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PwaAuthController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
});

Route::middleware(['guest', 'pwa.nostore'])->prefix('pwa')->name('pwa.')->group(function (): void {
    Route::get('/login', [PwaAuthController::class, 'create'])->name('login');
    Route::post('/login', [PwaAuthController::class, 'store'])->name('login.store');
});

Route::post('/pwa/logout', [PwaAuthController::class, 'destroy'])
    ->middleware(['auth', 'pwa.nostore'])
    ->name('pwa.logout');

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
