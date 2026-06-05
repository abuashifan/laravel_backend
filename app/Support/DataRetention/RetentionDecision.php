<?php

namespace App\Support\DataRetention;

class RetentionDecision
{
    private function __construct(
        public readonly string $action,
        private readonly bool $allowed,
        public readonly string $code,
        public readonly string $message,
        public readonly array $reasons = [],
        public readonly array $meta = [],
    ) {
    }

    public static function keep(string $message = 'Keep data.', array $meta = []): self
    {
        return new self(RetentionAction::KEEP, true, 'KEEP', $message, [], $meta);
    }

    public static function hide(string $message = 'Hide from normal UI.', array $meta = []): self
    {
        return new self(RetentionAction::HIDE, true, 'HIDE', $message, [], $meta);
    }

    public static function archiveEligible(string $message, array $reasons = [], array $meta = []): self
    {
        return new self(RetentionAction::ARCHIVE_ELIGIBLE, true, 'ARCHIVE_ELIGIBLE', $message, $reasons, $meta);
    }

    public static function purgeEligible(string $message, array $reasons = [], array $meta = []): self
    {
        return new self(RetentionAction::PURGE_ELIGIBLE, true, 'PURGE_ELIGIBLE', $message, $reasons, $meta);
    }

    public static function block(string $code, string $message, array $reasons = [], array $meta = []): self
    {
        return new self(RetentionAction::BLOCK, false, $code, $message, $reasons, $meta);
    }

    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'allowed' => $this->allowed,
            'code' => $this->code,
            'message' => $this->message,
            'reasons' => $this->reasons,
            'meta' => $this->meta,
        ];
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function blocked(): bool
    {
        return ! $this->allowed;
    }
}

