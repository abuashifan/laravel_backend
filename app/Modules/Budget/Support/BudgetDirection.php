<?php

namespace App\Modules\Budget\Support;

/**
 * Arah sebuah baris anggaran. Diturunkan dari `chart_of_accounts.account_type`
 * saat baris ditulis, tidak pernah diinput user — lalu disimpan di
 * `budget_lines.direction` supaya filter `direction=revenue` bisa memakai index
 * alih-alih men-join CoA setiap kali.
 *
 * Class berisi konstanta, bukan backed enum — mengikuti pola
 * `Inventory\Support\InventoryMovementType` dan `OpeningBalance\Support\*`.
 */
class BudgetDirection
{
    public const REVENUE = 'revenue';

    public const EXPENSE = 'expense';

    /** @return array<int,string> */
    public static function all(): array
    {
        return [self::REVENUE, self::EXPENSE];
    }

    public static function exists(string $direction): bool
    {
        return in_array($direction, self::all(), true);
    }

    /**
     * Hanya akun laba-rugi yang bisa dianggarkan. Akun neraca (asset, liability,
     * equity) tidak punya arah anggaran yang masuk akal, jadi dikembalikan null
     * dan pemanggil yang memutuskan cara menolaknya.
     */
    public static function fromAccountType(?string $accountType): ?string
    {
        return match ($accountType) {
            self::REVENUE => self::REVENUE,
            self::EXPENSE => self::EXPENSE,
            default => null,
        };
    }
}
