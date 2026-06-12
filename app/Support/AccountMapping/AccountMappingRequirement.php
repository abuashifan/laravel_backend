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
        public readonly array $defaultAccountCodes,
        public readonly bool $visibleInSettings,
        public readonly ?string $settingsSection,
        public readonly int $settingsOrder,
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
            array_values((array) ($config['default_account_codes'] ?? [])),
            (bool) ($config['visible_in_settings'] ?? false),
            isset($config['settings_section']) ? (string) $config['settings_section'] : null,
            (int) ($config['settings_order'] ?? 999),
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
            'default_account_codes' => $this->defaultAccountCodes,
            'visible_in_settings' => $this->visibleInSettings,
            'settings_section' => $this->settingsSection,
            'settings_order' => $this->settingsOrder,
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
