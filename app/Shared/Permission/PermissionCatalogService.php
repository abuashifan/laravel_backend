<?php

namespace App\Shared\Permission;

use App\Shared\Models\Permission;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PermissionCatalogService
{
    private const MATRIX_COLUMNS = ['daftar', 'tambah', 'ubah', 'hapus', 'persetujuan', 'cetak', 'ekspor'];

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
                'label' => $this->featureLabel($module, $feature),
                'permissions' => [],
            ];

            $column = (string) ($permission['matrix_column'] ?? '');
            if ($column !== '') {
                $modules[$module]['features'][$feature]['permissions'][$column] = $permission;
            }
        }

        $ordered = [];
        foreach ((array) config('permissions.matrix', []) as $moduleKey => $definition) {
            if (! isset($modules[$moduleKey])) {
                continue;
            }
            $module = $modules[$moduleKey];
            $features = [];
            foreach (array_keys($definition['features']) as $featureKey) {
                if (isset($module['features'][$featureKey])) {
                    $features[] = $module['features'][$featureKey];
                }
            }
            $module['features'] = $features;
            $ordered[] = $module;
        }

        return ['matrix_columns' => self::MATRIX_COLUMNS, 'modules' => $ordered];
    }

    public function matrixColumnForAction(string $action): ?string
    {
        return match ($action) {
            'view', 'list', 'index', 'show' => 'daftar',
            'create', 'store' => 'tambah',
            'edit', 'update' => 'ubah',
            'delete', 'deactivate', 'void', 'cancel' => 'hapus',
            'print', 'export_pdf' => 'cetak',
            'export' => 'ekspor',
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
                ->whereIn('key', (array) config('permissions.matrix_keys', []))
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

        return collect((array) config('permissions.matrix_keys', []))
            ->map(fn (string $key, int $index) => $this->fromKey($key, $index))
            ->all();
    }

    public function fromKey(string $key, int $sortOrder = 0): array
    {
        $definition = $this->matrixDefinition($key);
        if ($definition !== null) {
            return [...$definition, 'sort_order' => $sortOrder];
        }

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
        $label = config("permissions.matrix.{$module}.label");
        if (is_string($label)) {
            return $label;
        }

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

    private function featureLabel(string $module, string $feature): string
    {
        $matrix = (array) config('permissions.matrix', []);

        return $matrix[$module]['features'][$feature][0] ?? Str::headline($feature);
    }

    private function matrixDefinition(string $key): ?array
    {
        foreach ((array) config('permissions.matrix', []) as $module => $definition) {
            foreach ($definition['features'] as $prefix => [$label, $actions]) {
                foreach ($actions as $action) {
                    if ($key !== $prefix.'.'.$action) {
                        continue;
                    }

                    $column = $this->matrixColumnForAction($action);

                    return [
                        'key' => $key,
                        'module' => $module,
                        'group' => $prefix,
                        'feature' => $prefix,
                        'action' => $action,
                        'label' => $label.' - '.match ($action) {
                            'view' => 'Lihat', 'create' => 'Tambah', 'edit' => 'Ubah',
                            'delete' => 'Hapus / Void', 'approve' => 'Setujui / Posting',
                            'print' => 'Cetak', 'export' => 'Ekspor',
                        },
                        'description' => null,
                        'matrix_column' => $column,
                        'is_special' => false,
                        'is_system' => true,
                    ];
                }
            }

            if (isset($definition['special'][$key])) {
                return [
                    'key' => $key,
                    'module' => $module,
                    'group' => null,
                    'feature' => $key,
                    'action' => substr($key, strrpos($key, '.') + 1),
                    'label' => $definition['special'][$key],
                    'description' => null,
                    'matrix_column' => null,
                    'is_special' => true,
                    'is_system' => true,
                ];
            }
        }

        return null;
    }
}
