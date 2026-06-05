<?php

namespace App\Services\Permissions;

use App\Models\Permission;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class PermissionCatalogService
{
    private const MATRIX_COLUMNS = ['daftar', 'tambah', 'ubah', 'hapus', 'cetak', 'laporan', 'persetujuan'];

    public function grouped(): array
    {
        $permissions = $this->permissions();
        $modules = [];

        foreach ($permissions as $permission) {
            $module = (string) $permission['module'];
            $feature = (string) $permission['feature'];
            $isSpecial = (bool) $permission['is_special'];

            $modules[$module] ??= [
                'key' => $module,
                'label' => $this->moduleLabel($module),
                'features' => [],
                'special_permissions' => [],
            ];

            if ($isSpecial) {
                $modules[$module]['special_permissions'][] = $permission;
                continue;
            }

            $modules[$module]['features'][$feature] ??= [
                'key' => $feature,
                'label' => Str::headline($feature),
                'permissions' => [],
            ];

            $column = (string) ($permission['matrix_column'] ?? '');
            if ($column !== '') {
                $modules[$module]['features'][$feature]['permissions'][$column] = $permission;
            }
        }

        return [
            'matrix_columns' => self::MATRIX_COLUMNS,
            'modules' => array_values(array_map(function (array $module) {
                $module['features'] = array_values($module['features']);

                return $module;
            }, $modules)),
        ];
    }

    public function matrixColumnForAction(string $action): ?string
    {
        return match ($action) {
            'view', 'list', 'index', 'show' => 'daftar',
            'create', 'store' => 'tambah',
            'edit', 'update' => 'ubah',
            'delete', 'deactivate', 'void', 'cancel' => 'hapus',
            'print', 'export_pdf' => 'cetak',
            'report', 'view_report' => 'laporan',
            'approve', 'post', 'confirm', 'close', 'reopen', 'receive', 'ship', 'deliver', 'finalize' => 'persetujuan',
            default => null,
        };
    }

    public function isSpecialAction(string $action): bool
    {
        return $this->matrixColumnForAction($action) === null;
    }

    private function permissions(): array
    {
        if (Schema::hasTable('permissions')) {
            return Permission::query()
                ->orderBy('sort_order')
                ->orderBy('key')
                ->get()
                ->map(fn (Permission $permission) => [
                    'key' => $permission->key,
                    'module' => $permission->module,
                    'group' => $permission->group,
                    'feature' => $permission->feature,
                    'action' => $permission->action,
                    'label' => $permission->label,
                    'description' => $permission->description,
                    'matrix_column' => $permission->matrix_column,
                    'is_special' => $permission->is_special,
                ])
                ->all();
        }

        return collect((array) config('permissions.permissions', []))
            ->map(fn (string $key, int $index) => $this->fromKey($key, $index))
            ->all();
    }

    public function fromKey(string $key, int $sortOrder = 0): array
    {
        $parts = explode('.', $key);
        $action = (string) array_pop($parts);
        $module = (string) ($parts[0] ?? 'system');
        $feature = implode('.', array_slice($parts, 1)) ?: $module;
        $matrixColumn = $this->matrixColumnForAction($action);

        return [
            'key' => $key,
            'module' => $module,
            'group' => $parts[1] ?? null,
            'feature' => $feature,
            'action' => $action,
            'label' => Str::headline($feature).' - '.Str::headline($action),
            'description' => null,
            'matrix_column' => $matrixColumn,
            'is_special' => $matrixColumn === null,
            'is_system' => true,
            'sort_order' => $sortOrder,
        ];
    }

    private function moduleLabel(string $module): string
    {
        return match ($module) {
            'settings' => 'Info Perusahaan',
            'access' => 'Access Management',
            'master_data', 'coa', 'contacts', 'products', 'units', 'warehouses', 'departments', 'projects', 'payment_terms' => 'Master Data',
            'journal', 'accounting', 'fiscal_year' => 'Buku Besar',
            'sales' => 'Penjualan',
            'purchase' => 'Pembelian',
            'cash_bank' => 'Kas & Bank',
            'inventory' => 'Persediaan',
            'reports' => 'Laporan',
            'audit' => 'Audit',
            default => Str::headline($module),
        };
    }
}
