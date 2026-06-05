<?php

namespace App\Support\AccountMapping;

class AccountMappingRequirement
{
    private function __construct(
        public readonly string $key,
        public readonly string $module,
        public readonly string $label,
        public readonly bool $required,
        public readonly array $accountTypes,
        public readonly ?string $description,
    ) {
    }

    public static function fromConfig(string $key, array $config): self
    {
        return new self(
            $key,
            (string) ($config['module'] ?? ''),
            (string) ($config['label'] ?? $key),
            (bool) ($config['required'] ?? false),
            (array) ($config['account_types'] ?? []),
            isset($config['description']) ? (string) $config['description'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'module' => $this->module,
            'label' => $this->label,
            'required' => $this->required,
            'account_types' => $this->accountTypes,
            'description' => $this->description,
        ];
    }

    public function allowsAccountType(?string $accountType): bool
    {
        if ($accountType === null || trim($accountType) === '') {
            return false;
        }

        return in_array(trim($accountType), $this->accountTypes, true);
    }

    public function isRequired(): bool
    {
        return $this->required;
    }
}

