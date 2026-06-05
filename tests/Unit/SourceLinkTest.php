<?php

namespace Tests\Unit;

use App\Support\SourceLink\SourceLink;
use App\Support\SourceLink\SourceLinkFactory;
use App\Support\SourceLink\SourceModule;
use App\Support\SourceLink\SourceType;
use Tests\TestCase;

class SourceLinkTest extends TestCase
{
    public function test_source_type_list_contains_sales_invoice(): void
    {
        $this->assertTrue(SourceType::exists(SourceType::SALES_INVOICE));
    }

    public function test_source_module_list_contains_sales(): void
    {
        $this->assertTrue(SourceModule::exists(SourceModule::SALES));
    }

    public function test_source_link_make_returns_expected_array(): void
    {
        $link = SourceLink::make(SourceType::SALES_INVOICE, 15, 'SI-2026-000015', 2, SourceModule::SALES);

        $this->assertSame([
            'source_type' => 'sales_invoice',
            'source_id' => 15,
            'source_number' => 'SI-2026-000015',
            'source_revision' => 2,
            'source_module' => 'sales',
            'source_batch_id' => null,
            'is_system_generated' => true,
            'is_obsolete' => false,
            'metadata' => [],
        ], $link->toArray());
    }

    public function test_source_link_from_array_works(): void
    {
        $link = SourceLink::fromArray([
            'source_type' => SourceType::SALES_INVOICE,
            'source_id' => 15,
            'source_number' => 'SI-2026-000015',
            'source_revision' => 2,
            'source_module' => SourceModule::SALES,
            'is_system_generated' => true,
            'is_obsolete' => false,
            'metadata' => ['k' => 'v'],
        ]);

        $this->assertSame(15, $link->sourceId);
        $this->assertSame('SI-2026-000015', $link->sourceNumber);
    }

    public function test_mark_obsolete_sets_is_obsolete_true(): void
    {
        $link = SourceLink::make(SourceType::SALES_INVOICE, 15)->markObsolete();
        $this->assertTrue($link->isObsolete);
    }

    public function test_with_revision_changes_source_revision(): void
    {
        $link = SourceLink::make(SourceType::SALES_INVOICE, 15)->withRevision(3);
        $this->assertSame(3, $link->sourceRevision);
    }

    public function test_with_batch_sets_source_batch_id(): void
    {
        $link = SourceLink::make(SourceType::SALES_INVOICE, 15)->withBatch('BATCH-1');
        $this->assertSame('BATCH-1', $link->sourceBatchId);
    }

    public function test_is_from_returns_true_for_matching_source_type(): void
    {
        $link = SourceLink::make(SourceType::SALES_INVOICE, 15);
        $this->assertTrue($link->isFrom(SourceType::SALES_INVOICE));
    }

    public function test_is_same_source_true_for_same_type_and_id(): void
    {
        $a = SourceLink::make(SourceType::SALES_INVOICE, 15);
        $b = SourceLink::make(SourceType::SALES_INVOICE, 15);
        $this->assertTrue($a->isSameSource($b));
    }

    public function test_is_same_source_false_for_different_id(): void
    {
        $a = SourceLink::make(SourceType::SALES_INVOICE, 15);
        $b = SourceLink::make(SourceType::SALES_INVOICE, 16);
        $this->assertFalse($a->isSameSource($b));
    }

    public function test_factory_reads_document_number_first(): void
    {
        $link = SourceLinkFactory::fromSource(SourceType::SALES_INVOICE, [
            'id' => 15,
            'document_number' => 'SI-2026-000015',
            'invoice_number' => 'INV-1',
            'revision_no' => 2,
        ], SourceModule::SALES);

        $this->assertSame('SI-2026-000015', $link->sourceNumber);
        $this->assertSame(2, $link->sourceRevision);
    }

    public function test_factory_invoice_number_fallback_works(): void
    {
        $link = SourceLinkFactory::fromSource(SourceType::SALES_INVOICE, [
            'id' => 15,
            'invoice_number' => 'INV-1',
        ], SourceModule::SALES);

        $this->assertSame('INV-1', $link->sourceNumber);
        $this->assertSame(1, $link->sourceRevision);
    }

    public function test_factory_journal_number_fallback_works(): void
    {
        $link = SourceLinkFactory::fromSource(SourceType::MANUAL_JOURNAL, [
            'id' => 15,
            'journal_number' => 'JV-1',
        ], SourceModule::JOURNAL);

        $this->assertSame('JV-1', $link->sourceNumber);
    }

    public function test_factory_default_revision_is_1_when_missing(): void
    {
        $link = SourceLinkFactory::fromSource(SourceType::SALES_INVOICE, ['id' => 15], SourceModule::SALES);
        $this->assertSame(1, $link->sourceRevision);
    }

    public function test_to_array_includes_system_generated_and_obsolete_flags(): void
    {
        $link = SourceLink::make(SourceType::SYSTEM, null, null, 1, SourceModule::SYSTEM, null, true, true);
        $arr = $link->toArray();

        $this->assertArrayHasKey('is_system_generated', $arr);
        $this->assertArrayHasKey('is_obsolete', $arr);
        $this->assertTrue($arr['is_system_generated']);
        $this->assertTrue($arr['is_obsolete']);
    }
}

