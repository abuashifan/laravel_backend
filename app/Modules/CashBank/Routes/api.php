<?php

use App\Modules\CashBank\Controllers\BankReconciliationController;
use App\Modules\CashBank\Controllers\BankTransferController;
use App\Modules\CashBank\Controllers\CashBankAccountController;
use App\Modules\CashBank\Controllers\CashBankReportController;
use App\Modules\CashBank\Controllers\CashPaymentController;
use App\Modules\CashBank\Controllers\CashReceiptController;
use App\Modules\CashBank\Controllers\WorkspaceDisbursementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->prefix('cash-bank')->group(function () {
    Route::get('/accounts', [CashBankAccountController::class, 'index'])
        ->middleware('permission:cash_bank.receipts.view|cash_bank.payments.view|cash_bank.transfers.view|cash_bank.reconciliations.view');

    Route::get('/cash-receipts', [CashReceiptController::class, 'index'])
        ->middleware('permission:cash_bank.receipts.view');
    // Harus sebelum `/cash-receipts/{id}` supaya "adjacent" tidak tertangkap sebagai id.
    Route::get('/cash-receipts/adjacent', [CashReceiptController::class, 'adjacent'])
        ->middleware('permission:cash_bank.receipts.view');
    Route::post('/cash-receipts', [CashReceiptController::class, 'store'])
        ->middleware('permission:cash_bank.receipts.create');
    Route::get('/cash-receipts/{id}', [CashReceiptController::class, 'show'])
        ->middleware('permission:cash_bank.receipts.view');
    Route::patch('/cash-receipts/{id}/post', [CashReceiptController::class, 'post'])
        ->middleware('permission:cash_bank.receipts.approve');
    Route::patch('/cash-receipts/{id}/void', [CashReceiptController::class, 'void'])
        ->middleware('permission:cash_bank.receipts.delete');
    Route::patch('/cash-receipts/{id}', [CashReceiptController::class, 'update'])
        ->middleware('permission:cash_bank.receipts.edit');
    Route::delete('/cash-receipts/{id}', [CashReceiptController::class, 'destroy'])
        ->middleware('permission:cash_bank.receipts.delete');

    Route::prefix('workspace-disbursements')->group(function () {
        $view = 'permission:cash_bank.workspace_disbursements.view';
        $create = 'permission:cash_bank.workspace_disbursements.create';
        $approve = 'permission:cash_bank.workspace_disbursements.approve';
        Route::get('/', [WorkspaceDisbursementController::class, 'index'])->middleware($view);
        Route::get('/options', [WorkspaceDisbursementController::class, 'options'])
            ->middleware('permission:cash_bank.workspace_disbursements.create|cash_bank.workspace_disbursements.approve');
        Route::get('/{id}', [WorkspaceDisbursementController::class, 'show'])->whereNumber('id')->middleware($view);
        Route::post('/{id}/tranches', [WorkspaceDisbursementController::class, 'storeTranche'])->whereNumber('id')->middleware($create);
        Route::put('/{id}/tranches/{tranche}', [WorkspaceDisbursementController::class, 'updateTranche'])->whereNumber(['id', 'tranche'])->middleware($create);
        Route::post('/{id}/tranches/{tranche}/cancel', [WorkspaceDisbursementController::class, 'cancelTranche'])->whereNumber(['id', 'tranche'])->middleware($create);
        Route::post('/{id}/tranches/{tranche}/qc/reopen', [WorkspaceDisbursementController::class, 'reopen'])->whereNumber(['id', 'tranche'])->middleware($approve);
        Route::post('/{id}/tranches/{tranche}/qc/{decision}', [WorkspaceDisbursementController::class, 'review'])
            ->whereNumber(['id', 'tranche'])->whereIn('decision', ['approve', 'reject'])->middleware($approve);
        Route::post('/{id}/tranches/{tranche}/settle', [WorkspaceDisbursementController::class, 'settle'])->whereNumber(['id', 'tranche'])->middleware($approve);
        Route::post('/{id}/tranches/{tranche}/settlement/cancel', [WorkspaceDisbursementController::class, 'cancelSettlement'])->whereNumber(['id', 'tranche'])->middleware($approve);
    });

    Route::get('/cash-payments', [CashPaymentController::class, 'index'])
        ->middleware('permission:cash_bank.payments.view');
    Route::get('/cash-payments/adjacent', [CashPaymentController::class, 'adjacent'])
        ->middleware('permission:cash_bank.payments.view');
    Route::post('/cash-payments', [CashPaymentController::class, 'store'])
        ->middleware('permission:cash_bank.payments.create');
    Route::get('/cash-payments/{id}', [CashPaymentController::class, 'show'])
        ->middleware('permission:cash_bank.payments.view');
    Route::patch('/cash-payments/{id}/post', [CashPaymentController::class, 'post'])
        ->middleware('permission:cash_bank.payments.approve');
    Route::patch('/cash-payments/{id}/void', [CashPaymentController::class, 'void'])
        ->middleware('permission:cash_bank.payments.delete');
    Route::patch('/cash-payments/{id}', [CashPaymentController::class, 'update'])
        ->middleware('permission:cash_bank.payments.edit');
    Route::delete('/cash-payments/{id}', [CashPaymentController::class, 'destroy'])
        ->middleware('permission:cash_bank.payments.delete');

    Route::get('/bank-transfers', [BankTransferController::class, 'index'])
        ->middleware('permission:cash_bank.transfers.view');
    Route::get('/bank-transfers/adjacent', [BankTransferController::class, 'adjacent'])
        ->middleware('permission:cash_bank.transfers.view');
    Route::post('/bank-transfers', [BankTransferController::class, 'store'])
        ->middleware('permission:cash_bank.transfers.create');
    Route::get('/bank-transfers/{id}', [BankTransferController::class, 'show'])
        ->middleware('permission:cash_bank.transfers.view');
    Route::patch('/bank-transfers/{id}/post', [BankTransferController::class, 'post'])
        ->middleware('permission:cash_bank.transfers.approve');
    Route::patch('/bank-transfers/{id}/void', [BankTransferController::class, 'void'])
        ->middleware('permission:cash_bank.transfers.delete');
    Route::patch('/bank-transfers/{id}', [BankTransferController::class, 'update'])->middleware('permission:cash_bank.transfers.edit');
    Route::delete('/bank-transfers/{id}', [BankTransferController::class, 'destroy'])->middleware('permission:cash_bank.transfers.delete');

    Route::get('/bank-reconciliations', [BankReconciliationController::class, 'index'])
        ->middleware('permission:cash_bank.reconciliations.view');
    Route::get('/bank-reconciliations/adjacent', [BankReconciliationController::class, 'adjacent'])
        ->middleware('permission:cash_bank.reconciliations.view');
    Route::post('/bank-reconciliations', [BankReconciliationController::class, 'store'])
        ->middleware('permission:cash_bank.reconciliations.create');
    Route::get('/bank-reconciliations/{id}', [BankReconciliationController::class, 'show'])
        ->middleware('permission:cash_bank.reconciliations.view');
    Route::patch('/bank-reconciliations/{id}', [BankReconciliationController::class, 'update'])
        ->middleware('permission:cash_bank.reconciliations.edit');
    Route::post('/bank-reconciliations/{id}/refresh-lines', [BankReconciliationController::class, 'refreshLines'])
        ->middleware('permission:cash_bank.reconciliations.edit');
    Route::post('/bank-reconciliations/{id}/mark-lines', [BankReconciliationController::class, 'markLines'])
        ->middleware('permission:cash_bank.reconciliations.edit');

    Route::get('/reports/account-statement', [CashBankReportController::class, 'accountStatement'])
        ->middleware('permission:cash_bank.statements.view');
});
