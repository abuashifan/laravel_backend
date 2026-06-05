<?php

namespace App\Support\Revision;

class RevisionSnapshot
{
    private const DEFAULT_IGNORED_FIELDS = [
        'created_at',
        'updated_at',
    ];

    public static function from(mixed $source, array $only = [], array $except = []): array
    {
        $data = self::normalize($source);

        if (! empty($only)) {
            $data = array_intersect_key($data, array_flip($only));
        }

        $except = array_values(array_unique(array_merge(self::DEFAULT_IGNORED_FIELDS, $except)));

        foreach ($except as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    public static function hasChanges(array $oldValues, array $newValues): bool
    {
        return self::changedFields($oldValues, $newValues) !== [];
    }

    public static function changedFields(array $oldValues, array $newValues): array
    {
        $diff = self::diff($oldValues, $newValues);
        return $diff['changed_fields'];
    }

    /**
     * @return array{changed_fields: array, old_values: array, new_values: array}
     */
    public static function diff(array $oldValues, array $newValues): array
    {
        $keys = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));
        $changed = [];

        foreach ($keys as $key) {
            $old = $oldValues[$key] ?? null;
            $new = $newValues[$key] ?? null;

            if ($old !== $new) {
                $changed[$key] = ['old' => $old, 'new' => $new];
            }
        }

        return [
            'changed_fields' => $changed,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ];
    }

    private static function normalize(mixed $source): array
    {
        if ($source === null) {
            return [];
        }

        if (is_array($source)) {
            return $source;
        }

        if (is_object($source)) {
            if (method_exists($source, 'toArray')) {
                return (array) $source->toArray();
            }

            return get_object_vars($source);
        }

        return [];
    }
}

