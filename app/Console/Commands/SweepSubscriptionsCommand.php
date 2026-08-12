<?php

namespace App\Console\Commands;

use App\Shared\Models\Subscription;
use App\Shared\Models\User;
use App\Shared\Subscription\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Dua tugas, keduanya TIDAK memengaruhi kebenaran gerbang — status langganan
 * selalu diturunkan dari tanggal (`SubscriptionService::stateFor()`), jadi
 * command yang gagal jalan semalam berarti orang terkunci/terbuka secara
 * keliru TIDAK terjadi. Yang command ini urus hanyalah hal yang memang butuh
 * dijalankan (Fase 3, skema tier §4e):
 *
 * 1. Mencabut token client yang kedaluwarsa/dibatalkan — sesi yang sedang
 *    berjalan tetap hidup sampai tokennya dicabut eksplisit.
 * 2. Menandai siapa yang perlu dihubungi (H-14 dan selama tenggang) — tidak
 *    ada pengiriman email di aplikasi ini, jadi ini daftar untuk dihubungi
 *    manual lewat WhatsApp.
 */
class SweepSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:sweep {--dry-run : Cetak daftar tanpa mengubah apa pun}';

    protected $description = 'Cabut token client kedaluwarsa/dibatalkan, dan daftar client yang perlu dihubungi (H-14 / tenggang)';

    public function handle(SubscriptionService $subscriptions): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $clientIds = Subscription::query()->distinct()->pluck('user_id');

        if ($clientIds->isEmpty()) {
            $this->info('Tidak ada client dengan riwayat langganan.');

            return self::SUCCESS;
        }

        $revoked = [];
        $dueSoon = [];
        $inGrace = [];

        User::query()->whereIn('id', $clientIds)->each(function (User $client) use ($subscriptions, $dryRun, &$revoked, &$dueSoon, &$inGrace) {
            $state = $subscriptions->stateFor($client);
            $days = $subscriptions->daysRemaining($client);

            if (in_array($state, [SubscriptionService::STATE_EXPIRED, SubscriptionService::STATE_CANCELLED], true)) {
                $tokenCount = $client->tokens()->count();

                if ($tokenCount > 0) {
                    if (! $dryRun) {
                        $client->tokens()->delete();
                    }
                    $revoked[] = [$client->id, $client->email, $state, $tokenCount];
                }

                return;
            }

            if ($state === SubscriptionService::STATE_GRACE) {
                $inGrace[] = [$client->id, $client->email, $days];

                return;
            }

            if ($state === SubscriptionService::STATE_ACTIVE && $days !== null && $days <= 14) {
                $dueSoon[] = [$client->id, $client->email, $days];
            }
        });

        if ($dryRun) {
            $this->warn('--dry-run: tidak ada token yang benar-benar dicabut.');
        }

        $this->newLine();
        $this->line('<fg=yellow>Token dicabut (kedaluwarsa/dibatalkan):</>');
        $this->table(['ID', 'Email', 'Status', 'Token dicabut'], $revoked);

        $this->newLine();
        $this->line('<fg=yellow>Dalam masa tenggang (akses penuh, hubungi segera):</>');
        $this->table(['ID', 'Email', 'Sisa hari sejak berakhir'], $inGrace);

        $this->newLine();
        $this->line('<fg=yellow>Akan jatuh tempo ≤14 hari (H-14):</>');
        $this->table(['ID', 'Email', 'Sisa hari'], $dueSoon);

        $this->newLine();
        $this->info(sprintf(
            'Selesai. %d token dicabut, %d dalam tenggang, %d akan jatuh tempo.',
            count($revoked), count($inGrace), count($dueSoon)
        ));

        return self::SUCCESS;
    }
}
