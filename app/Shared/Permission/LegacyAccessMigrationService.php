<?php

namespace App\Shared\Permission;

use App\Shared\Models\CompanyUser;
use App\Shared\Models\Permission;
use Illuminate\Support\Facades\DB;

/** Prepare and compare direct permissions with the previous role/override model. */
class LegacyAccessMigrationService
{
    public function __construct(
        private readonly EffectivePermissionService $legacy,
        private readonly PermissionCatalogService $catalog,
    ) {}

    /** @return array{full_access:bool,is_owner:bool,keys:list<string>} */
    public function expected(CompanyUser $member): array
    {
        $creatorId = DB::table('companies')->where('id', $member->company_id)->value('created_by');
        $isOwner = $creatorId !== null && (int) $creatorId === (int) $member->user_id;
        $roleKeys = $this->legacy->rolePermissionsForCompanyUser($member);
        $fullAccess = $isOwner || in_array($member->role, ['owner', 'admin'], true) || in_array('*', $roleKeys, true);

        if ($fullAccess) {
            return ['full_access' => true, 'is_owner' => $isOwner, 'keys' => []];
        }

        $denied = $this->legacy->getUserDenyOverrideKeys($member);
        $allowed = array_diff(array_unique(array_merge($roleKeys, $this->legacy->getUserAllowOverrideKeys($member))), $denied);
        $keys = array_values(array_diff($this->translate($allowed), $this->translate($denied)));
        sort($keys);

        return ['full_access' => false, 'is_owner' => $isOwner, 'keys' => $keys];
    }

    /** Apply the legacy projection once per membership. */
    public function migrate(CompanyUser $member): void
    {
        $expected = $this->expected($member);

        DB::transaction(function () use ($member, $expected): void {
            $member->forceFill([
                'full_access' => $expected['full_access'],
                'is_owner' => $expected['is_owner'],
            ])->save();

            if ($expected['full_access']) {
                $member->assignedPermissions()->sync([]);

                return;
            }

            $ids = [];
            foreach ($expected['keys'] as $key) {
                $ids[] = Permission::query()->firstOrCreate(['key' => $key], $this->catalog->fromKey($key))->id;
            }
            $member->assignedPermissions()->sync($ids);
        });
    }

    /** @return array{full_access:bool,is_owner:bool,missing:list<string>,extra:list<string>} */
    public function compare(CompanyUser $member): array
    {
        $expected = $this->expected($member);
        $actual = $member->assignedPermissions()->pluck('permissions.key')->unique()->values()->all();
        $missing = $expected['full_access'] ? [] : array_values(array_diff($expected['keys'], $actual));
        $extra = $expected['full_access'] ? [] : array_values(array_diff($actual, $expected['keys']));
        sort($missing);
        sort($extra);

        return [
            'full_access' => (bool) $member->full_access === $expected['full_access'],
            'is_owner' => (bool) $member->is_owner === $expected['is_owner'],
            'missing' => $missing,
            'extra' => $extra,
        ];
    }

    /** @param iterable<string> $keys
     * @return list<string>
     */
    private function translate(iterable $keys): array
    {
        $map = (array) config('permission_renames', []);
        $catalog = array_fill_keys((array) config('permissions.permissions', []), true);
        $translated = [];
        foreach ($keys as $key) {
            if ($key === '*') {
                continue;
            }
            // Kunci yang masih dipakai rute tetap diberikan sampai Fase 5–6.
            if (isset($catalog[$key]) || ! array_key_exists($key, $map)) {
                $translated[] = $key;
            }
            array_push($translated, ...($map[$key] ?? []));
        }

        return array_values(array_unique($translated));
    }
}
