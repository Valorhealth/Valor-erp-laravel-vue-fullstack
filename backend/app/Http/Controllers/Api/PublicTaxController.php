<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingRecord;
use App\Services\Documents\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicTaxController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $bill = $this->bill($token)->load('items');

        return response()->json(['data' => [
            ...$bill->only([
                'bill_id', 'bill_date', 'buyer_name', 'total_amount', 'status', 'tax_info_filled',
                'tax_cust_name', 'tax_cust_address', 'tax_cust_tax_id', 'tax_invoice_no',
            ]),
            'items' => $bill->items,
        ]]);
    }

    public function confirm(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'tax_cust_name' => ['required', 'string', 'max:1000'],
            'tax_cust_tax_id' => ['required', 'regex:/^\d{13}$/'],
            'tax_cust_address' => ['required', 'string', 'max:5000'],
        ]);
        $bill = $this->bill($token);
        abort_if(in_array($bill->status, ['ยกเลิกบิล', 'ไม่อนุมัติ'], true), 422, 'เอกสารนี้ถูกยกเลิกแล้ว');
        $bill->forceFill([...$data, 'tax_info_filled' => true])->save();

        return response()->json(['data' => $bill->fresh()]);
    }

    public function cancel(string $token): JsonResponse
    {
        $bill = $this->bill($token);
        $bill->forceFill(['status' => 'ยกเลิกบิล'])->save();

        return response()->json(['data' => $bill->fresh()]);
    }

    public function pdf(string $token, DocumentService $documents): mixed
    {
        $bill = $this->bill($token);
        abort_unless($bill->tax_info_filled, 422, 'กรุณายืนยันข้อมูลภาษีก่อนดาวน์โหลด');
        abort_if(in_array($bill->status, ['ยกเลิกบิล', 'ไม่อนุมัติ'], true), 422, 'เอกสารนี้ถูกยกเลิกแล้ว');
        abort_if(in_array($bill->status, ['ฉบับร่าง', 'รออนุมัติ', 'รอหัวหน้าอนุมัติ', 'รอแก้ไข'], true), 422, 'บิลนี้ยังไม่ผ่านการอนุมัติ');
        abort_if($bill->bill_date?->isFuture(), 422, 'บิลลงวันที่อนาคตได้ใน Draft แต่ยังออกเลขใบกำกับภาษีก่อนถึงวันที่บิลไม่ได้');
        $bytes = $documents->billPdf($bill);
        $bill->forceFill(['document_printed_at' => now(), 'document_printed_by' => 'Customer Portal'])->save();
        $filename = $documents->billDownloadName($bill);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $documents->attachmentDisposition($filename),
        ]);
    }

    private function bill(string $token): BillingRecord
    {
        return BillingRecord::query()->where('tax_qr_token', $token)->firstOrFail();
    }
}
