<?php

namespace App\Services\Transactions;

use App\Contracts\Transactions\TransactionDependencyChecker;
use App\Services\Transactions\Checkers\CashBankTransactionDependencyChecker;
use App\Services\Transactions\Checkers\InventoryTransactionDependencyChecker;
use App\Services\Transactions\Checkers\JournalTransactionDependencyChecker;
use App\Services\Transactions\Checkers\PurchaseTransactionDependencyChecker;
use App\Services\Transactions\Checkers\SalesTransactionDependencyChecker;
use App\Support\Transaction\DependencyCheckResult;
use App\Support\Transaction\TransactionModule;

class TransactionDependencyService implements TransactionDependencyChecker
{
    /** @var array<string, TransactionDependencyChecker> */
    private array $checkers = [];

    public function __construct(
        SalesTransactionDependencyChecker $salesChecker,
        PurchaseTransactionDependencyChecker $purchaseChecker,
        JournalTransactionDependencyChecker $journalChecker,
        CashBankTransactionDependencyChecker $cashBankChecker,
        InventoryTransactionDependencyChecker $inventoryChecker,
        NoopTransactionDependencyChecker $fallbackChecker,
    ) {
        $this->checkers = [
            TransactionModule::SALES => $salesChecker,
            TransactionModule::PURCHASE => $purchaseChecker,
            TransactionModule::JOURNAL => $journalChecker,
            TransactionModule::CASH_BANK => $cashBankChecker,
            TransactionModule::INVENTORY => $inventoryChecker,
            '*' => $fallbackChecker,
        ];
    }

    public function registerChecker(string $module, TransactionDependencyChecker $checker): void
    {
        $this->checkers[$module] = $checker;
    }

    public function checkerFor(string $module): TransactionDependencyChecker
    {
        return $this->checkers[$module] ?? $this->checkers['*'];
    }

    public function check(mixed $transaction, string $action, string $module): DependencyCheckResult
    {
        return $this->checkerFor($module)->check($transaction, $action, $module);
    }

    public function hasBlockingDependencies(mixed $transaction, string $action, string $module): bool
    {
        return $this->check($transaction, $action, $module)->isBlocked();
    }

    public function blockingReasons(mixed $transaction, string $action, string $module): array
    {
        return $this->check($transaction, $action, $module)->reasons();
    }
}
