<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingRecord;
use App\Models\CustomerAcceptanceSignature;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\Notifications\WorkflowEmailNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CustomerAcceptanceController extends Controller
{
    public const ACCEPTANCE_INTENT = 'ข้าพเจ้าได้ตรวจสอบรายการสินค้า ราคา เงื่อนไขการชำระเงิน และกำหนดส่งในใบเสนอราคา Revision นี้แล้ว และยืนยันสั่งซื้อตามรายละเอียดดังกล่าว';

    public function show(string $token): JsonResponse
    {
        $bill = $this->billForToken($token)->load('items');
        $quotation = Quotation::query()->findOrFail($bill->ref_qt_id);
        $acceptance = $bill->customerAcceptanceSignatures()->whereNull('revoked_at')->latest('accepted_at')->first();

        return response()->json(['data' => [
            'bill_id' => $bill->bill_id,
            'qt_no' => $quotation->qt_no,
            'revision_no' => $bill->revision_no,
            'bill_date' => $bill->bill_date,
            'buyer_name' => $bill->buyer_name,
            'address_note' => $bill->address_note,
            'total_amount' => $bill->total_amount,
            'quotation_valid_until' => $bill->quotation_valid_until,
            'payment_terms' => $bill->payment_terms,
            'delivery_due_date' => $bill->delivery_due_date,
            'delivery_terms' => $bill->delivery_terms,
            'status' => $bill->status,
            'items' => $bill->items,
            'intent_text' => self::ACCEPTANCE_INTENT,
            'accepted_at' => $acceptance?->accepted_at,
            'signer_name' => $acceptance?->signer_name,
        ]])->header('Cache-Control', 'no-store');
    }

    public function accept(Request $request, string $token, WorkflowEmailNotifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'signer_name' => ['required', 'string', 'max:500'],
            'signer_position' => ['required', 'string', 'max:500'],
            'signer_organization' => ['required', 'string', 'max:1000'],
            'signer_email' => ['nullable', 'email', 'max:255'],
            'intent' => ['required', 'string', Rule::in([self::ACCEPTANCE_INTENT])],
            'consent' => ['accepted'],
            'signature_method' => ['required', Rule::in(['drawn', 'uploaded_png'])],
            'signature_image' => ['required', 'string', 'max:1500000'],
        ]);
        [$imageBase64, $imageBytes] = $this->validatedPng((string) $data['signature_image']);

        $bill = DB::transaction(function () use ($token, $data, $imageBase64, $imageBytes, $request): BillingRecord {
            $bill = BillingRecord::query()
                ->where('customer_acceptance_token', $token)
                ->lockForUpdate()
                ->with('items')
                ->firstOrFail();
            abort_unless($bill->ref_qt_id, 422, 'รายการนี้ไม่ใช่ใบเสนอราคา');
            abort_if($bill->customer_acceptance_expires_at && $bill->customer_acceptance_expires_at->isPast(), 410, 'ลิงก์ยืนยันหมดอายุแล้ว กรุณาติดต่อ Sale');
            if ($bill->status !== 'รอลูกค้ายืนยัน') {
                throw ValidationException::withMessages(['status' => 'ใบเสนอราคานี้ถูกยืนยันหรือเปลี่ยนสถานะแล้ว']);
            }

            $quotation = Quotation::query()->findOrFail($bill->ref_qt_id);
            $snapshot = $this->canonicalize([
                'bill' => $bill->only([
                    'bill_id', 'ref_qt_id', 'revision_no', 'bill_date', 'buyer_name', 'tax_id', 'address_note',
                    'quotation_valid_until', 'payment_terms', 'delivery_due_date', 'delivery_terms',
                    'sale_discount_total', 'total_amount', 'created_by', 'submitted_at',
                ]),
                'quotation' => ['qt_no' => $quotation->qt_no],
                'items' => $bill->items->map(fn ($item): array => $item->only([
                    'item_id', 'main_category', 'sub_category', 'customer_product_name', 'unit_type',
                    'quantity', 'unit_price', 'sale_discount_unit', 'line_total_before_discount', 'line_total',
                ]))->values()->all(),
                'signer' => [
                    'name' => trim((string) $data['signer_name']),
                    'position' => trim((string) $data['signer_position']),
                    'organization' => trim((string) $data['signer_organization']),
                    'email' => $data['signer_email'] ?? null,
                ],
                'intent' => self::ACCEPTANCE_INTENT,
            ]);
            $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

            CustomerAcceptanceSignature::query()->create([
                'bill_id' => $bill->bill_id,
                'qt_id' => $bill->ref_qt_id,
                'revision_no' => max(1, (int) $bill->revision_no),
                'signer_name' => trim((string) $data['signer_name']),
                'signer_position' => trim((string) $data['signer_position']),
                'signer_organization' => trim((string) $data['signer_organization']),
                'signer_email' => $data['signer_email'] ?? null,
                'signature_method' => $data['signature_method'],
                'signature_image_base64' => $imageBase64,
                'signature_image_sha256' => hash('sha256', $imageBytes),
                'intent_text' => self::ACCEPTANCE_INTENT,
                'payload_snapshot' => $snapshot,
                'payload_sha256' => hash('sha256', $encoded),
                'accepted_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
            ]);

            $bill->forceFill([
                'status' => 'รอแอดมินส่งออก',
                'customer_confirmed_at' => today(),
                'customer_contact_name' => trim((string) $data['signer_name']),
                'customer_contact_channel' => filled($data['signer_email'] ?? null) ? 'Customer Portal: '.$data['signer_email'] : 'Customer Portal',
                'customer_acceptance_used_at' => now(),
            ])->save();

            return $bill->fresh(['items', 'documents']);
        });

        $sale = $bill->creator()->first() ?? User::query()->where('username', $bill->created_by)->first();
        if ($sale) {
            $notifier->customerAcceptedQuotation($bill, $sale, trim((string) $data['signer_name']));
        }

        return response()->json(['data' => ['status' => $bill->status, 'accepted_at' => now()]])->header('Cache-Control', 'no-store');
    }

    public function pdf(string $token, DocumentService $documents): mixed
    {
        $bill = $this->billForToken($token)->load(['items', 'documents', 'signatures', 'customerAcceptanceSignatures']);
        $quotation = Quotation::query()->with(['items', 'creator:id,username,department'])->findOrFail($bill->ref_qt_id);
        $bytes = $documents->quotationPdf($quotation, draft: $bill);
        \App\Services\Documents\DocumentDownloadAudit::capture(request(), 'customer_quotation', $quotation, $bill);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $documents->attachmentDisposition('Quotation_'.$quotation->qt_no.'_R'.max(1, (int) $bill->revision_no).'.pdf'),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function billForToken(string $token): BillingRecord
    {
        $bill = BillingRecord::query()->where('customer_acceptance_token', $token)->firstOrFail();
        abort_unless($bill->ref_qt_id, 404);
        abort_if($bill->customer_acceptance_expires_at && $bill->customer_acceptance_expires_at->isPast() && ! $bill->customer_acceptance_used_at, 410, 'ลิงก์หมดอายุ');

        return $bill;
    }

    /** @return array{string, string} */
    private function validatedPng(string $dataUri): array
    {
        if (! preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=\r\n]+)$/', $dataUri, $matches)) {
            throw ValidationException::withMessages(['signature_image' => 'ลายเซ็นต้องเป็นไฟล์ PNG']);
        }
        $bytes = base64_decode($matches[1], true);
        if ($bytes === false || strlen($bytes) < 8 || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw ValidationException::withMessages(['signature_image' => 'ข้อมูลภาพ PNG ไม่ถูกต้อง']);
        }
        if (strlen($bytes) > 1024 * 1024) {
            throw ValidationException::withMessages(['signature_image' => 'ภาพลายเซ็นต้องไม่เกิน 1 MB']);
        }

        return [base64_encode($bytes), $bytes];
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
