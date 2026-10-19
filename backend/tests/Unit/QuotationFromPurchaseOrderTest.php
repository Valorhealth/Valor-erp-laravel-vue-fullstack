<?php

namespace Tests\Unit;

use App\Models\BillingRecord;
use App\Services\Quotations\QuotationFromPurchaseOrder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QuotationFromPurchaseOrderTest extends TestCase
{
    #[Test]
    public function it_copies_commercial_fields_without_bill_identity_or_workflow_state(): void
    {
        $bill = new BillingRecord;
        $bill->setRawAttributes([
            'bill_id' => 42,
            'buyer_name' => 'Test Hospital',
            'customer_po_no' => 'PO-77',
            'total_amount' => '1200.00',
            'status' => 'รอแอดมินส่งออก',
            'tax_qr_token' => 'private-token',
        ]);

        $attributes = QuotationFromPurchaseOrder::attributesFromBill($bill);

        $this->assertSame('Test Hospital', $attributes['buyer_name']);
        $this->assertSame('PO-77', $attributes['customer_po_no']);
        $this->assertSame('1200.00', $attributes['total_amount']);
        $this->assertArrayNotHasKey('bill_id', $attributes);
        $this->assertArrayNotHasKey('status', $attributes);
        $this->assertArrayNotHasKey('tax_qr_token', $attributes);
    }

    #[Test]
    public function it_copies_item_prices_without_reusing_the_bill_item_id(): void
    {
        $attributes = QuotationFromPurchaseOrder::itemAttributes([
            'item_id' => 99,
            'bill_id' => 42,
            'main_category' => 'FIBROSCAN',
            'customer_product_name' => 'Hospital label',
            'quantity' => 2,
            'unit_price' => '600.00',
            'line_total' => '1200.00',
        ]);

        $this->assertSame('FIBROSCAN', $attributes['main_category']);
        $this->assertSame('Hospital label', $attributes['customer_product_name']);
        $this->assertSame('1200.00', $attributes['line_total']);
        $this->assertArrayNotHasKey('item_id', $attributes);
        $this->assertArrayNotHasKey('bill_id', $attributes);
    }
}
