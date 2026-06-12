<?php

namespace Tests\Unit\Enums;

use App\Enums\SourceType;
use Tests\TestCase;
use ValueError;

class SourceTypeTest extends TestCase
{
    public function test_all_source_type_cases_have_non_empty_values(): void
    {
        foreach (SourceType::cases() as $case) {
            $this->assertNotSame('', trim($case->value), $case->name.' has an empty value.');
        }
    }

    public function test_source_link_config_values_are_defined_in_enum(): void
    {
        $enumValues = array_map(fn (SourceType $sourceType) => $sourceType->value, SourceType::cases());

        foreach ((array) config('source_links.source_types', []) as $sourceType) {
            $this->assertContains($sourceType, $enumValues, $sourceType.' is missing from SourceType enum.');
        }
    }

    public function test_stock_adjustment_value_can_be_resolved(): void
    {
        $this->assertSame(SourceType::STOCK_ADJUSTMENT, SourceType::from('stock_adjustment'));
    }

    public function test_invalid_value_throws_value_error(): void
    {
        $this->expectException(ValueError::class);

        SourceType::from('invalid_type');
    }
}
