<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Yazma işlemleri (Admin + Finance). Sabit yollar parametreli yollardan önce tanımlanır.
    Route::middleware('can:write-finance')->group(function () {
        Route::get('/accounts/create', [AccountController::class, 'create'])->name('accounts.create');
        Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::get('/accounts/{account}/edit', [AccountController::class, 'edit'])->name('accounts.edit');
        Route::put('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');

        Route::get('/transactions/create', [TransactionController::class, 'create'])->name('transactions.create');
        Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');
        Route::get('/transactions/{transaction}/edit', [TransactionController::class, 'edit'])->name('transactions.edit');
        Route::patch('/transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
        Route::post('/transactions/{transaction}/reverse', [TransactionController::class, 'reverse'])->name('transactions.reverse');

        Route::post('/pos/settle-due', [PosController::class, 'settleDue'])->name('pos.settle-due');
        Route::post('/pos/{transaction}/settle', [PosController::class, 'settle'])->name('pos.settle');

        Route::get('/partners/create', [PartnerController::class, 'create'])->name('partners.create');
        Route::post('/partners', [PartnerController::class, 'store'])->name('partners.store');
        Route::get('/partners/{partner}/edit', [PartnerController::class, 'edit'])->name('partners.edit');
        Route::put('/partners/{partner}', [PartnerController::class, 'update'])->name('partners.update');
        Route::post('/partners/{partner}/adjustments', [PartnerController::class, 'storeAdjustment'])->name('partners.adjustments.store');
        Route::post('/partner-adjustments/{adjustment}/reverse', [PartnerController::class, 'reverseAdjustment'])->name('partners.adjustments.reverse');

        Route::get('/loans/create', [LoanController::class, 'create'])->name('loans.create');
        Route::post('/loans', [LoanController::class, 'store'])->name('loans.store');
        Route::post('/loan-installments/{installment}/pay', [LoanController::class, 'pay'])->name('loans.installments.pay');
        Route::post('/loan-installments/{installment}/reverse', [LoanController::class, 'reversePayment'])->name('loans.installments.reverse');
        Route::patch('/loan-installments/{installment}', [LoanController::class, 'updateInstallment'])->name('loans.installments.update');

        Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::get('/expenses/{expense}/edit', [ExpenseController::class, 'edit'])->name('expenses.edit');
        Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::post('/expenses/{expense}/pay', [ExpenseController::class, 'pay'])->name('expenses.pay');
    });

    // Görüntüleme (tüm aktif roller).
    Route::middleware('can:view-finance')->group(function () {
        Route::view('/', 'dashboard')->name('dashboard');

        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
        Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
        Route::get('/partners', [PartnerController::class, 'index'])->name('partners.index');
        Route::get('/partners/{partner}', [PartnerController::class, 'show'])->name('partners.show');
        Route::get('/loans', [LoanController::class, 'index'])->name('loans.index');
        Route::get('/loans/{loan}', [LoanController::class, 'show'])->name('loans.show');
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    });

    Route::middleware('can:manage-users')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
    });
});
