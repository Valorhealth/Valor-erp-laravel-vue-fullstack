<?php

namespace Tests\Unit;

use App\Models\BillingRecord;
use App\Models\Quotation;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentDownloadAudit;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentDownloadAuditTest extends TestCase
{
    #[Test]
    public function it_captures_the_rendered_revision_and_file_hash(): void
    {
        $request = Request::create('/quotation/pdf');
        $quotation = new Quotation(['qt_id' => 12, 'qt_no' => 'QT26090001', 'revision_no' => 1]);
        $bill = new BillingRecord(['bill_id' => 42, 'revision_no' => 3]);
        DocumentDownloadAudit::capture($request, 'quotation_copy', $quotation, $bill);
        $payload = app(AuditLogger::class)->responseRequestData($request, response('pdf-bytes'), ['copy' => 1]);
        $this->assertSame(3, $payload['document_download']['revision_no']);
        $this->assertSame('QT26090001', $payload['document_download']['quotation_no']);
        $this->assertSame(42, $payload['document_download']['bill_id']);
        $this->assertSame(hash('sha256', 'pdf-bytes'), $payload['document_download']['sha256']);
        $this->assertSame(1, $payload['copy']);
    }

    #[Test]
    public function failed_or_head_requests_do_not_count_as_downloads(): void
    {
        foreach ([['GET', 403], ['GET', 500], ['HEAD', 200]] as [$method, $status]) {
            $request = Request::create('/quotation/pdf', $method);
            DocumentDownloadAudit::capture($request, 'quotation_original');
            $payload = app(AuditLogger::class)->responseRequestData($request, response('', $status));
            $this->assertArrayNotHasKey('document_download', $payload);
        }
    }

    #[Test]
    public function client_input_cannot_forge_download_evidence(): void
    {
        $request = Request::create('/quotation/pdf', 'GET', ['document_download' => ['revision_no' => 99]]);
        $payload = app(AuditLogger::class)->responseRequestData($request, response(''));
        $this->assertArrayNotHasKey('document_download', $payload);
    }

    #[Test]
    public function batch_export_keeps_every_document_and_repeat_downloads(): void
    {
        $request = Request::create('/bills/export-quotations.pdf', 'POST');
        $documents = [['bill_id' => 1, 'revision_no' => 2], ['bill_id' => 2, 'revision_no' => 4]];
        $request->attributes->set('document_audit', ['type' => 'quotation_batch', 'documents' => $documents]);
        foreach (range(1, 2) as $attempt) {
            $payload = app(AuditLogger::class)->responseRequestData($request, response('combined-pdf'));
            $this->assertSame($documents, $payload['document_download']['documents']);
        }
    }
}
