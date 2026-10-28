<?php

namespace App\Console\Commands;

use App\Shared\Models\CompanyUser;
use App\Shared\Permission\LegacyAccessMigrationService;
use Illuminate\Console\Command;

class VerifyAccessMigrationCommand extends Command
{
    protected $signature = 'access:verify-migration';

    protected $description = 'Compare direct access with the previous role and override assignments without changing data';

    public function handle(LegacyAccessMigrationService $service): int
    {
        $checked = 0;
        $mismatched = 0;
        CompanyUser::query()->orderBy('id')->chunkById(100, function ($members) use ($service, &$checked, &$mismatched): void {
            foreach ($members as $member) {
                $checked++;
                $result = $service->compare($member);
                if ($result['full_access'] && $result['is_owner'] && $result['missing'] === [] && $result['extra'] === []) {
                    continue;
                }

                $mismatched++;
                $this->warn(sprintf(
                    'company_user=%d company=%d full_access=%s owner=%s missing=%d extra=%d',
                    $member->id, $member->company_id,
                    $result['full_access'] ? 'ok' : 'beda', $result['is_owner'] ? 'ok' : 'beda',
                    count($result['missing']), count($result['extra']),
                ));
            }
        });

        $this->info("Pemeriksaan hak akses: {$checked} anggota, {$mismatched} berbeda.");

        return $mismatched === 0 ? self::SUCCESS : self::FAILURE;
    }
}
