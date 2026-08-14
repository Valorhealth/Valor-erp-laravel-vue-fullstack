<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Services\Authorization\RoleAccess;
use App\Services\Billing\BillingWorkflow;
use App\Services\Credit\CustomerCreditResolver;
use App\Services\Documents\DocumentService;
use App\Services\Quotations\QuotationNumberGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuotationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $availableForBilling = $request->boolean('available_for_billing');
        $quotations = Quotation::query()
            ->with(['billingRecord:bill_id,ref_qt_id,bill_date,status,buyer_name,total_amount'])
            ->withCount('items')
            ->when(RoleAccess::mustUseOwnBillingRecords($request->user()->role), fn ($query) => $query->where('created_by', $request->user()->username))
            ->when($availableForBilling, fn ($query) => $query->where(fn ($available) => $available
                ->whereDoesntHave('billingRecord')
                ->orWhereHas('billingRecord', fn ($bill) => $bill->where('status', BillingWorkflow::DRAFT_STATUS))))
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('qt_no', 'ilike', "%{$search}%")
                ->orWhere('buyer_name', 'ilike', "%{$search}%")
                ->orWhere('created_by', 'ilike', "%{$search}%")))
            ->orderByDesc('qt_id')
            ->paginate(min(max($request->integer('per_page', 20), 5), 100));

        return response()->json($quotations);
    }

    public function show(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotation($request, $quotation);

        return response()->json(['data' => $quotation->load('items')]);
    }

    public function store(
        Request $request,
        CustomerCreditResolver $creditResolver,
        BillingWorkflow $workflow,
        QuotationNumberGenerator $quotationNumbers,
    ): JsonResponse
    {
        $data = $request->validate([
            'qt_date' => ['required', 'date'],
            'buyer_name' => ['required', 'string', 'max:1000'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'bplus_customer_code' => ['required', 'string', 'max:255'],
            'province' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'sub_district' => ['required', 'string', 'max:255'],
            'zipcode' => ['required', 'string', 'max:20'],
            'address_note' => ['required', 'string', 'max:5000'],
            'shipping_type' => ['required', 'in:ส่งปกติ,ส่งเร็ว,ส่งด่วน'],
            'shipping_text' => ['nullable', 'string', 'max:2000'],
            'bill_note' => ['nullable', 'string', 'max:5000'],
            'sale_discount_total' => ['nullable', 'numeric', 'min:0'],
            'sale_discount_note' => ['nullable', 'string', 'max:2000'],
            'requested_credit_term' => ['nullable', 'integer', 'min:0'],
            'workflow_type' => ['nullable', 'in:existing_customer,credit_first'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.main_category' => ['required', 'string', 'max:255'],
            'items.*.sub_category' => ['required', 'string', 'max:2000'],
            'items.*.unit_type' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.sale_discount_unit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $quotation = DB::transaction(function () use ($request, $data, $creditResolver, $workflow, $quotationNumbers): Quotation {
            $items = collect($data['items'])->map(function (array $item): array {
                $before = round((int) $item['quantity'] * (float) $item['unit_price'], 2);
                $discount = min((float) ($item['sale_discount_unit'] ?? 0), $before);

                return [...$item, 'sale_discount_unit' => $discount, 'line_total_before_discount' => $before, 'line_total' => round($before - $discount, 2)];
            });
            $gross = (float) $items->sum('line_total');
            $tailDiscount = min((float) ($data['sale_discount_total'] ?? 0), $gross);
            $credit = $creditResolver->resolve($data['bplus_customer_code'], $data['buyer_name']);

            $quotation = Quotation::query()->create([
                ...collect($data)->except(['items'])->all(),
                'total_amount' => round($gross - $tailDiscount, 2),
                'sale_discount_total' => $tailDiscount,
                'workflow_type' => $data['workflow_type'] ?? 'existing_customer',
                'credit_limit' => $credit?->credit_limit ?? 0,
                'credit_term' => $credit?->credit_term ?? 0,
                'requested_credit_term' => $data['requested_credit_term'] ?? $credit?->credit_term ?? 0,
                'created_by' => $request->user()->username,
                'status' => 'ร่างใบเสนอราคา',
                'created_at' => now(),
            ]);
            $quotation->forceFill([
                'qt_no' => $quotationNumbers->nextForDate($data['qt_date']),
            ])->save();
            foreach ($items as $item) {
                $quotation->items()->create($item);
            }

            $draft = $workflow->createFromQuotation($request->user(), $quotation, [
                'bill_date' => $data['qt_date'],
                'save_as_draft' => true,
            ]);

            return $quotation->load('items')->setRelation('billingRecord', $draft);
        });

        return response()->json(['data' => $quotation], 201);
    }

    public function pdf(Request $request, Quotation $quotation, DocumentService $documents): mixed
    {
        $this->authorizeQuotation($request, $quotation);
        $copy = $request->boolean('copy');
        $revised = $request->boolean('revised');
        abort_if($copy && $revised, 422, 'กรุณาเลือกประเภทเอกสารเพียงประเภทเดียว');
        $draft = $quotation->billingRecord()->with('items')->first();
        $bytes = $documents->quotationPdf($quotation, $copy, $draft, $revised);
        $quotation->forceFill(['quotation_printed_at' => now(), 'quotation_printed_by' => $request->user()->username])->save();
        $filename = $documents->quotationDownloadName($quotation, $copy, $revised);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $documents->attachmentDisposition($filename),
        ]);
    }

    public function customNamePdf(Request $request, Quotation $quotation, DocumentService $documents): mixed
    {
        $this->authorizeQuotation($request, $quotation);
        $data = $request->validate([
            'item_names' => ['required', 'array', 'min:1', 'max:100'],
            'item_names.*.qt_item_id' => ['required', 'integer'],
            'item_names.*.name' => ['required', 'string', 'max:2000'],
        ]);
        $quotation->load('items');
        $ownedItemIds = $quotation->items->pluck('qt_item_id')->map(fn ($id): int => (int) $id);
        $submittedItemIds = collect($data['item_names'])->pluck('qt_item_id')->map(fn ($id): int => (int) $id);
        abort_if($submittedItemIds->diff($ownedItemIds)->isNotEmpty(), 422, 'พบรายการสินค้าที่ไม่ได้อยู่ในใบเสนอราคานี้');

        $itemNames = collect($data['item_names'])
            ->mapWithKeys(fn (array $item): array => [(int) $item['qt_item_id'] => trim($item['name'])])
            ->all();
        $bytes = $documents->quotationPdf($quotation, revised: true, itemNameOverrides: $itemNames);
        $quotation->forceFill(['quotation_printed_at' => now(), 'quotation_printed_by' => $request->user()->username])->save();
        $filename = $documents->quotationDownloadName($quotation, revised: true);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $documents->attachmentDisposition($filename),
        ]);
    }

    private function authorizeQuotation(Request $request, Quotation $quotation): void
    {
        if (RoleAccess::mustUseOwnBillingRecords($request->user()->role) && $quotation->created_by !== $request->user()->username) {
            abort(403, 'คุณไม่มีสิทธิ์เข้าถึงใบเสนอราคานี้');
        }
    }
}
