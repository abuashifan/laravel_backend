<?php

namespace App\Services\AccountMapping;

use App\Support\AccountMapping\AccountMappingKey;
use App\Support\AccountMapping\AccountMappingModule;

class AccountMappingValidator
{
    public function __construct(private readonly AccountMappingService $service)
    {
    }

    public function validateProvidedMappings(array $mappings): array
    {
        $errors = [];

        foreach (array_keys($mappings) as $key) {
            if (! $this->service->exists((string) $key)) {
                $errors[] = 'UNKNOWN_MAPPING_KEY:'.$key;
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => [],
        ];
    }

    public function validateModuleRequirements(string $module, array $providedMappingKeys): array
    {
        if (! AccountMappingModule::exists($module)) {
            return [
                'valid' => false,
                'missing' => [],
                'errors' => ['UNKNOWN_MAPPING_MODULE'],
            ];
        }

        return $this->service->validateRequiredKeys($providedMappingKeys, $module);
    }

    public function validateAccountType(string $mappingKey, ?string $accountType): array
    {
        return $this->service->validateAccountTypeForKey($mappingKey, $accountType);
    }
}

