<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

// Sprint 2'de auth middleware ile korunacak.
Route::view('/', 'dashboard')->name('dashboard');
