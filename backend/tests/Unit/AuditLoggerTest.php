<?php

namespace Tests\Unit;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    #[Test]
    public function it_redacts_credentials_and_keeps_business_fields(): void
    {
        $request = Request::create('/api/v1/auth/login', 'POST', [
            'username' => 'sale01',
            'password' => 'top-secret',
            'password_confirmation' => 'top-secret',
            'buyer_name' => 'Example Hospital',
            'nested' => ['token' => 'secret-token', 'quantity' => 3],
        ]);

        $payload = app(AuditLogger::class)->sanitizedRequestData($request);

        $this->assertSame('sale01', $payload['username']);
        $this->assertSame('[REDACTED]', $payload['password']);
        $this->assertSame('[REDACTED]', $payload['password_confirmation']);
        $this->assertSame('[REDACTED]', $payload['nested']['token']);
        $this->assertSame(3, $payload['nested']['quantity']);
        $this->assertSame('Example Hospital', $payload['buyer_name']);
    }

    #[Test]
    public function it_stores_only_uploaded_file_metadata(): void
    {
        $request = Request::create('/api/v1/receipt-ocr', 'POST');
        $request->files->set('receipt', UploadedFile::fake()->createWithContent('receipt.jpg', 'fake-jpeg-content'));

        $payload = app(AuditLogger::class)->sanitizedRequestData($request);

        $this->assertSame('receipt.jpg', $payload['receipt']['name']);
        $this->assertNotEmpty($payload['receipt']['mime_type']);
        $this->assertGreaterThan(0, $payload['receipt']['size_bytes']);
    }

    #[Test]
    public function it_handles_an_uploaded_file_that_was_moved_before_logging(): void
    {
        $request = Request::create('/api/v1/budget/analyze', 'POST');
        $file = UploadedFile::fake()->createWithContent('budget.xlsx', 'fake-excel-content');
        $request->files->set('file', $file);
        unlink($file->getPathname());

        $payload = app(AuditLogger::class)->sanitizedRequestData($request);

        $this->assertSame('budget.xlsx', $payload['file']['name']);
        $this->assertNull($payload['file']['size_bytes']);
    }
}
