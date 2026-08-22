<?php

namespace Tests\Support;

use PHPUnit\Event\TestRunner\Finished as TestRunnerFinished;
use PHPUnit\Event\TestRunner\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Fase 5 (skema tier) §5d — tripwire, bukan pembersih. Test yang menyediakan
 * tenant sqlite wajib mendaftarkan berkasnya lewat `TestCase::registerTenantFile()`
 * supaya dihapus otomatis di `tearDown()`; kalau ada berkas `test_*` yang masih
 * tersisa setelah SELURUH suite selesai, itu tandanya ada jalur baru yang lupa
 * melakukannya — persis kebocoran yang dulu menumpuk 53 GB sebelum ditutup.
 *
 * Dipasang sebagai extension (event `TestRunner\Finished`), bukan sebagai test
 * biasa: PHPUnit tidak menjamin urutan eksekusi antar class test secara default,
 * jadi test class "pemeriksa akhir" bisa saja tidak benar-benar berjalan
 * terakhir. Event `Finished` dijamin menyala setelah semuanya selesai apa pun
 * urutannya.
 */
final class TenantFileLeakGuardExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements FinishedSubscriber
        {
            public function notify(TestRunnerFinished $event): void
            {
                $directory = dirname(__DIR__, 2).'/database/tenants';

                if (! is_dir($directory)) {
                    return;
                }

                $leaked = glob($directory.'/test_*') ?: [];

                if ($leaked === []) {
                    return;
                }

                fwrite(STDERR, sprintf(
                    "\n\033[41;97m TENANT FILE LEAK \033[0m %d berkas 'test_*' tersisa di database/tenants/ setelah suite selesai.\n".
                    "Test yang menyediakan tenant sqlite wajib memanggil \$this->registerTenantFile(\$path)\n".
                    "(lihat tests/TestCase.php) supaya dibersihkan di tearDown(). Berkas pertama:\n  %s\n%s\n",
                    count($leaked),
                    $leaked[0],
                    count($leaked) > 1 ? '  ... dan '.(count($leaked) - 1).' lainnya.' : ''
                ));

                // Extension berjalan setelah PHPUnit menghitung status akhirnya
                // sendiri — exit paksa di sini adalah cara resmi yang didukung
                // untuk membuat pemeriksaan tambahan ikut menggagalkan build.
                exit(1);
            }
        });
    }
}
