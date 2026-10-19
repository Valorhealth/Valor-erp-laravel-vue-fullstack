<?php

namespace App\Services\Documents;

use App\Models\BillingRecord;
use App\Models\Quotation;
use App\Models\QuotationDocumentVersion;
use App\Models\QuotationPowerOfAttorneyVersion;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

final class PowerOfAttorneyService
{
    private readonly PowerOfAttorneyTemplateCatalog $templates;

    public function __construct(
        private readonly DocumentService $documents,
        ?PowerOfAttorneyTemplateCatalog $templates = null,
    ) {
        $this->templates = $templates ?? new PowerOfAttorneyTemplateCatalog;
    }

    public function createForQuotationVersion(Quotation $quotation, BillingRecord $bill, QuotationDocumentVersion $version): QuotationPowerOfAttorneyVersion
    {
        $sale = $quotation->creator_user_id
            ? User::query()->find($quotation->creator_user_id)
            : User::query()->where('username', $quotation->created_by)->first();
        if (! $sale) {
            throw ValidationException::withMessages(['power_of_attorney' => 'ไม่พบบัญชี Sale เจ้าของใบเสนอราคา จึงสร้างหนังสือมอบอำนาจไม่ได้']);
        }
        $payload = $this->payload($quotation, $bill, $version, $sale);
        $pdf = $this->documents->powerOfAttorneyPdf($payload, '');

        return QuotationPowerOfAttorneyVersion::query()->create([
            'document_version_id' => $version->document_version_id,
            'qt_id' => $quotation->qt_id,
            'bill_id' => $bill->bill_id,
            'authorization_no' => $payload['authorization_no'],
            'payload' => $payload,
            'sale_user_id' => $sale->id,
            'sale_username' => $sale->username,
            'draft_pdf_base64' => base64_encode($pdf),
            'draft_pdf_sha256' => hash('sha256', $pdf),
            'filename' => 'Power_of_Attorney_'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $payload['quotation_no']).'_R'.$version->revision_no.'.pdf',
            'created_at' => now(),
        ]);
    }

    public function pdf(QuotationPowerOfAttorneyVersion $authorization, BillingRecord $bill): string
    {
        $draft = base64_decode((string) $authorization->draft_pdf_base64, true);
        abort_if($draft === false || ! hash_equals((string) $authorization->draft_pdf_sha256, hash('sha256', $draft)), 500, 'ไฟล์หนังสือมอบอำนาจที่จัดเก็บไม่ผ่านการตรวจสอบความถูกต้อง');

        return $draft;
    }

    /** @param array<string, mixed> $documentPayload
     * @return array{payload: array<string, mixed>, sale_signature: string}
     */
    public function previewData(Quotation $quotation, array $documentPayload): array
    {
        $bill = $quotation->billingRecord()->firstOrFail();
        $sale = $this->saleFor($quotation);
        return [
            'payload' => $this->payloadFromDocument($quotation, $bill, $documentPayload, $sale),
            'sale_signature' => '',
        ];
    }

    /** @return array<string, mixed> */
    private function payload(Quotation $quotation, BillingRecord $bill, QuotationDocumentVersion $version, User $sale): array
    {
        return $this->payloadFromDocument(
            $quotation,
            $bill,
            (array) $version->payload,
            $sale,
            (int) $version->document_version_id,
            (string) $version->pdf_sha256,
            (int) $version->revision_no,
        );
    }

    /** @param array<string, mixed> $documentPayload
     * @return array<string, mixed>
     */
    private function payloadFromDocument(
        Quotation $quotation,
        BillingRecord $bill,
        array $documentPayload,
        User $sale,
        ?int $documentVersionId = null,
        string $quotationPdfSha256 = '',
        int $revisionNo = 1,
    ): array {
        $editorSettings = (array) data_get($documentPayload, 'power_of_attorney', []);
        $issueDate = Carbon::createFromFormat('!Y-m-d', (string) ($editorSettings['issue_date'] ?? '')) ?: now();
        $validUntil = $issueDate->copy()->addDays(90);
        $saleName = trim((string) $sale->first_name.' '.(string) $sale->last_name) ?: $sale->username;
        $items = collect((array) data_get($documentPayload, 'items', []))->map(fn (array $item): array => [
            'sku' => (string) ($item['sku'] ?? '-'),
            'description' => (string) ($item['description'] ?? '-'),
            'quantity' => (string) ($item['quantity'] ?? '-'),
            'unit' => (string) ($item['unit'] ?? ''),
        ])->values()->all();

        $hospitalName = (string) ($bill->buyer_name ?: $quotation->buyer_name);
        $templateId = $this->templates->normalize($editorSettings['template_id'] ?? null, $hospitalName);
        $project = trim((string) ($editorSettings['project'] ?? ''));
        if ($project === '') {
            $project = collect($items)->pluck('description')->filter()->take(3)->implode(', ');
        }

        return [
            'authorization_no' => (string) ($editorSettings['authorization_no'] ?? 'POA-'.($quotation->qt_no ?: $quotation->qt_id).'-R'.$revisionNo),
            'template_id' => $templateId,
            'quotation_no' => (string) data_get($documentPayload, 'document.number', $quotation->qt_no ?: $quotation->qt_id),
            'quotation_document_version_id' => $documentVersionId,
            'quotation_pdf_sha256' => $quotationPdfSha256,
            'hospital_name' => $hospitalName,
            'place' => (string) ($editorSettings['place'] ?? 'บริษัท เวเลอร์ เฮลธ์ จำกัด สำนักงานใหญ่'),
            'company_name' => (string) ($editorSettings['company_name'] ?? 'บริษัท เวเลอร์ เฮลธ์ จำกัด สำนักงานใหญ่'),
            'company_address' => (string) ($editorSettings['company_address'] ?? '2/22 อาคารไอยรา ทาวเวอร์ ชั้น 10 ถนนจันทน์ แขวงทุ่งวัดดอน เขตสาทร กรุงเทพมหานคร 10120'),
            'grantor_text' => (string) ($editorSettings['grantor_text'] ?? 'ผู้มีอำนาจลงนามผูกพันบริษัทฯ'),
            'grantor_one_name' => (string) ($editorSettings['grantor_one_name'] ?? 'นายประเสริฐ ไวยาวัจมัย'),
            'grantor_two_name' => (string) ($editorSettings['grantor_two_name'] ?? 'นายกฤต เติมพิริยะ'),
            'grantee_name' => (string) ($editorSettings['grantee_name'] ?? $saleName),
            'grantee_job_title' => (string) ($editorSettings['grantee_job_title'] ?? $sale->job_title ?? ''),
            'grantee_address' => (string) ($editorSettings['grantee_address'] ?? $sale->representative_address ?? ''),
            'project' => mb_substr($project, 0, 2000),
            'organization' => (string) ($editorSettings['organization'] ?? $hospitalName),
            'authority_note' => mb_substr(trim((string) ($editorSettings['authority_note'] ?? '')), 0, 2000),
            'acceptance_text' => (string) ($editorSettings['acceptance_text'] ?? 'การใดที่ผู้รับมอบอำนาจได้กระทำภายในขอบเขตแห่งหนังสือมอบอำนาจฉบับนี้ บริษัทฯ ขอรับผิดชอบเสมือนหนึ่งบริษัทฯ เป็นผู้กระทำเองทุกประการ'),
            'body_intro' => (string) ($editorSettings['body_intro'] ?? ''),
            'body_scope' => (string) ($editorSettings['body_scope'] ?? ''),
            'body_closing' => (string) ($editorSettings['body_closing'] ?? ''),
            'witness_one_name' => (string) ($editorSettings['witness_one_name'] ?? ''),
            'witness_two_name' => (string) ($editorSettings['witness_two_name'] ?? ''),
            'footer_text' => (string) ($editorSettings['footer_text'] ?? ''),
            'stamp_text' => (string) ($editorSettings['stamp_text'] ?? 'อากรแสตมป์ตามอัตราที่กฎหมายกำหนด'),
            'authority_quote' => (bool) ($editorSettings['authority_quote'] ?? true),
            'authority_documents' => (bool) ($editorSettings['authority_documents'] ?? true),
            'authority_negotiate' => (bool) ($editorSettings['authority_negotiate'] ?? true),
            'authority_modify' => (bool) ($editorSettings['authority_modify'] ?? true),
            'authority_contract' => (bool) ($editorSettings['authority_contract'] ?? false),
            'authority_order' => (bool) ($editorSettings['authority_order'] ?? false),
            'sale_identity_no' => mb_substr(trim((string) ($editorSettings['sale_identity_no'] ?? $sale->national_id ?? '')), 0, 13),
            'national_id_issued_at' => (string) ($editorSettings['national_id_issued_at'] ?? $sale->national_id_issued_at?->format('Y-m-d') ?? ''),
            'national_id_expires_at' => (string) ($editorSettings['national_id_expires_at'] ?? $sale->national_id_expires_at?->format('Y-m-d') ?? ''),
            'issue_date' => $issueDate->format('Y-m-d'),
            'issue_date_th' => $issueDate->format('d/m/').((int) $issueDate->format('Y') + 543),
            'issue_date_long_th' => $this->thaiLongDate($issueDate),
            'valid_until' => $validUntil->format('Y-m-d'),
            'valid_until_th' => $validUntil->format('d/m/').((int) $validUntil->format('Y') + 543),
            'sale_name' => $saleName,
            'sale_username' => $sale->username,
            'sale_email' => $sale->email ?: '-',
            'sale_phone' => $sale->phone ?: '-',
            'items' => $items,
        ];
    }

    private function saleFor(Quotation $quotation): User
    {
        $sale = $quotation->creator_user_id
            ? User::query()->find($quotation->creator_user_id)
            : User::query()->where('username', $quotation->created_by)->first();

        return $sale ?? throw ValidationException::withMessages([
            'power_of_attorney' => 'ไม่พบบัญชี Sale เจ้าของใบเสนอราคา จึงสร้างตัวอย่างหนังสือมอบอำนาจไม่ได้',
        ]);
    }

    private function thaiLongDate(CarbonInterface $date): string
    {
        $months = [1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

        return (int) $date->format('j').' '.$months[(int) $date->format('n')].' '.((int) $date->format('Y') + 543);
    }
}
