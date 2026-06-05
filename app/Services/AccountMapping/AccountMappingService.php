<?php

namespace App\Services\AccountMapping;

use App\Support\AccountMapping\AccountMappingKey;
use App\Support\AccountMapping\AccountMappingModule;
use App\Support\AccountMapping\AccountMappingRequirement;
use RuntimeException;

class AccountMappingService
{
    /**
     * @return array<int, AccountMappingRequirement>
     */
    public function allRequirements(): array
    {
        $req = [];
        foreach ((array) config('account_mappings.required_mappings', []) as $key => $cfg) {
            $req[] = AccountMappingRequirement::fromConfig((string) $key, (array) $cfg);
        }
        return $req;
    }

    public function requirement(string $key): ?AccountMappingRequirement
    {
        $all = (array) config('account_mappings.required_mappings', []);
        $cfg = $all[$key] ?? null;
        if (! is_array($cfg)) {
            return null;
        }

        return AccountMappingRequirement::fromConfig($key, $cfg);
    }

    /**
     * @return array<int, AccountMappingRequirement>
     */
    public function requirementsForModule(string $module): array
    {
        if (! AccountMappingModule::exists($module)) {
            return [];
        }

        return array_values(array_filter(
            $this->allRequirements(),
            fn (AccountMappingRequirement $r) => $r->module === $module
        ));
    }

    public function requiredKeys(?string $module = null): array
    {
        if ($module === null) {
            return AccountMappingKey::requiredKeys();
        }

        return array_values(array_filter(
            AccountMappingKey::requiredKeys(),
            fn (string $k) => AccountMappingKey::moduleFor($k) === $module
        ));
    }

    public function optionalKeys(?string $module = null): array
    {
        if ($module === null) {
            return AccountMappingKey::optionalKeys();
        }

        return array_values(array_filter(
            AccountMappingKey::optionalKeys(),
            fn (string $k) => AccountMappingKey::moduleFor($k) === $module
        ));
    }

    public function exists(string $key): bool
    {
        return AccountMappingKey::exists($key);
    }

    public function isRequired(string $key): bool
    {
        $all = (array) config('account_mappings.required_mappings', []);
        return (bool) ($all[$key]['required'] ?? false);
    }

    public function allowedAccountTypes(string $key): array
    {
        $all = (array) config('account_mappings.required_mappings', []);
        return (array) ($all[$key]['account_types'] ?? []);
    }

    public function validateRequiredKeys(array $providedMappingKeys, ?string $module = null): array
    {
        $required = $this->requiredKeys($module);
        $missing = array_values(array_diff($required, $providedMappingKeys));

        return [
            'valid' => $missing === [],
            'missing' => $missing,
            'errors' => $missing === [] ? [] : ['ACCOUNT_MAPPING_MISSING'],
        ];
    }

    public function validateAccountTypeForKey(string $key, ?string $accountType): array
    {
        if (! $this->exists($key)) {
            return [
                'valid' => false,
                'errors' => ['UNKNOWN_MAPPING_KEY'],
            ];
        }

        $req = $this->requirement($key);
        if (! $req) {
            return [
                'valid' => false,
                'errors' => ['UNKNOWN_MAPPING_KEY'],
            ];
        }

        if (! $req->allowsAccountType($accountType)) {
            return [
                'valid' => false,
                'errors' => ['ACCOUNT_TYPE_NOT_ALLOWED'],
            ];
        }

        return [
            'valid' => true,
            'errors' => [],
        ];
    }

    public function getAccountId(string $key): ?int
    {
        throw new RuntimeException('Account mapping storage is not implemented until Chart of Accounts is available.');
    }

    public function requireAccountId(string $key): int
    {
        throw new RuntimeException('Account mapping storage is not implemented until Chart of Accounts is available.');
    }

    public function mappingCompleteForModule(string $module): bool
    {
        throw new RuntimeException('Account mapping storage is not implemented until Chart of Accounts is available.');
    }
}
