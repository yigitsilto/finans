<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AgingController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CashflowController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ReceivableController;
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

        Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');

        Route::get('/receivables/create', [ReceivableController::class, 'create'])->name('receivables.create');
        Route::post('/receivables', [ReceivableController::class, 'store'])->name('receivables.store');
        Route::get('/receivables/{receivable}/edit', [ReceivableController::class, 'edit'])->name('receivables.edit');
        Route::put('/receivables/{receivable}', [ReceivableController::class, 'update'])->name('receivables.update');
        Route::post('/receivables/{receivable}/collect', [ReceivableController::class, 'collect'])->name('receivables.collect');
        Route::post('/receivables/{receivable}/cancel', [ReceivableController::class, 'cancel'])->name('receivables.cancel');
    });

    // Görüntüleme (tüm aktif roller).
    Route::middleware('can:view-finance')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/api/dashboard', [DashboardController::class, 'api'])->name('api.dashboard');
        Route::get('/reports/analysis', [AnalysisController::class, 'index'])->name('analysis.index');
        Route::get('/reports/exports', [ExportController::class, 'index'])->name('exports.index');
        // Yetki controller içinde rapora göre kontrol edilir (denetim kayıtları yalnızca Admin).
        Route::get('/reports/exports/{report}.{format}', [ExportController::class, 'download'])
            ->where(['report' => '[a-z]+', 'format' => 'xlsx|pdf'])->middleware('throttle:30,1')->name('exports.download');

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
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::get('/receivables', [ReceivableController::class, 'index'])->name('receivables.index');
        Route::get('/receivables/{receivable}', [ReceivableController::class, 'show'])->name('receivables.show');
        Route::get('/aging', [AgingController::class, 'index'])->name('aging.index');
        Route::get('/reports/cashflow', [CashflowController::class, 'index'])->name('cashflow.index');
    });

    Route::middleware('can:view-audit')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit.show');
    });

    Route::middleware('can:manage-users')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
    });
});
