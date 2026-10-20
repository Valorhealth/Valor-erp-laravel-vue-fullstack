<?php

namespace Tests\Unit;

use App\Models\BillingRecord;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\Documents\PowerOfAttorneyService;
use App\Services\Documents\QuotationDocumentEditor;
use App\Services\Documents\QuotationTemplateCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class QuotationDocumentEditorTest extends TestCase
{
    private QuotationDocumentEditor $editor;

    private Quotation $quotation;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('user_signature_assets', function (Blueprint $table): void {
            $table->increments('signature_asset_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('version_no');
            $table->boolean('is_current');
            $table->longText('image_base64');
            $table->string('image_sha256');
        });
        Schema::create('quotations', function (Blueprint $table): void {
            $table->increments('qt_id');
            $table->string('qt_no');
            $table->date('qt_date');
            $table->string('buyer_name');
            $table->string('bplus_customer_code')->nullable();
            $table->text('address_note')->nullable();
            $table->text('billing_address')->nullable();
            $table->text('delivery_address')->nullable();
            $table->boolean('standard_show_billing_address')->default(true);
            $table->boolean('standard_show_delivery_address')->default(false);
            $table->string('tax_id')->nullable();
            $table->string('created_by');
            $table->string('workflow_type')->default('normal');
            $table->string('price_vat_mode')->default('in_vat');
            $table->decimal('sale_discount_total')->default(0);
            $table->decimal('total_amount')->default(0);
        });
        Schema::create('billing_records', function (Blueprint $table): void {
            $table->increments('bill_id');
            $table->unsignedInteger('ref_qt_id');
            $table->string('status');
            $table->string('buyer_name');
            $table->string('bplus_customer_code')->nullable();
            $table->text('address_note')->nullable();
            $table->text('billing_address')->nullable();
            $table->text('delivery_address')->nullable();
            $table->boolean('standard_show_billing_address')->default(true);
            $table->boolean('standard_show_delivery_address')->default(false);
            $table->string('tax_id')->nullable();
            $table->string('created_by');
            $table->date('bill_date');
            $table->string('price_vat_mode')->default('in_vat');
            $table->decimal('sale_discount_total')->default(0);
            $table->decimal('total_amount')->default(0);
            $table->unsignedInteger('quotation_document_version_id')->nullable();
        });
        foreach (['quotation_items' => 'qt_item_id', 'bill_items' => 'item_id'] as $name => $key) {
            Schema::create($name, function (Blueprint $table) use ($name, $key): void {
                $table->increments($key);
                $table->unsignedInteger($name === 'bill_items' ? 'bill_id' : 'qt_id');
                $table->string('main_category');
                $table->string('sub_category');
                $table->string('unit_type');
                $table->unsignedInteger('quantity');
                $table->decimal('unit_price');
                $table->decimal('sale_discount_unit')->default(0);
                $table->decimal('line_total_before_discount');
                $table->decimal('line_total');
            });
        }
        Schema::create('master_products', function (Blueprint $table): void {
            $table->string('sku_code')->primary();
            $table->string('sku_name');
        });
        Schema::create('quotation_document_drafts', function (Blueprint $table): void {
            $table->increments('draft_id');
            $table->unsignedInteger('qt_id');
            $table->string('template_id');
            $table->string('document_mode');
            $table->json('payload');
            $table->unsignedInteger('lock_version');
            $table->unsignedInteger('updated_by_user_id');
            $table->string('updated_by');
            $table->timestamps();
        });
        Schema::create('quotation_document_versions', function (Blueprint $table): void {
            $table->increments('document_version_id');
            $table->unsignedInteger('qt_id');
            $table->unsignedInteger('bill_id');
            $table->string('template_id');
            $table->string('document_mode');
            $table->unsignedInteger('revision_no');
            $table->json('payload');
            $table->longText('pdf_base64');
            $table->string('pdf_sha256');
            $table->string('filename');
            $table->unsignedInteger('confirmed_by_user_id');
            $table->string('confirmed_by');
            $table->timestamp('created_at');
        });
        Schema::create('quotation_power_of_attorney_versions', function (Blueprint $table): void {
            $table->increments('authorization_version_id');
            $table->unsignedInteger('document_version_id');
            $table->unsignedInteger('qt_id');
            $table->unsignedInteger('bill_id');
            $table->string('authorization_no');
            $table->json('payload');
            $table->unsignedInteger('sale_user_id')->nullable();
            $table->string('sale_username');
            $table->longText('draft_pdf_base64');
            $table->string('draft_pdf_sha256');
            $table->string('filename');
            $table->timestamp('created_at');
        });
        Schema::create('document_signatures', function (Blueprint $table): void {
            $table->increments('signature_id');
            $table->unsignedInteger('bill_id');
            $table->unsignedInteger('qt_id');
            $table->unsignedInteger('revision_no');
            $table->string('signature_type');
            $table->string('signer_name');
            $table->string('signer_username');
            $table->string('signer_role');
            $table->text('signature_image_base64');
            $table->string('signature_image_sha256');
            $table->json('payload_snapshot');
            $table->string('payload_sha256');
            $table->timestamp('signed_at');
            $table->timestamp('revoked_at')->nullable();
        });
        Schema::create('edit_logs', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('bill_id');
            $table->string('edited_by');
            $table->timestamp('edit_time');
            $table->text('edit_summary');
        });

        $this->quotation = Quotation::query()->create([
            'qt_no' => 'QT-1', 'qt_date' => '2026-10-14', 'buyer_name' => 'Hospital',
            'created_by' => 'sale', 'total_amount' => 100,
        ]);
        $bill = BillingRecord::query()->create([
            'ref_qt_id' => $this->quotation->qt_id, 'status' => 'ฉบับร่าง',
            'buyer_name' => 'Hospital', 'created_by' => 'sale', 'bill_date' => '2026-10-14',
            'total_amount' => 100,
        ]);
        $line = [
            'main_category' => 'SKU-1', 'sub_category' => 'Original item', 'unit_type' => 'ชิ้น',
            'quantity' => 1, 'unit_price' => 100, 'sale_discount_unit' => 0,
            'line_total_before_discount' => 100, 'line_total' => 100,
        ];
        $this->quotation->items()->create($line);
        $bill->items()->create($line);
        DB::table('master_products')->insert([
            ['sku_code' => 'SKU-1', 'sku_name' => 'Original item'],
            ['sku_code' => 'SKU-2', 'sku_name' => 'Second item'],
        ]);
        $this->actor = User::query()->create(['username' => 'sale', 'first_name' => 'Sale', 'last_name' => 'Test', 'email' => 'sale@example.com']);
        $signature = base64_encode((string) file_get_contents(resource_path('assets/images/valorhealth.png')));
        DB::table('user_signature_assets')->insert([
            'user_id' => $this->actor->id,
            'version_no' => 1,
            'is_current' => true,
            'image_base64' => $signature,
            'image_sha256' => hash('sha256', base64_decode($signature, true)),
        ]);
        $this->editor = new QuotationDocumentEditor(app(DocumentService::class), new QuotationTemplateCatalog, app(PowerOfAttorneyService::class));
    }

    public function test_document_only_fields_do_not_change_commercial_data(): void
    {
        $payload = $this->editor->open($this->quotation, 'standard', 'original')['payload'];
        $payload['items'][0]['description'] = 'Hospital wording';
        $payload['items'][0]['pack'] = 'Box of 10';
        $payload['document']['contact'] = 'Contact sale';
        $version = $this->editor->confirm($this->quotation, $this->actor, 'standard', 'original', $payload);

        $this->assertSame('Hospital wording', $version->payload['items'][0]['description']);
        $this->assertSame('Original item', $this->quotation->items()->first()->sub_category);
        $this->assertEquals(100, $this->quotation->fresh()->total_amount);
        $this->assertEquals(100, $this->quotation->billingRecord->fresh()->total_amount);
        $this->assertDatabaseCount('edit_logs', 0);
        $this->assertDatabaseCount('quotation_power_of_attorney_versions', 1);
    }

    public function test_disabling_power_of_attorney_confirms_only_the_quotation(): void
    {
        $payload = $this->editor->open($this->quotation, 'standard', 'original')['payload'];
        $this->assertTrue($payload['power_of_attorney']['enabled']);
        $payload['power_of_attorney']['enabled'] = false;

        $version = $this->editor->confirm($this->quotation, $this->actor, 'standard', 'original', $payload);

        $this->assertFalse($version->payload['power_of_attorney']['enabled']);
        $this->assertDatabaseCount('quotation_power_of_attorney_versions', 0);
        $this->assertNull($version->powerOfAttorney()->first());
    }

    public function test_standard_quotation_uses_customer_form_values_and_only_edits_existing_text(): void
    {
        $this->quotation->billingRecord()->update([
            'bplus_customer_code' => 'AR-123',
            'address_note' => 'ที่อยู่จากฟอร์ม',
            'billing_address' => 'ฝ่ายการเงิน อาคาร A',
            'delivery_address' => 'คลังสินค้า อาคาร B',
            'standard_show_billing_address' => true,
            'standard_show_delivery_address' => true,
            'tax_id' => '1234567890123',
        ]);
        $this->quotation->unsetRelation('billingRecord');
        $payload = $this->editor->open($this->quotation, 'standard', 'original')['payload'];
        $this->assertSame('AR-123', $payload['customer']['code']);
        $this->assertSame('ที่อยู่จากฟอร์ม', $payload['customer']['address']);
        $this->assertTrue($payload['customer']['show_billing_address']);
        $this->assertTrue($payload['customer']['show_delivery_address']);
        $payload['customer']['code'] = 'AR-DOCUMENT';
        $payload['customer']['tax_id'] = '9999999999999';
        $payload['customer']['billing_address'] = 'ที่อยู่ออกบิลฉบับเอกสาร';
        $payload['document']['document_note'] = 'หมายเหตุฉบับแก้ไข';
        $payload['document']['confirmation_terms'] = 'เงื่อนไขฉบับแก้ไข';
        $normalized = $this->editor->normalizedPayload($this->quotation, 'standard', 'original', $payload);
        $html = app(DocumentService::class)->quotationEditorHtml($normalized);

        $this->assertSame('AR-DOCUMENT', $normalized['customer']['code']);
        $this->assertSame('ที่อยู่ออกบิลฉบับเอกสาร', $normalized['customer']['billing_address']);
        $this->assertStringContainsString('ที่อยู่ออกบิล:', $html);
        $this->assertStringContainsString('ที่อยู่ออกบิลฉบับเอกสาร', $html);
        $this->assertStringContainsString('ที่อยู่จัดส่ง:', $html);
        $this->assertStringContainsString('คลังสินค้า อาคาร B', $html);
        $this->assertStringNotContainsString('<strong>ที่อยู่:</strong> ที่อยู่ออกบิล:', $html);
        $this->assertStringContainsString('data-edit-path="document.document_note"', $html);
        $this->assertStringContainsString('data-edit-path="document.confirmation_terms"', $html);
        $this->assertStringContainsString('หมายเหตุฉบับแก้ไข', $html);
        $this->assertStringNotContainsString('data-edit-path="items.0.pack"', $html);
        $this->assertStringContainsString('AR-DOCUMENT', $html);
        $this->assertStringContainsString('9999999999999', $html);
    }

    public function test_approved_download_signs_only_the_quotation_and_keeps_the_power_of_attorney_unsigned(): void
    {
        $payload = $this->editor->open($this->quotation, QuotationTemplateCatalog::LUANGPHO_THAWEESAK, 'original')['payload'];
        $version = $this->editor->confirm($this->quotation, $this->actor, QuotationTemplateCatalog::LUANGPHO_THAWEESAK, 'original', $payload);
        $bill = $this->quotation->billingRecord->fresh();
        $documents = app(DocumentService::class);
        $authorizations = app(PowerOfAttorneyService::class);
        $authorization = $version->powerOfAttorney()->firstOrFail();
        $unsignedAuthorization = $authorizations->pdf($authorization, $bill);
        $original = base64_decode($version->pdf_base64, true);
        $this->assertSame($original, $documents->confirmedQuotationVersionPdf($version, $bill));

        $bill->signatures()->create([
            'qt_id' => $version->qt_id,
            'revision_no' => 1,
            'signature_type' => 'internal_quotation_approval',
            'signer_name' => 'หัวหน้า ทดสอบ',
            'signer_username' => 'approve',
            'signer_role' => 'approve',
            'signature_image_base64' => base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9SVK4AAAAASUVORK5CYII=')),
            'signature_image_sha256' => 'test',
            'payload_snapshot' => [
                'quotation_document' => ['document_version_id' => $version->document_version_id, 'pdf_sha256' => $version->pdf_sha256],
                'power_of_attorney' => ['authorization_version_id' => $authorization->authorization_version_id, 'draft_pdf_sha256' => $authorization->draft_pdf_sha256],
            ],
            'payload_sha256' => 'test',
            'signed_at' => now(),
        ]);

        $signed = $documents->confirmedQuotationVersionPdf($version, $bill);
        $this->assertStringStartsWith('%PDF-', $signed);
        $this->assertNotSame($original, $signed);
        $this->assertSame(hash('sha256', $original), $version->fresh()->pdf_sha256);
        $this->assertStringStartsWith('%PDF-', $unsignedAuthorization);
        $this->assertSame($unsignedAuthorization, $authorizations->pdf($authorization, $bill));

        $bill->forceFill(['status' => 'รอลูกค้ายืนยัน'])->save();
        $opened = $this->editor->open($this->quotation->fresh(), QuotationTemplateCatalog::LUANGPHO_THAWEESAK, 'original');
        $this->assertTrue($opened['locked']);
        $this->assertSame($version->document_version_id, $opened['approved_version_id']);
        $this->assertSame($version->payload, $opened['payload']);
        $this->expectException(ValidationException::class);
        $this->editor->saveDraft($this->quotation->fresh(), $this->actor, QuotationTemplateCatalog::LUANGPHO_THAWEESAK, 'original', $opened['payload'], 0);
    }

    public function test_approved_quotation_never_falls_back_to_an_unsigned_pdf(): void
    {
        $payload = $this->editor->open($this->quotation, QuotationTemplateCatalog::LUANGPHO_THAWEESAK, 'original')['payload'];
        $version = $this->editor->confirm($this->quotation, $this->actor, QuotationTemplateCatalog::LUANGPHO_THAWEESAK, 'original', $payload);
        $bill = $this->quotation->billingRecord->fresh();
        $bill->forceFill(['status' => 'รอลูกค้ายืนยัน'])->save();

        $this->expectException(HttpException::class);
        app(DocumentService::class)->confirmedQuotationVersionPdf($version, $bill);
    }

    public function test_quantity_price_discount_and_added_item_sync_to_bill_and_quotation(): void
    {
        $payload = $this->editor->open($this->quotation, 'standard', 'original')['payload'];
        $payload['items'][0]['quantity'] = 2;
        $payload['items'][0]['unit_price'] = 120;
        $payload['items'][0]['discount'] = 20;
        $payload['items'][] = [
            'row_key' => 'new-2', 'sku' => 'SKU-2', 'description' => 'Hospital name',
            'quantity' => 1, 'unit' => 'ชิ้น', 'pack' => 'Box', 'unit_price' => 50,
            'discount' => 0, 'remark' => '',
        ];
        $this->editor->confirm($this->quotation, $this->actor, 'standard', 'original', $payload);

        $this->assertDatabaseCount('bill_items', 2);
        $this->assertDatabaseCount('quotation_items', 2);
        $this->assertEquals(270, $this->quotation->fresh()->total_amount);
        $this->assertEquals(270, $this->quotation->billingRecord->fresh()->total_amount);
        $this->assertSame('Second item', $this->quotation->items()->where('main_category', 'SKU-2')->first()->sub_category);
        $this->assertDatabaseCount('edit_logs', 1);
    }

    public function test_removing_an_item_updates_both_records(): void
    {
        $line = [
            'main_category' => 'SKU-2', 'sub_category' => 'Second item', 'unit_type' => 'ชิ้น',
            'quantity' => 1, 'unit_price' => 50, 'sale_discount_unit' => 0,
            'line_total_before_discount' => 50, 'line_total' => 50,
        ];
        $this->quotation->items()->create($line);
        $this->quotation->billingRecord->items()->create($line);
        $this->quotation->billingRecord->forceFill(['total_amount' => 150])->save();
        $this->quotation->forceFill(['total_amount' => 150])->save();
        $payload = $this->editor->open($this->quotation->fresh(), 'standard', 'original')['payload'];
        $payload['items'] = [end($payload['items'])];
        $this->editor->confirm($this->quotation, $this->actor, 'standard', 'original', $payload);

        $this->assertDatabaseCount('bill_items', 1);
        $this->assertDatabaseCount('quotation_items', 1);
        $this->assertEquals(50, $this->quotation->fresh()->total_amount);
        $this->assertEquals(50, $this->quotation->billingRecord->fresh()->total_amount);
    }

    public function test_open_rebases_new_bill_prices_without_losing_hospital_wording(): void
    {
        $payload = $this->editor->open($this->quotation, 'standard', 'original')['payload'];
        $payload['items'][0]['description'] = 'Hospital wording';
        $this->editor->confirm($this->quotation, $this->actor, 'standard', 'original', $payload);
        $this->quotation->billingRecord->items()->first()->forceFill([
            'unit_price' => 150, 'line_total_before_discount' => 150, 'line_total' => 150,
        ])->save();
        $this->quotation->billingRecord->forceFill(['total_amount' => 150])->save();

        $reopened = $this->editor->open($this->quotation->fresh(), 'standard', 'original')['payload'];

        $this->assertEquals(150, $reopened['items'][0]['unit_price']);
        $this->assertSame('Hospital wording', $reopened['items'][0]['description']);
    }

    public function test_ex_vat_mode_rebases_the_document_and_adds_vat_to_grand_total(): void
    {
        $payload = $this->editor->open($this->quotation, 'standard', 'original')['payload'];
        $payload['items'][0]['description'] = 'Hospital wording';
        $this->editor->saveDraft($this->quotation, $this->actor, 'standard', 'original', $payload, 0);

        $this->quotation->billingRecord->forceFill([
            'price_vat_mode' => 'ex_vat',
            'total_amount' => 107,
        ])->save();

        $reopened = $this->editor->open($this->quotation->fresh(), 'standard', 'original')['payload'];
        $html = app(DocumentService::class)->quotationEditorHtml($reopened);

        $this->assertSame('ex_vat', $reopened['price_vat_mode']);
        $this->assertSame('Hospital wording', $reopened['items'][0]['description']);
        $this->assertStringContainsString('7.00', $html);
        $this->assertStringContainsString('107.00', $html);
    }
}
