<?php

namespace App\Services\MasterData;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Services\AccountMapping\AccountMappingService;

class AccountMappingStorageService
{
    public function __construct(private readonly AccountMappingService $definitionService)
    {
    }

    public function syncDefaultMappingsFromConfig(): void
    {
        foreach ($this->definitionService->allRequirements() as $req) {
            AccountMapping::query()->updateOrCreate(
                ['mapping_key' => $req->key],
                [
                    'module' => $req->module,
                    'account_id' => null,
                    'is_required' => $req->required,
                    'is_active' => true,
                ]
            );
        }
    }

    public function list()
    {
        return AccountMapping::query()->orderBy('module')->orderBy('mapping_key')->get();
    }

    public function updateMapping(string $key, ?int $accountId): AccountMapping
    {
        if (! $this->definitionService->exists($key)) {
            throw ApiException::make('UNKNOWN_MAPPING_KEY', 'Unknown mapping key.', 404);
        }

        $mapping = AccountMapping::query()->where('mapping_key', $key)->first();
        if (! $mapping) {
            $req = $this->definitionService->requirement($key);
            $mapping = AccountMapping::query()->create([
                'mapping_key' => $key,
                'module' => $req?->module ?? $this->definitionService->requirement($key)?->module ?? 'unknown',
                'is_required' => (bool) ($req?->required ?? $this->definitionService->isRequired($key)),
                'is_active' => true,
            ]);
        }

        if ($accountId !== null) {
            $account = ChartOfAccount::query()->find($accountId);
            if (! $account) {
                throw ApiException::make('ACCOUNT_NOT_FOUND', 'Account not found.', 422);
            }

            if (! $account->is_active) {
                throw ApiException::make('ACCOUNT_INACTIVE', 'Account must be active.', 422);
            }

            $this->validateMappingAccountType($key, $account);
        }

        $mapping->account_id = $accountId;
        $mapping->save();

        return $mapping->refresh();
    }

    public function validateMappingAccountType(string $key, ChartOfAccount $account): void
    {
        $result = $this->definitionService->validateAccountTypeForKey($key, $account->account_type);
        if (! $result['valid']) {
            throw ApiException::make('ACCOUNT_TYPE_NOT_ALLOWED', 'Account type is not allowed for this mapping key.', 422, [
                'errors' => $result['errors'],
            ]);
        }
    }

    public function requiredMappingsComplete(?string $module = null): bool
    {
        $missing = $this->missingRequiredMappings($module);
        return $missing === [];
    }

    public function missingRequiredMappings(?string $module = null): array
    {
        $requiredKeys = $this->definitionService->requiredKeys($module);

        $existing = AccountMapping::query()
            ->whereIn('mapping_key', $requiredKeys)
            ->whereNotNull('account_id')
            ->pluck('mapping_key')
            ->all();

        return array_values(array_diff($requiredKeys, $existing));
    }
}

