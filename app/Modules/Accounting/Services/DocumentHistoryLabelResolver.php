<?php

namespace App\Modules\Accounting\Services;

use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\Product;
use App\Modules\MasterData\Models\Project;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Models\Warehouse;

/**
 * Riwayat edit menyimpan ID apa adanya (customer_id 5 -> 7). Supaya bisa dibaca,
 * ID master data di dalamnya diterjemahkan jadi nama saat riwayat dibuka --
 * bukan saat disimpan, karena nama master data bisa berubah belakangan.
 */
class DocumentHistoryLabelResolver
{
    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, array<string, string>> label per jenis => [id => label]
     */
    public function labels(array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            foreach (['before', 'after'] as $side) {
                $snapshot = (array) ($entry[$side] ?? []);
                $rows = array_merge([(array) ($snapshot['header'] ?? $snapshot['journal'] ?? [])], (array) ($snapshot['lines'] ?? []));
                foreach ($rows as $row) {
                    foreach ((array) $row as $field => $value) {
                        $kind = $this->kindOf((string) $field);
                        if ($kind !== null && $value !== null && $value !== '') {
                            $ids[$kind][] = (int) $value;
                        }
                    }
                }
            }
        }

        $labels = [];
        foreach ($ids as $kind => $list) {
            $labels[$kind] = $this->lookup($kind, array_values(array_unique($list)));
        }

        return $labels;
    }

    /**
     * Jenis master data untuk satu kolom, atau null bila bukan ID master data.
     */
    public function kindOf(string $field): ?string
    {
        return match (true) {
            in_array($field, ['customer_id', 'vendor_id', 'supplier_id', 'contact_id'], true) => 'contact',
            $field === 'product_id' => 'product',
            $field === 'unit_id' => 'unit',
            str_ends_with($field, 'warehouse_id') => 'warehouse',
            $field === 'department_id' => 'department',
            $field === 'project_id' => 'project',
            $field === 'payment_term_id' => 'payment_term',
            $field === 'account_id' || str_ends_with($field, '_account_id') => 'account',
            default => null,
        };
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, string>
     */
    private function lookup(string $kind, array $ids): array
    {
        $rows = match ($kind) {
            'contact' => Contact::query()->withoutGlobalScopes()->whereIn('id', $ids)->pluck('name', 'id'),
            'product' => Product::query()->withoutGlobalScopes()->whereIn('id', $ids)->get(['id', 'product_code', 'product_name'])
                ->mapWithKeys(fn (Product $p): array => [$p->id => trim($p->product_code.' '.$p->product_name)]),
            'unit' => Unit::query()->withoutGlobalScopes()->whereIn('id', $ids)->pluck('code', 'id'),
            'warehouse' => Warehouse::query()->withoutGlobalScopes()->whereIn('id', $ids)->pluck('name', 'id'),
            'department' => Department::query()->withoutGlobalScopes()->whereIn('id', $ids)->pluck('name', 'id'),
            'project' => Project::query()->withoutGlobalScopes()->whereIn('id', $ids)->pluck('name', 'id'),
            'payment_term' => PaymentTerm::query()->withoutGlobalScopes()->whereIn('id', $ids)->pluck('name', 'id'),
            'account' => ChartOfAccount::query()->withoutGlobalScopes()->whereIn('id', $ids)->get(['id', 'account_code', 'account_name'])
                ->mapWithKeys(fn (ChartOfAccount $a): array => [$a->id => $a->account_code.' '.$a->account_name]),
            default => collect(),
        };

        return collect($rows)->mapWithKeys(fn ($label, $id): array => [(string) $id => (string) $label])->all();
    }
}
