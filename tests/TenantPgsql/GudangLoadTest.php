<?php

namespace Tests\TenantPgsql;

use App\Modules\Gudang\StockLedger;
use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;

class GudangLoadTest extends PostgresTenantTestCase
{
    use RefreshDatabase;

    public function test_warehouse_pilot_volume_and_two_concurrent_issues(): void
    {
        $user = User::factory()->create(['status'=>'active']);
        Subscription::query()->create(['user_id'=>$user->id,'plan_id'=>Plan::where('code','gudang')->firstOrFail()->id,
            'product'=>'gudang','starts_at'=>now()->subDay(),'ends_at'=>now()->addMonth(),'billing_cycle'=>'monthly','price'=>0]);
        Sanctum::actingAs($user,['*']);
        $id = $this->postJson('/api/companies',['name'=>'Uji beban Gudang','product'=>'gudang'])->assertCreated()->json('data.id');
        $tenant = TenantDatabase::forProduct('gudang')->where('company_id',$id)->firstOrFail();
        $this->trackSchema($tenant->database_name);
        $this->storage()->connect($tenant->database_name,$tenant->database_path,'gudang');
        $db = DB::connection('gudang');
        foreach (array_chunk(range(1,5000),500) as $batch) {
            $db->table('gudang_items')->insert(array_map(fn ($n) => ['id'=>$n,'name'=>'Barang '.$n,'sku'=>'SKU'.$n,'unit'=>'pcs'], $batch));
        }
        $location = $db->table('gudang_locations')->value('id');
        $ledger = app(StockLedger::class);
        for ($day = 0; $day < 7; $day++) {
            foreach (array_chunk(range(1,500),100) as $batch) {
                $lines = array_map(fn ($n) => ['item_id'=>$n + $day*500,'location_id'=>$location,'quantity'=>'1'], $batch);
                $posted = $ledger->receive(['request_key'=>"$day-".$batch[0], 'source'=>'Uji beban','document_date'=>now()->subDays(6-$day)->toDateString(),'lines'=>$lines], $user->id);
            }
        }
        $this->assertSame(3500,$db->table('gudang_stock_movements')->count());
        $headers = ['X-Company-ID'=>(string) $id];
        foreach (['reports?kind=stock','reports?kind=warehouse','reports?kind=movements','reports?kind=stock&q=SKU3500','items/1/card'] as $path) {
            $times = [];
            for ($run=0;$run<5;$run++) {
                $start = hrtime(true);
                $this->getJson('/api/gudang/'.$path,$headers)->assertOk();
                $times[] = (hrtime(true)-$start)/1e6;
            }
            fwrite(STDOUT,sprintf("\nGudang %s max %.1f ms\n",$path,max($times)));
            $this->assertLessThan(250,max($times),$path);
        }

        // Penulis pertama menahan kunci saldo. Penulis kedua memakai proses PHP dan koneksi mandiri.
        $worker = new Process([PHP_BINARY,'-r', <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.gudang'=>['driver'=>'pgsql','url'=>getenv('TENANT_TEST_PGSQL_URL'),'search_path'=>$argv[1],'charset'=>'utf8','prefix'=>'']]);
$db = Illuminate\Support\Facades\DB::connection('gudang');
$db->statement("SET application_name = 'gudang_concurrent_issue'");
try {
    $db->transaction(fn () => app(App\Modules\Gudang\StockLedger::class)->move((int)$argv[2],1,(int)$argv[3],-1000));
    exit(2);
} catch (Illuminate\Validation\ValidationException $e) {
    echo 'stock-rejected';
}
PHP
            ,$tenant->database_name,(string)$posted->id,(string)$location],base_path(),['TENANT_TEST_PGSQL_URL'=>env('TENANT_TEST_PGSQL_URL')]);
        $db->beginTransaction();
        try {
            $ledger->move($posted->id,1,$location,-1000);
            $worker->start();
            $limit = microtime(true)+12;
            do {
                $waiting = DB::connection($this->connection)->table('pg_stat_activity')
                    ->where('application_name','gudang_concurrent_issue')->where('wait_event_type','Lock')->exists();
                if (!$waiting) usleep(30_000);
            } while (!$waiting && $worker->isRunning() && microtime(true)<$limit);
            $this->assertTrue($waiting,'Penulis kedua harus menunggu kunci saldo. '.$worker->getErrorOutput());
            $db->commit();
            $this->assertSame(0,$worker->wait(),$worker->getErrorOutput());
            $this->assertSame('stock-rejected',$worker->getOutput());
            $this->assertSame(0,(int)$db->table('gudang_stock_balances')->where('item_id',1)->value('quantity_milli'));
        } finally {
            if ($db->transactionLevel()) $db->rollBack();
            $worker->stop();
        }
    }
}
