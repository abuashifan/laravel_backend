<?php

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Models\SavedReport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Laporan Tersimpan (Fase 13 T13.1).
 *
 * Visibilitas: sebuah saved report terlihat oleh pemiliknya ATAU user yang
 * dibagikan. Mutasi (update/hapus/atur berbagi) hanya oleh pemilik.
 */
class SavedReportService
{
    /**
     * Daftar saved report yang terlihat oleh user (miliknya + yang dibagikan).
     *
     * @return list<array<string,mixed>>
     */
    public function listForUser(int $userId): array
    {
        /** @var Collection<int, SavedReport> $reports */
        $reports = SavedReport::query()
            ->with('shares')
            ->where('user_id', $userId)
            ->orWhereHas('shares', fn ($q) => $q->where('user_id', $userId))
            ->orderByDesc('updated_at')
            ->get();

        return $reports->map(fn (SavedReport $r) => $this->present($r, $userId))->all();
    }

    /**
     * @param  array{report_key:string, name:string, params?:array<string,mixed>|null, shared_user_ids?:list<int>}  $data
     * @return array<string,mixed>
     */
    public function create(int $userId, array $data): array
    {
        return DB::connection('tenant')->transaction(function () use ($userId, $data) {
            $report = SavedReport::query()->create([
                'user_id' => $userId,
                'report_key' => $data['report_key'],
                'name' => $data['name'],
                'params' => $data['params'] ?? [],
            ]);

            $this->syncShares($report, $data['shared_user_ids'] ?? [], $userId);

            return $this->present($report->fresh('shares'), $userId);
        });
    }

    /**
     * @return array<string,mixed>|null null bila tidak ada/tak terlihat oleh user.
     */
    public function show(int $userId, int $id): ?array
    {
        $report = $this->findVisible($userId, $id);

        return $report === null ? null : $this->present($report, $userId);
    }

    /**
     * @param  array{report_key?:string, name?:string, params?:array<string,mixed>|null, shared_user_ids?:list<int>}  $data
     * @return array<string,mixed>|false|null null=tak ditemukan, false=bukan pemilik.
     */
    public function update(int $userId, int $id, array $data): array|false|null
    {
        /** @var SavedReport|null $report */
        $report = SavedReport::query()->find($id);
        if ($report === null) {
            return null;
        }
        if ((int) $report->user_id !== $userId) {
            return false;
        }

        return DB::connection('tenant')->transaction(function () use ($report, $data, $userId) {
            $report->fill(array_filter([
                'report_key' => $data['report_key'] ?? null,
                'name' => $data['name'] ?? null,
            ], fn ($v) => $v !== null));
            if (array_key_exists('params', $data)) {
                $report->params = $data['params'] ?? [];
            }
            $report->save();

            if (array_key_exists('shared_user_ids', $data)) {
                $this->syncShares($report, $data['shared_user_ids'] ?? [], $userId);
            }

            return $this->present($report->fresh('shares'), $userId);
        });
    }

    /**
     * @return bool|null null=tak ditemukan, false=bukan pemilik, true=terhapus.
     */
    public function delete(int $userId, int $id): ?bool
    {
        /** @var SavedReport|null $report */
        $report = SavedReport::query()->find($id);
        if ($report === null) {
            return null;
        }
        if ((int) $report->user_id !== $userId) {
            return false;
        }

        $report->delete();

        return true;
    }

    private function findVisible(int $userId, int $id): ?SavedReport
    {
        /** @var SavedReport|null $report */
        $report = SavedReport::query()
            ->with('shares')
            ->where('id', $id)
            ->where(fn ($q) => $q
                ->where('user_id', $userId)
                ->orWhereHas('shares', fn ($s) => $s->where('user_id', $userId)))
            ->first();

        return $report;
    }

    /**
     * @param  list<int>  $userIds  penerima berbagi (owner otomatis dikecualikan).
     */
    private function syncShares(SavedReport $report, array $userIds, int $ownerId): void
    {
        $recipients = collect($userIds)
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0 && $v !== $ownerId)
            ->unique()
            ->values();

        $report->shares()->delete();
        foreach ($recipients as $uid) {
            $report->shares()->create(['user_id' => $uid]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function present(SavedReport $report, int $userId): array
    {
        return [
            'id' => (int) $report->id,
            'report_key' => (string) $report->report_key,
            'name' => (string) $report->name,
            'params' => $report->params ?? [],
            'is_owner' => (int) $report->user_id === $userId,
            'owner_user_id' => (int) $report->user_id,
            'shared_user_ids' => $report->shares->pluck('user_id')->map(fn ($v) => (int) $v)->values()->all(),
            'created_at' => optional($report->created_at)->toIso8601String(),
            'updated_at' => optional($report->updated_at)->toIso8601String(),
        ];
    }
}
