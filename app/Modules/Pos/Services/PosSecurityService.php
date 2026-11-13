<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosMemberLimit;
use App\Modules\Pos\Models\PosSupervisorPin;
use App\Shared\Models\User;
use App\Shared\Permission\PermissionService;
use App\Shared\Tenant\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Batas wewenang kasir (Fase 4b): PIN kepala toko dan batas diskon per kasir.
 *
 * `verifyPin()` HARUS dipanggil di luar transaksi database. Pencacah salah-PIN
 * dan baris log audit harus tetap tersimpan walau permintaannya ditolak;
 * di dalam transaksi yang gagal keduanya ikut hilang.
 */
class PosSecurityService
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 5;

    public function __construct(
        private readonly PosSettingService $settings,
        private readonly PosAuditService $audit,
        private readonly PermissionService $permissions,
        private readonly TenantContext $context,
    ) {}

    public function pinIsSet(): bool
    {
        return PosSupervisorPin::query()->exists();
    }

    /**
     * Pengguna dengan izin batal/retur (kepala toko, pemilik) bertindak sendiri;
     * yang lain butuh PIN kepala toko dan hanya kalau pemilik mengizinkannya.
     */
    public function canActWithoutPin(): bool
    {
        return $this->permissions->can('pos.sales.delete');
    }

    /**
     * Jaga aksi batal/retur: lolos kalau pengguna berizin penuh, atau
     * pemilik mengizinkan kasir memakai PIN dan PIN-nya benar.
     *
     * @return bool true kalau aksi ini disetujui lewat PIN
     */
    public function authorizeSensitive(User $actor, ?string $pin): bool
    {
        if ($this->canActWithoutPin()) {
            return false;
        }

        if ($this->settings->get('cashier_void_with_pin') !== '1') {
            abort(403, 'Anda tidak punya izin untuk aksi ini. Minta kepala toko.');
        }

        $this->verifyPin($actor, $pin);

        return true;
    }

    /** Periksa PIN kepala toko; gagal = ValidationException di kunci `supervisor_pin`. */
    public function verifyPin(User $actor, ?string $pin): void
    {
        $record = PosSupervisorPin::query()->first();
        if (! $record) {
            throw ValidationException::withMessages(['supervisor_pin' => ['Toko belum punya PIN kepala toko. Minta pemilik membuatnya di Pengaturan.']]);
        }
        if ($pin === null || trim($pin) === '') {
            throw ValidationException::withMessages(['supervisor_pin' => ['PIN kepala toko wajib diisi.']]);
        }
        if ($record->locked_until && $record->locked_until->isFuture()) {
            $minutes = max(1, (int) ceil(now()->diffInSeconds($record->locked_until, true) / 60));
            throw ValidationException::withMessages(['supervisor_pin' => ["PIN terkunci karena terlalu banyak salah. Coba lagi dalam {$minutes} menit."]]);
        }

        if (Hash::check($pin, $record->pin_hash)) {
            if ($record->failed_attempts > 0 || $record->locked_until) {
                $record->forceFill(['failed_attempts' => 0, 'locked_until' => null])->save();
            }

            return;
        }

        $attempts = $record->failed_attempts + 1;
        $locked = $attempts >= self::MAX_ATTEMPTS;
        $record->forceFill([
            'failed_attempts' => $locked ? 0 : $attempts,
            'locked_until' => $locked ? now()->addMinutes(self::LOCK_MINUTES) : null,
        ])->save();

        $this->audit->record(
            'pin.failed',
            $locked ? 'PIN kepala toko salah berulang kali; dikunci '.self::LOCK_MINUTES.' menit.' : 'PIN kepala toko salah.',
            $actor,
            changes: ['attempts' => $attempts],
        );

        throw ValidationException::withMessages(['supervisor_pin' => [
            $locked
                ? 'PIN salah. Terlalu banyak percobaan; PIN dikunci '.self::LOCK_MINUTES.' menit.'
                : 'PIN kepala toko salah.',
        ]]);
    }

    /** Buat atau ganti PIN. Pemilik boleh tanpa PIN lama; yang lain wajib. */
    public function setPin(User $actor, string $pin, ?string $currentPin): void
    {
        $record = PosSupervisorPin::query()->first();

        if ($record && ! $this->isOwner()) {
            if (! $currentPin || ! Hash::check($currentPin, $record->pin_hash)) {
                throw ValidationException::withMessages(['current_pin' => ['PIN lama salah.']]);
            }
        }

        $values = ['pin_hash' => Hash::make($pin), 'set_by' => $actor->id, 'failed_attempts' => 0, 'locked_until' => null];
        $record ? $record->forceFill($values)->save() : PosSupervisorPin::query()->create($values);

        $this->audit->record('pin.changed', $record ? 'PIN kepala toko diganti.' : 'PIN kepala toko dibuat.', $actor);
    }

    public function clearPin(User $actor): void
    {
        if (PosSupervisorPin::query()->delete() > 0) {
            $this->audit->record('pin.changed', 'PIN kepala toko dihapus.', $actor);
        }
    }

    /**
     * Batas diskon persen untuk pengguna ini, atau null kalau tanpa batas.
     * Urutan: izin `pos.discount.override` (tanpa batas) -> batas pribadi ->
     * batas bawaan toko.
     */
    public function discountLimit(User $user): ?float
    {
        if ($this->permissions->can('pos.discount.override')) {
            return null;
        }

        $own = PosMemberLimit::query()->where('user_id', $user->id)->value('max_discount_percent');
        if ($own !== null) {
            return (float) $own;
        }

        $default = trim($this->settings->get('max_discount_percent'));

        return $default === '' ? null : (float) $default;
    }

    /** Batas pribadi (null = ikut bawaan toko) untuk layar pengguna. */
    public function setMemberLimit(int $userId, ?float $percent): void
    {
        PosMemberLimit::query()->updateOrCreate(['user_id' => $userId], ['max_discount_percent' => $percent]);
    }

    private function isOwner(): bool
    {
        return (bool) $this->context->companyUser()?->is_owner;
    }
}
