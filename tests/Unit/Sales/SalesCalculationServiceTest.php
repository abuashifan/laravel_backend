<?php

namespace Tests\Unit\Sales;

use App\Services\Sales\SalesCalculationService;
use InvalidArgumentException;
use Tests\TestCase;

class SalesCalculationServiceTest extends TestCase
{
    public function test_percent_discount_line(): void
    {
        $line = $this->service()->calculateLine([
            'quantity' => 2,
            'unit_price' => 100,
            'discount_type' => 'percent',
            'discount_value' => 10,
        ]);

        $this->assertSame(200.0, $line['gross_amount']);
        $this->assertSame(20.0, $line['discount_amount']);
        $this->assertSame(180.0, $line['line_total']);
    }

    public function test_fixed_discount_line(): void
    {
        $line = $this->service()->calculateLine([
            'quantity' => 2,
            'unit_price' => 100,
            'discount_type' => 'fixed_amount',
            'discount_value' => 25,
        ]);

        $this->assertSame(25.0, $line['discount_amount']);
        $this->assertSame(175.0, $line['line_total']);
    }

    public function test_header_percent_discount(): void
    {
        $document = $this->service()->calculateDocument([
            ['quantity' => 2, 'unit_price' => 100],
        ], [
            'header_discount_type' => 'percent',
            'header_discount_value' => 10,
        ]);

        $this->assertSame(20.0, $document['header_discount_amount']);
        $this->assertSame(180.0, $document['grand_total']);
    }

    public function test_header_fixed_discount(): void
    {
        $document = $this->service()->calculateDocument([
            ['quantity' => 2, 'unit_price' => 100],
        ], [
            'header_discount_type' => 'fixed_amount',
            'header_discount_value' => 40,
        ]);

        $this->assertSame(40.0, $document['header_discount_amount']);
        $this->assertSame(160.0, $document['grand_total']);
    }

    public function test_discount_cannot_exceed_base(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DISCOUNT_EXCEEDS_BASE');

        $this->service()->calculateDiscountAmount('fixed_amount', 101, 100);
    }

    public function test_tax_after_discount(): void
    {
        $line = $this->service()->calculateLine([
            'quantity' => 1,
            'unit_price' => 100,
            'discount_type' => 'percent',
            'discount_value' => 10,
            'tax_rate' => 11,
        ]);

        $this->assertSame(90.0, $line['subtotal_after_discount']);
        $this->assertSame(9.9, $line['tax_amount']);
        $this->assertSame(99.9, $line['line_total']);
    }

    private function service(): SalesCalculationService
    {
        return new SalesCalculationService();
    }
}
