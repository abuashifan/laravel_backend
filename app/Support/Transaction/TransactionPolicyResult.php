<?php

namespace App\Support\Transaction;

class TransactionPolicyResult
{
    public function __construct(
        private readonly bool $allowed,
        private readonly bool $warning,
        private readonly ?string $code,
        private readonly string $message,
        private readonly array $reasons = [],
        private readonly array $meta = [],
    ) {
    }

    public static function allow(string $message = 'Allowed', array $meta = []): self
    {
        return new self(true, false, 'TRANSACTION_ALLOWED', $message, [], $meta);
    }

    public static function deny(string $code, string $message, array $reasons = [], array $meta = []): self
    {
        return new self(false, false, $code, $message, $reasons, $meta);
    }

    public static function warning(string $code, string $message, array $reasons = [], array $meta = []): self
    {
        return new self(true, true, $code, $message, $reasons, $meta);
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function denied(): bool
    {
        return ! $this->allowed;
    }

    public function isWarning(): bool
    {
        return $this->warning;
    }

    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'warning' => $this->warning,
            'code' => $this->code,
            'message' => $this->message,
            'reasons' => $this->reasons,
            'meta' => $this->meta,
        ];
    }
}
