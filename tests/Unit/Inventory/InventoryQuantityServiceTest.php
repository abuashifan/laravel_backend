<?php

namespace Tests\Unit\Inventory;

use App\Exceptions\ApiException;
use App\Services\Inventory\InventoryConfigService;
use App\Services\Inventory\InventoryQuantityService;
use Tests\TestCase;

class InventoryQuantityServiceTest extends TestCase
{
    public function test_positive_quantity_accepted_and_normalized(): void
    {
        $svc = new InventoryQuantityService(new InventoryConfigService());
        $svc->assertPositiveQuantity(1);
        $this->assertSame(1.0, $svc->normalizeQuantity('1.0000'));
    }

    public function test_zero_or_negative_rejected_when_positive_required(): void
    {
        $svc = new InventoryQuantityService(new InventoryConfigService());
        $this->expectException(ApiException::class);
        $svc->assertPositiveQuantity(0);
    }

    public function test_remaining_quantity_calculation(): void
    {
        $svc = new InventoryQuantityService(new InventoryConfigService());
        $this->assertSame(7.5, $svc->calculateRemainingQuantity(10, 2.5));
    }
}

