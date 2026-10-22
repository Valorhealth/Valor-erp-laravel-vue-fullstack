<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\QuotationController;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

final class QuotationControllerPayloadRulesTest extends TestCase
{
    #[Test]
    public function it_preserves_every_inline_editable_standard_document_field(): void
    {
        $reflection = new ReflectionClass(QuotationController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $rulesMethod = $reflection->getMethod('editorPayloadRules');

        /** @var array<string, array<int, mixed>> $rules */
        $rules = $rulesMethod->invoke($controller);

        foreach ([
            'payload.document.title',
            'payload.document.english_title',
            'payload.document.company_name',
            'payload.document.company_address',
            'payload.document.company_contact',
            'payload.document.company_tax_id',
            'payload.document.branch',
            'payload.customer.address',
            'payload.customer.billing_address',
            'payload.customer.delivery_address',
            'payload.customer.show_billing_address',
            'payload.customer.show_delivery_address',
            'payload.customer.tax_id',
            'payload.layout.title_alignment',
            'payload.layout.logo_alignment',
            'payload.document.summary_gross_label',
            'payload.document.summary_total_value',
            'payload.document.summary_amount_words',
            'payload.commercial_baseline',
        ] as $field) {
            $this->assertArrayHasKey($field, $rules, "Missing validation rule for {$field}; Laravel would silently remove the edit.");
        }
    }
}
