<?php

namespace Tests\TenantPgsql;

use App\Modules\Workspace\Services\WorkspaceBudgetService;
use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;

class WorkspaceBudgetConcurrencyTest extends PostgresTenantTestCase
{
    use RefreshDatabase;

    public function test_two_finance_approvals_cannot_commit_against_the_same_remaining_pagu(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        Subscription::query()->create([
            'user_id' => $owner->id, 'plan_id' => Plan::query()->where('code', 'workspace_pilot')->firstOrFail()->id,
            'product' => 'workspace', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'billing_cycle' => 'monthly', 'price' => 0,
        ]);
        Sanctum::actingAs($owner, ['*']);
        $company = $this->postJson('/api/companies', ['name' => 'Uji konkurensi Workspace', 'product' => 'workspace'])->assertCreated()->json('data.id');
        $tenant = TenantDatabase::forProduct('workspace')->where('company_id', $company)->firstOrFail();
        $schema = $this->trackSchema($tenant->database_name);
        $headers = ['X-Company-ID' => (string) $company];
        $unit = $this->postJson('/api/workspace/units', ['code' => 'UNIT-A', 'name' => 'Unit A', 'is_active' => true], $headers)->assertCreated()->json('data.id');
        $path = '/api/workspace/budget';
        $period = $this->postJson("$path/periods", ['name' => 'Pagu 2026', 'period_from' => '2026-01-01'], $headers)->assertCreated()->json('data.id');
        $annual = $this->postJson("$path/requests", [
            'kind' => 'annual', 'period_id' => $period, 'unit_id' => $unit, 'title' => 'RAB Tahunan',
            'lines' => [['code' => 'TR', 'description' => 'Transportasi', 'amount' => '100.00']],
        ], $headers)->assertCreated()->json('data.id');
        $this->postJson("$path/requests/$annual/submit", [], $headers)->assertOk();
        $this->postJson("$path/requests/$annual/approve", [], $headers)->assertOk();
        $item = $this->getJson("$path/items?kind=activity&period_id=$period&unit_id=$unit", $headers)->assertOk()->json('data.0.id');

        $requests = [];
        foreach (['Kegiatan A', 'Kegiatan B'] as $title) {
            $id = $this->postJson("$path/requests", [
                'kind' => 'activity', 'period_id' => $period, 'unit_id' => $unit, 'title' => $title, 'activity_date' => '2026-01-16',
                'lines' => [['item_id' => $item, 'description' => 'Ongkos', 'amount' => '70.00']],
            ], $headers)->assertCreated()->json('data.id');
            $this->postJson("$path/requests/$id/submit", [], $headers)->assertOk();
            $requests[] = $id;
        }

        $worker = new Process([PHP_BINARY, '-r', <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.workspace' => [
    'driver' => 'pgsql', 'url' => getenv('TENANT_TEST_PGSQL_URL'),
    'search_path' => $argv[1], 'charset' => 'utf8', 'prefix' => '',
]]);
$app->instance(App\Shared\Permission\PermissionService::class, new class extends App\Shared\Permission\PermissionService {
    public function __construct() {}
    public function can(string $permission): bool { return $permission === 'workspace.budget.approve_activity'; }
});
Illuminate\Support\Facades\DB::connection('workspace')->statement("SET application_name = 'workspace_budget_approval'");
try {
    $app->make(App\Modules\Workspace\Services\WorkspaceBudgetService::class)->decide((int) $argv[2], 'approve', null, 2);
    exit(2);
} catch (App\Shared\Exceptions\ApiException $e) {
    echo $e->codeName;
}
CODE
            , $schema, (string) $requests[1]], base_path(), ['TENANT_TEST_PGSQL_URL' => env('TENANT_TEST_PGSQL_URL')]);

        $db = DB::connection('workspace');
        $db->beginTransaction();
        try {
            app(WorkspaceBudgetService::class)->decide($requests[0], 'approve', null, $owner->id);
            $worker->start();
            $deadline = microtime(true) + 12;
            do {
                $waiting = DB::connection($this->connection)->table('pg_stat_activity')
                    ->where('application_name', 'workspace_budget_approval')
                    ->where('wait_event_type', 'Lock')->exists();
                if (! $waiting) {
                    usleep(30_000);
                }
            } while (! $waiting && $worker->isRunning() && microtime(true) < $deadline);
            $this->assertTrue($waiting, $worker->getErrorOutput().$worker->getOutput());
            $db->commit();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $this->assertSame('WORKSPACE_BUDGET_LIMIT_EXCEEDED', $worker->getOutput());
            $this->assertSame(7000, (int) $db->table('workspace_budget_items')->where('id', $item)->value('committed_minor'));
            $this->assertSame('submitted', $db->table('workspace_budget_documents')->where('id', $requests[1])->value('status'));
        } finally {
            if ($db->transactionLevel()) {
                $db->rollBack();
            }
            $worker->stop();
            DB::disconnect('workspace');
        }
    }
}
