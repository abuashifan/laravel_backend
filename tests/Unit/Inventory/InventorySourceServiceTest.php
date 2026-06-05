<?php

namespace Tests\Unit\Inventory;

use App\Services\Inventory\InventorySourceService;
use Tests\TestCase;

class InventorySourceServiceTest extends TestCase
{
    public function test_source_payload_generated(): void
    {
        $svc = new InventorySourceService();
        $arr = $svc->buildSourcePayload('goods_receipt', 10, 'GR-2026-000001', 1);
        $this->assertSame('goods_receipt', $arr['source_type']);
        $this->assertSame(10, $arr['source_id']);
        $this->assertSame('GR-2026-000001', $arr['source_number']);
        $this->assertSame(1, $arr['source_revision']);
    }

    public function test_source_line_payload_generated(): void
    {
        $svc = new InventorySourceService();
        $arr = $svc->buildSourceLinePayload('goods_receipt_line', 99);
        $this->assertSame('goods_receipt_line', $arr['source_line_type']);
        $this->assertSame(99, $arr['source_line_id']);
    }
}

