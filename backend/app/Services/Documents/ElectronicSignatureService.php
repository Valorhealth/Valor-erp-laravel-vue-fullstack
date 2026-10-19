<?php

namespace App\Services\Documents;

use App\Models\BillingRecord;
use App\Models\DocumentSignature;
use App\Models\QuotationDocumentVersion;
use App\Models\QuotationPowerOfAttorneyVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class ElectronicSignatureService
{
    public const APPROVAL_INTENT = 'ข้าพเจ้าได้ตรวจสอบใบเสนอราคาและชุดเอกสารประกอบ Revision นี้ และอนุมัติให้ดำเนินการตามข้อมูลดังกล่าว';

    public const LEGACY_APPROVAL_INTENT = 'ข้าพเจ้าได้ตรวจสอบใบเสนอราคา Revision นี้และอนุมัติให้บริษัทดำเนินการตามข้อมูลดังกล่าว';

    public const PO_DIRECT_APPROVAL_INTENT = 'ข้าพเจ้าได้ตรวจสอบใบสั่งซื้อของลูกค้าและข้อมูลเปิดบิลรายการนี้แล้ว และอนุมัติให้บริษัทดำเนินการตามข้อมูลดังกล่าว';

    public function signInternalApproval(
        User $user,
        BillingRecord $bill,
        string $password,
        string $intent,
        ?string $ipAddress,
        ?string $userAgent,
    ): DocumentSignature {
        if (! $user->quotation_signing_authorized) {
            abort(403, 'บัญชีนี้ยังไม่ได้รับการกำหนดสิทธิ์ลงนามใบเสนอราคาโดยผู้ดูแลระบบ');
        }
        $signatureAsset = DB::table('user_signature_assets')
            ->where('user_id', $user->id)
            ->where('is_current', true)
            ->first(['signature_asset_id', 'version_no', 'image_base64', 'image_sha256']);
        if (! $signatureAsset) {
            throw ValidationException::withMessages(['signature' => 'บัญชีนี้ยังไม่มี PNG ลายเซ็นประจำบัญชี']);
        }
        if (! Hash::check($password, (string) $user->password_hash)) {
            throw ValidationException::withMessages(['password' => 'รหัสผ่านสำหรับยืนยันลายมือชื่อไม่ถูกต้อง']);
        }
        $bill->loadMissing('items');
        $documents = DB::table('billing_documents')
            ->where('bill_id', $bill->bill_id)
            ->where('is_current', true)
            ->orderBy('document_type')
            ->get(['document_type', 'file_name', 'version_no', 'sha256'])
            ->map(fn (object $document): array => (array) $document)
            ->all();
        $quotationDocument = null;
        $powerOfAttorney = null;
        if ($bill->workflow_type !== 'customer_po_direct' && $bill->ref_qt_id) {
            $quotationDocument = QuotationDocumentVersion::query()
                ->where('document_version_id', $bill->quotation_document_version_id)
                ->where('bill_id', $bill->bill_id)
                ->where('qt_id', $bill->ref_qt_id)
                ->first();
            if (! $quotationDocument) {
                throw ValidationException::withMessages([
                    'quotation_document' => 'ไม่พบ PDF ใบเสนอราคาฉบับที่ Sale ยืนยัน จึงยังอนุมัติไม่ได้',
                ]);
            }
            $powerOfAttorney = QuotationPowerOfAttorneyVersion::query()
                ->where('document_version_id', $quotationDocument->document_version_id)
                ->where('bill_id', $bill->bill_id)
                ->first();
        }
        $expectedIntent = $bill->workflow_type === 'customer_po_direct'
            ? self::PO_DIRECT_APPROVAL_INTENT
            : ($powerOfAttorney ? self::APPROVAL_INTENT : self::LEGACY_APPROVAL_INTENT);
        if (! hash_equals($expectedIntent, trim($intent))) {
            throw ValidationException::withMessages(['intent' => 'ข้อความแสดงเจตนาอนุมัติไม่ตรงกับข้อความที่ระบบกำหนด']);
        }
        $snapshot = $this->canonicalize([
            'bill' => $bill->only([
                'bill_id', 'ref_qt_id', 'revision_no', 'bill_date', 'buyer_name', 'tax_id', 'address_note',
                'customer_po_no', 'customer_confirmed_at', 'customer_contact_name', 'customer_contact_channel',
                'quotation_valid_until', 'payment_terms', 'delivery_due_date', 'delivery_terms',
                'sale_discount_total', 'total_amount', 'created_by', 'submitted_at',
            ]),
            'items' => $bill->items->map(fn ($item): array => $item->only([
                'item_id', 'main_category', 'sub_category', 'customer_product_name', 'unit_type',
                'quantity', 'unit_price', 'sale_discount_unit', 'line_total_before_discount', 'line_total',
            ]))->values()->all(),
            'evidence_documents' => $documents,
            'quotation_document' => $quotationDocument ? [
                'document_version_id' => (int) $quotationDocument->document_version_id,
                'template_id' => (string) $quotationDocument->template_id,
                'document_mode' => (string) $quotationDocument->document_mode,
                'revision_no' => (int) $quotationDocument->revision_no,
                'filename' => (string) $quotationDocument->filename,
                'pdf_sha256' => (string) $quotationDocument->pdf_sha256,
            ] : null,
            'power_of_attorney' => $powerOfAttorney ? [
                'authorization_version_id' => (int) $powerOfAttorney->authorization_version_id,
                'document_version_id' => (int) $powerOfAttorney->document_version_id,
                'draft_pdf_sha256' => (string) $powerOfAttorney->draft_pdf_sha256,
            ] : null,
            'intent' => $expectedIntent,
            'visual_signature' => [
                'signature_asset_id' => (int) $signatureAsset->signature_asset_id,
                'version_no' => (int) $signatureAsset->version_no,
                'sha256' => (string) $signatureAsset->image_sha256,
            ],
        ]);
        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return DocumentSignature::query()->create([
            'bill_id' => $bill->bill_id,
            'qt_id' => $bill->ref_qt_id,
            'revision_no' => max(1, (int) $bill->revision_no),
            'signature_type' => 'internal_quotation_approval',
            'signature_level' => 'general_electronic_signature',
            'signer_user_id' => $user->id,
            'signer_username' => $user->username,
            'signer_name' => trim((string) $user->first_name.' '.(string) $user->last_name) ?: $user->username,
            'signer_role' => $user->role,
            'signer_email' => $user->email,
            'signature_asset_id' => $signatureAsset->signature_asset_id,
            'signature_image_sha256' => $signatureAsset->image_sha256,
            'signature_image_base64' => $signatureAsset->image_base64,
            'intent_text' => $expectedIntent,
            'payload_snapshot' => $snapshot,
            'payload_sha256' => hash('sha256', $encoded),
            'signed_at' => now(),
            'ip_address' => $ipAddress,
            'user_agent' => mb_substr((string) $userAgent, 0, 2000),
        ]);
    }

    /** @return array<string|int, mixed> */
    private function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => is_array($item) ? $this->canonicalize($item) : $item, $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
