<?php

namespace Tests\Unit;

use App\Models\BillingRecord;
use App\Models\User;
use App\Services\Billing\BillingCancellationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BillingCancellationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for database service tests.');
        }
        Schema::create('quotations', function (Blueprint $table): void { $table->increments('qt_id'); $table->string('status'); $table->timestamp('canceled_at')->nullable(); $table->string('canceled_by')->nullable(); $table->text('cancellation_reason')->nullable(); });
        Schema::create('billing_records', function (Blueprint $table): void { $table->increments('bill_id'); $table->unsignedInteger('ref_qt_id')->nullable(); $table->string('status'); $table->timestamp('canceled_at')->nullable(); $table->string('canceled_by')->nullable(); $table->text('cancellation_reason')->nullable(); });
        Schema::create('bill_items', function (Blueprint $table): void { $table->increments('item_id'); $table->unsignedInteger('bill_id'); });
        Schema::create('billing_documents', function (Blueprint $table): void {
            $table->id('doc_id'); $table->unsignedInteger('bill_id'); $table->string('document_type')->default('customer_po');
            $table->string('file_name')->nullable(); $table->string('mime_type')->nullable(); $table->binary('file_bytes')->nullable();
            $table->string('uploaded_by')->nullable(); $table->timestamp('uploaded_at')->nullable();
        });
    }

    #[Test]
    public function it_cancels_without_deleting_the_bill_quotation_or_items(): void
    {
        $quotationId = DB::table('quotations')->insertGetId(['status' => 'ร่างใบเสนอราคา']);
        $billId = DB::table('billing_records')->insertGetId(['ref_qt_id' => $quotationId, 'status' => 'รอแอดมินส่งออก']);
        DB::table('bill_items')->insert(['bill_id' => $billId]);
        $actor = new User(['username' => 'admin']);

        $result = app(BillingCancellationService::class)->cancel($actor, BillingRecord::query()->findOrFail($billId), 'ลูกค้ายกเลิกคำสั่งซื้อ');

        $this->assertSame('ยกเลิกบิล', $result->status);
        $this->assertSame(1, DB::table('billing_records')->count());
        $this->assertSame(1, DB::table('bill_items')->count());
        $this->assertSame('ยกเลิกใบเสนอราคา', DB::table('quotations')->value('status'));
    }
}
