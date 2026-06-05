<?php

namespace Tests\Unit;

use App\Contracts\Transactions\TransactionDependencyChecker;
use App\Services\Transactions\NoopTransactionDependencyChecker;
use App\Services\Transactions\TransactionDependencyService;
use App\Support\Transaction\DependencyCheckResult;
use App\Support\Transaction\TransactionModule;
use Tests\TestCase;

class TransactionDependencyServiceTest extends TestCase
{
    public function test_default_no_dependency_returns_clear_result(): void
    {
        $service = $this->app->make(TransactionDependencyService::class);
        $result = $service->check(['id' => 1], 'edit', TransactionModule::SALES);

        $this->assertTrue($result->isClear());
        $this->assertSame([], $result->reasons());
        $this->assertSame([], $result->dependencies());
    }

    public function test_has_blocking_dependencies_returns_false_when_clear(): void
    {
        $service = $this->app->make(TransactionDependencyService::class);
        $this->assertFalse($service->hasBlockingDependencies(['id' => 1], 'edit', TransactionModule::SALES));
    }

    public function test_blocking_reasons_returns_empty_array_when_clear(): void
    {
        $service = $this->app->make(TransactionDependencyService::class);
        $this->assertSame([], $service->blockingReasons(['id' => 1], 'edit', TransactionModule::SALES));
    }

    public function test_registered_checker_can_block_transaction(): void
    {
        $service = $this->app->make(TransactionDependencyService::class);

        $blocking = new class extends NoopTransactionDependencyChecker implements TransactionDependencyChecker {
            public function check(mixed $transaction, string $action, string $module): DependencyCheckResult
            {
                return DependencyCheckResult::blocked(
                    ['Invoice sudah memiliki pembayaran.'],
                    [[
                        'type' => 'payment',
                        'module' => 'sales',
                        'record_id' => 10,
                        'record_number' => 'PAY-00010',
                        'message' => 'Invoice sudah memiliki pembayaran.',
                    ]]
                );
            }
        };

        $service->registerChecker(TransactionModule::SALES, $blocking);

        $result = $service->check(['id' => 1], 'void', TransactionModule::SALES);
        $this->assertTrue($result->isBlocked());
        $this->assertNotEmpty($result->reasons());
        $this->assertNotEmpty($result->dependencies());
    }

    public function test_service_resolves_sales_checker(): void
    {
        $service = $this->app->make(TransactionDependencyService::class);
        $this->assertNotNull($service->checkerFor(TransactionModule::SALES));
    }

    public function test_service_resolves_purchase_checker(): void
    {
        $service = $this->app->make(TransactionDependencyService::class);
        $this->assertNotNull($service->checkerFor(TransactionModule::PURCHASE));
    }

    public function test_service_fallbacks_to_noop_checker_for_unknown_module(): void
    {
        $service = $this->app->make(TransactionDependencyService::class);
        $checker = $service->checkerFor('unknown');

        $this->assertInstanceOf(NoopTransactionDependencyChecker::class, $checker);
    }
}
