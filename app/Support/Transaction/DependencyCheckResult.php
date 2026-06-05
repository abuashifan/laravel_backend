<?php

namespace App\Support\Transaction;

class DependencyCheckResult
{
    public function __construct(
        private readonly bool $blocked,
        private readonly array $reasons = [],
        private readonly array $dependencies = [],
        private readonly array $meta = [],
    ) {
    }

    public static function clear(array $meta = []): self
    {
        return new self(false, [], [], $meta);
    }

    public static function blocked(array $reasons, array $dependencies = [], array $meta = []): self
    {
        return new self(true, $reasons, $dependencies, $meta);
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    public function isClear(): bool
    {
        return ! $this->blocked;
    }

    public function reasons(): array
    {
        return $this->reasons;
    }

    public function dependencies(): array
    {
        return $this->dependencies;
    }

    public function toArray(): array
    {
        return [
            'blocked' => $this->blocked,
            'reasons' => $this->reasons,
            'dependencies' => $this->dependencies,
            'meta' => $this->meta,
        ];
    }
}
