<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\User;
use App\Services\Transactions\TransactionRevisionService;
use App\Support\Revision\RevisionSnapshot;
use App\Support\Revision\TransactionRevisionAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class TransactionRevisionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.tenant', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    }

    public function test_revision_snapshot_diff_returns_changed_fields(): void
    {
        $diff = RevisionSnapshot::diff(['a' => 1], ['a' => 2]);
        $this->assertArrayHasKey('a', $diff['changed_fields']);
        $this->assertSame(['old' => 1, 'new' => 2], $diff['changed_fields']['a']);
    }

    public function test_current_and_next_revision_number_defaults(): void
    {
        $service = $this->app->make(TransactionRevisionService::class);

        $this->assertSame(1, $service->currentRevisionNumber([]));
        $this->assertSame(2, $service->nextRevisionNumber([]));
        $this->assertSame(3, $service->nextRevisionNumber(['revision_no' => 2]));
    }

    public function test_record_edit_creates_transaction_revision_with_changed_fields(): void
    {
        $service = $this->app->make(TransactionRevisionService::class);

        $rev = $service->recordEdit(
            'sales_invoice',
            15,
            'SI-2026-000015',
            'sales',
            1,
            2,
            ['amount' => 10],
            ['amount' => 12],
            'Fix amount',
            1,
            ['k' => 'v']
        );

        $this->assertSame(TransactionRevisionAction::EDIT, $rev->action);
        $this->assertSame(1, $rev->source_revision_from);
        $this->assertSame(2, $rev->source_revision_to);
        $this->assertTrue($rev->hasChangedField('amount'));
    }

    public function test_record_void_creates_void_action_revision(): void
    {
        $service = $this->app->make(TransactionRevisionService::class);

        $rev = $service->recordVoid(
            'sales_invoice',
            15,
            'SI-2026-000015',
            'sales',
            2,
            'Input salah',
            1,
            ['status' => 'posted']
        );

        $this->assertSame(TransactionRevisionAction::VOID, $rev->action);
        $this->assertSame(2, $rev->source_revision_from);
        $this->assertSame(2, $rev->source_revision_to);
        $this->assertSame(['status' => 'posted'], $rev->old_values);
    }
}

