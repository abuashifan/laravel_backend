<?php

namespace App\Support\SourceLink;

class SourceLinkFactory
{
    public static function fromSource(
        string $sourceType,
        mixed $source,
        ?string $sourceModule = null,
        ?string $sourceBatchId = null,
        bool $isSystemGenerated = true
    ): SourceLink {
        $arr = is_array($source) ? $source : self::objectToArray($source);

        $id = $arr['id'] ?? null;
        $sourceNumber = self::resolveSourceNumber($arr);
        $revision = isset($arr['revision_no']) ? (int) $arr['revision_no'] : 1;

        return SourceLink::make(
            $sourceType,
            $id,
            $sourceNumber,
            $revision,
            $sourceModule,
            $sourceBatchId,
            $isSystemGenerated,
            false,
            []
        );
    }

    public static function fromArray(array $data): SourceLink
    {
        return SourceLink::fromArray($data);
    }

    private static function resolveSourceNumber(array $arr): ?string
    {
        foreach (['document_number', 'invoice_number', 'journal_number', 'number'] as $key) {
            if (isset($arr[$key]) && $arr[$key] !== '') {
                return (string) $arr[$key];
            }
        }

        return null;
    }

    private static function objectToArray(mixed $source): array
    {
        if (! is_object($source)) {
            return [];
        }

        if (method_exists($source, 'toArray')) {
            return (array) $source->toArray();
        }

        return get_object_vars($source);
    }
}

