<?php

namespace App\Support\SourceLink;

use InvalidArgumentException;

class SourceLink
{
    private function __construct(
        public readonly string $sourceType,
        public readonly int|string|null $sourceId,
        public readonly ?string $sourceNumber,
        public readonly ?int $sourceRevision,
        public readonly ?string $sourceModule,
        public readonly ?string $sourceBatchId,
        public readonly bool $isSystemGenerated,
        public readonly bool $isObsolete,
        public readonly array $metadata,
    ) {
    }

    public static function make(
        string $sourceType,
        int|string|null $sourceId = null,
        ?string $sourceNumber = null,
        ?int $sourceRevision = null,
        ?string $sourceModule = null,
        ?string $sourceBatchId = null,
        bool $isSystemGenerated = true,
        bool $isObsolete = false,
        array $metadata = []
    ): self {
        self::assertValid($sourceType, $sourceModule);

        return new self(
            $sourceType,
            $sourceId,
            $sourceNumber,
            $sourceRevision,
            $sourceModule,
            $sourceBatchId,
            $isSystemGenerated,
            $isObsolete,
            $metadata
        );
    }

    public static function fromArray(array $data): self
    {
        return self::make(
            (string) ($data['source_type'] ?? ''),
            $data['source_id'] ?? null,
            $data['source_number'] ?? null,
            isset($data['source_revision']) ? (int) $data['source_revision'] : null,
            $data['source_module'] ?? null,
            $data['source_batch_id'] ?? null,
            (bool) ($data['is_system_generated'] ?? true),
            (bool) ($data['is_obsolete'] ?? false),
            (array) ($data['metadata'] ?? [])
        );
    }

    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'source_number' => $this->sourceNumber,
            'source_revision' => $this->sourceRevision,
            'source_module' => $this->sourceModule,
            'source_batch_id' => $this->sourceBatchId,
            'is_system_generated' => $this->isSystemGenerated,
            'is_obsolete' => $this->isObsolete,
            'metadata' => $this->metadata,
        ];
    }

    public function markObsolete(): self
    {
        return new self(
            $this->sourceType,
            $this->sourceId,
            $this->sourceNumber,
            $this->sourceRevision,
            $this->sourceModule,
            $this->sourceBatchId,
            $this->isSystemGenerated,
            true,
            $this->metadata
        );
    }

    public function withRevision(int $revision): self
    {
        return new self(
            $this->sourceType,
            $this->sourceId,
            $this->sourceNumber,
            $revision,
            $this->sourceModule,
            $this->sourceBatchId,
            $this->isSystemGenerated,
            $this->isObsolete,
            $this->metadata
        );
    }

    public function withBatch(?string $batchId): self
    {
        return new self(
            $this->sourceType,
            $this->sourceId,
            $this->sourceNumber,
            $this->sourceRevision,
            $this->sourceModule,
            $batchId,
            $this->isSystemGenerated,
            $this->isObsolete,
            $this->metadata
        );
    }

    public function isFrom(string $sourceType): bool
    {
        return $this->sourceType === $sourceType;
    }

    public function isSameSource(SourceLink $other): bool
    {
        return $this->sourceType === $other->sourceType && $this->sourceId === $other->sourceId;
    }

    private static function assertValid(string $sourceType, ?string $sourceModule): void
    {
        if ($sourceType === '') {
            throw new InvalidArgumentException('SOURCE_TYPE_REQUIRED');
        }

        $strict = (bool) config('source_links.strict', true);

        if ($strict && ! SourceType::exists($sourceType)) {
            throw new InvalidArgumentException('UNKNOWN_SOURCE_TYPE');
        }

        if ($sourceModule !== null && $sourceModule !== '' && $strict && ! SourceModule::exists($sourceModule)) {
            throw new InvalidArgumentException('UNKNOWN_SOURCE_MODULE');
        }
    }
}

