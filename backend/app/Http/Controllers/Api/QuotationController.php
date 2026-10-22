<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingRecord;
use App\Models\Quotation;
use App\Models\QuotationDocumentHelpRequest;
use App\Models\QuotationDocumentVersion;
use App\Services\Authorization\RoleAccess;
use App\Services\Billing\BillingWorkflow;
use App\Services\Credit\CustomerCreditResolver;
use App\Services\Documents\DocumentDownloadAudit;
use App\Services\Documents\DocumentService;
use App\Services\Documents\PowerOfAttorneyService;
use App\Services\Documents\QuotationDocumentEditor;
use App\Services\Documents\QuotationTemplateCatalog;
use App\Services\Notifications\WorkflowEmailNotifier;
use App\Services\Products\ProductUnitValidator;
use App\Services\Quotations\QuotationFromPurchaseOrder;
use App\Services\Quotations\QuotationNumberGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuotationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $billId = preg_match('/^#?(\d+)$/', $search, $matches) ? (int) $matches[1] : null;
        $availableForBilling = $request->boolean('available_for_billing');
        $quotations = Quotation::query()
            ->with(['billingRecord' => fn ($query) => $query
                ->select(['bill_id', 'ref_qt_id', 'bill_date', 'status', 'buyer_name', 'total_amount', 'customer_acceptance_token', 'customer_acceptance_expires_at', 'customer_acceptance_used_at', 'quotation_document_version_id'])
                ->with(['documents', 'quotationDocumentVersion' => fn ($version) => $version
                    ->select(['document_version_id', 'qt_id', 'bill_id', 'template_id', 'revision_no', 'filename'])
                    ->with('powerOfAttorney:authorization_version_id,document_version_id,authorization_no,filename,created_at')])
                ->withExists(['signatures as internal_approval_exists' => fn ($signatures) => $signatures
                    ->where('signature_type', 'internal_quotation_approval')
                    ->whereNull('revoked_at')])])
            ->withCount('items')
            ->when(RoleAccess::mustUseOwnBillingRecords($request->user()->role), fn ($query) => $query->where(
                fn ($owned) => $owned->where('creator_user_id', $request->user()->id)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('creator_user_id')->where('created_by', $request->user()->username))
            ))
            ->when($availableForBilling, fn ($query) => $query->where(fn ($available) => $available
                ->whereDoesntHave('billingRecord')
                ->orWhereHas('billingRecord', fn ($bill) => $bill->where('status', BillingWorkflow::DRAFT_STATUS))))
            ->when($request->boolean('has_power_of_attorney'), fn ($query) => $query->whereHas('billingRecord.quotationDocumentVersion.powerOfAttorney'))
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('qt_no', 'ilike', "%{$search}%")
                ->orWhere('buyer_name', 'ilike', "%{$search}%")
                ->orWhere('created_by', 'ilike', "%{$search}%")
                ->orWhere('customer_po_no', 'ilike', "%{$search}%")
                ->when($billId !== null, fn ($lookup) => $lookup->orWhereHas('billingRecord', fn ($bill) => $bill->whereKey($billId)))))
            ->orderByDesc('qt_id')
            ->paginate(min(max($request->integer('per_page', 20), 5), 100));

        return response()->json($quotations);
    }

    public function show(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotation($request, $quotation);

        return response()->json(['data' => $quotation->load('items')]);
    }

    public function revisions(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotation($request, $quotation);
        $revisions = DB::table('quotation_revisions')
            ->where('qt_id', $quotation->qt_id)
            ->orderByDesc('revision_no')
            ->get(['revision_id', 'qt_id', 'bill_id', 'revision_no', 'changed_by', 'change_note', 'created_at']);

        return response()->json(['data' => $revisions]);
    }

    public function store(
        Request $request,
        CustomerCreditResolver $creditResolver,
        BillingWorkflow $workflow,
        QuotationNumberGenerator $quotationNumbers,
        ProductUnitValidator $productUnits,
    ): JsonResponse {
        $data = $request->validate([
            'qt_date' => ['required', 'date'],
            'buyer_name' => ['required', 'string', 'max:1000'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'price_vat_mode' => ['required', Rule::in(['in_vat', 'ex_vat'])],
            'bplus_customer_code' => ['required', 'string', 'max:255'],
            'province' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'sub_district' => ['required', 'string', 'max:255'],
            'zipcode' => ['required', 'string', 'max:20'],
            'address_note' => ['required', 'string', 'max:5000'],
            'billing_address' => ['required', 'string', 'max:5000'],
            'delivery_address' => ['required', 'string', 'max:5000'],
            'billing_address_id' => ['nullable', 'integer', Rule::exists('customer_addresses', 'address_id')->where('is_active', true)],
            'delivery_address_id' => ['nullable', 'integer', Rule::exists('customer_addresses', 'address_id')->where('is_active', true)],
            'standard_show_billing_address' => ['required', 'boolean'],
            'standard_show_delivery_address' => ['required', 'boolean', 'required_without:standard_show_billing_address'],
            'shipping_type' => ['required', 'in:ส่งปกติ,ส่งเร็ว,ส่งด่วน'],
            'shipping_text' => ['nullable', 'string', 'max:2000'],
            'bill_note' => ['nullable', 'string', 'max:5000'],
            'sale_discount_total' => ['nullable', 'numeric', 'min:0'],
            'sale_discount_note' => ['nullable', 'string', 'max:2000'],
            'requested_credit_term' => ['nullable', 'integer', 'min:0'],
            'workflow_type' => ['nullable', 'in:existing_customer,credit_first'],
            'customer_po_no' => ['nullable', 'string', 'max:255'],
            'customer_confirmed_at' => ['nullable', 'date'],
            'customer_contact_name' => ['nullable', 'string', 'max:500'],
            'customer_contact_channel' => ['nullable', 'string', 'max:255'],
            'quotation_valid_until' => ['nullable', 'date', 'after_or_equal:qt_date'],
            'payment_terms' => ['nullable', 'string', 'max:5000'],
            'delivery_due_date' => ['nullable', 'date'],
            'delivery_terms' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.main_category' => ['required', 'string', 'max:255', Rule::exists('master_products', 'sku_code')],
            'items.*.sub_category' => ['required', 'string', 'max:2000'],
            'items.*.customer_product_name' => ['nullable', 'string', 'max:2000'],
            'items.*.unit_type' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.sale_discount_unit' => ['nullable', 'numeric', 'min:0'],
        ]);
        if (! $data['standard_show_billing_address'] && ! $data['standard_show_delivery_address']) {
            throw ValidationException::withMessages(['standard_address_visibility' => 'ใบเสนอราคามาตรฐานต้องแสดงที่อยู่อย่างน้อยหนึ่งประเภท']);
        }
        $productUnits->validate($data['items']);

        $quotation = DB::transaction(function () use ($request, $data, $creditResolver, $workflow, $quotationNumbers): Quotation {
            $items = collect($data['items'])->map(function (array $item): array {
                $before = round((int) $item['quantity'] * (float) $item['unit_price'], 2);
                $discount = min((float) ($item['sale_discount_unit'] ?? 0), $before);

                return [...$item, 'sale_discount_unit' => $discount, 'line_total_before_discount' => $before, 'line_total' => round($before - $discount, 2)];
            });
            $gross = (float) $items->sum('line_total');
            $tailDiscount = min((float) ($data['sale_discount_total'] ?? 0), $gross);
            $credit = $creditResolver->resolve($data['bplus_customer_code'], $data['buyer_name']);

            $netEntered = round($gross - $tailDiscount, 2);
            $quotation = Quotation::query()->create([
                ...collect($data)->except(['items'])->all(),
                'total_amount' => $data['price_vat_mode'] === 'ex_vat'
                    ? round($netEntered * 1.07, 2)
                    : $netEntered,
                'sale_discount_total' => $tailDiscount,
                'workflow_type' => $data['workflow_type'] ?? 'existing_customer',
                'credit_limit' => $credit?->credit_limit ?? 0,
                'credit_term' => $credit?->credit_term ?? 0,
                'requested_credit_term' => $data['requested_credit_term'] ?? $credit?->credit_term ?? 0,
                'created_by' => $request->user()->username,
                'creator_user_id' => $request->user()->id,
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

    public function templates(QuotationTemplateCatalog $templates): JsonResponse
    {
        return response()->json(['data' => $templates->options()]);
    }

    public function editor(Request $request, Quotation $quotation, QuotationDocumentEditor $editor, QuotationTemplateCatalog $templates): JsonResponse
    {
        $this->authorizeQuotation($request, $quotation);
        $data = $request->validate([
            'template' => ['required', 'string', 'max:64'],
            'mode' => ['nullable', 'in:original,copy,revised'],
        ]);
        $template = $templates->requireAvailable($data['template']);
        $result = $editor->open($quotation, $template, $data['mode'] ?? 'original');
        $helpRequest = QuotationDocumentHelpRequest::query()
            ->where('qt_id', $quotation->qt_id)
            ->where('template_id', $template)
            ->where('document_mode', $data['mode'] ?? 'original')
            ->latest('help_request_id')
            ->first();
        $result['help_request'] = $helpRequest ? $this->helpRequestData($helpRequest) : null;
        $result['can_manage_help'] = RoleAccess::canUser($request->user(), 'system_admin');

        return response()->json(['data' => $result]);
    }

    public function helpRequests(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,in_progress,resolved,cancelled'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $requests = QuotationDocumentHelpRequest::query()
            ->with(['quotation:qt_id,qt_no,buyer_name,created_by'])
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END")
            ->orderByDesc('requested_at')
            ->paginate((int) ($data['per_page'] ?? 50));

        $requests->getCollection()->transform(fn (QuotationDocumentHelpRequest $helpRequest): array => $this->helpRequestData($helpRequest));

        return response()->json($requests);
    }

    public function requestEditorHelp(
        Request $request,
        Quotation $quotation,
        QuotationTemplateCatalog $templates,
        WorkflowEmailNotifier $notifier,
    ): JsonResponse {
        $this->authorizeQuotation($request, $quotation);
        $data = $request->validate([
            'template' => ['required', 'string', 'max:64'],
            'mode' => ['required', 'in:original,copy,revised'],
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $template = $templates->requireAvailable($data['template']);
        $mode = $data['mode'];
        if ($mode === 'original' && $quotation->billingRecord?->status !== null
            && $quotation->billingRecord->status !== 'ฉบับร่าง') {
            abort(409, 'ใบเสนอราคาต้นฉบับส่งอนุมัติแล้ว ไม่สามารถขอให้แก้ไข Draft นี้ได้');
        }

        $helpRequest = DB::transaction(function () use ($quotation, $request, $template, $mode, $data): QuotationDocumentHelpRequest {
            $active = QuotationDocumentHelpRequest::query()
                ->where('qt_id', $quotation->qt_id)
                ->where('template_id', $template)
                ->where('document_mode', $mode)
                ->whereIn('status', ['pending', 'in_progress'])
                ->lockForUpdate()
                ->first();
            if ($active?->status === 'in_progress') {
                abort(409, 'Sale COO รับคำขอนี้แล้ว กรุณารอผลการดำเนินการ');
            }
            if ($active) {
                $active->forceFill([
                    'request_note' => trim($data['note']),
                    'requested_by_user_id' => $request->user()->id,
                    'requested_by' => $request->user()->username,
                    'requested_at' => now(),
                ])->save();

                return $active->fresh();
            }

            return QuotationDocumentHelpRequest::query()->create([
                'qt_id' => $quotation->qt_id,
                'template_id' => $template,
                'document_mode' => $mode,
                'request_note' => trim($data['note']),
                'status' => 'pending',
                'requested_by_user_id' => $request->user()->id,
                'requested_by' => $request->user()->username,
                'requested_at' => now(),
            ]);
        });

        $notifier->quotationHelpRequested($helpRequest, $quotation, $request->user());

        return response()->json(['data' => $this->helpRequestData($helpRequest)], 201);
    }

    public function updateEditorHelp(
        Request $request,
        Quotation $quotation,
        QuotationDocumentHelpRequest $helpRequest,
        WorkflowEmailNotifier $notifier,
    ): JsonResponse {
        abort_unless((int) $helpRequest->qt_id === (int) $quotation->qt_id, 404);
        $data = $request->validate([
            'action' => ['required', 'in:accept,resolve,cancel'],
            'note' => ['nullable', 'string', 'max:2000', 'required_if:action,resolve'],
        ]);
        $isAdmin = RoleAccess::canUser($request->user(), 'system_admin');
        $isRequester = (int) $helpRequest->requested_by_user_id === (int) $request->user()->id
            || $helpRequest->requested_by === $request->user()->username;
        abort_unless($isAdmin || ($data['action'] === 'cancel' && $isRequester), 403, 'คุณไม่มีสิทธิ์ดำเนินการคำขอนี้');

        $helpRequest = DB::transaction(function () use ($helpRequest, $request, $data): QuotationDocumentHelpRequest {
            $locked = QuotationDocumentHelpRequest::query()->whereKey($helpRequest->help_request_id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['resolved', 'cancelled'], true)) {
                abort(409, 'คำขอนี้ปิดงานแล้ว');
            }

            if ($data['action'] === 'accept') {
                $locked->forceFill([
                    'status' => 'in_progress',
                    'assigned_to_user_id' => $request->user()->id,
                    'assigned_to' => $request->user()->username,
                    'accepted_at' => now(),
                ]);
            } elseif ($data['action'] === 'resolve') {
                $locked->forceFill([
                    'status' => 'resolved',
                    'assigned_to_user_id' => $locked->assigned_to_user_id ?: $request->user()->id,
                    'assigned_to' => $locked->assigned_to ?: $request->user()->username,
                    'accepted_at' => $locked->accepted_at ?: now(),
                    'resolved_at' => now(),
                    'resolution_note' => trim((string) $data['note']),
                ]);
            } else {
                $locked->forceFill([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'resolution_note' => trim((string) ($data['note'] ?? '')) ?: 'ยกเลิกโดยผู้ร้องขอ',
                ]);
            }
            $locked->save();

            return $locked->fresh();
        });

        if ($request->user()->username !== $helpRequest->requested_by) {
            $notifier->quotationHelpUpdated($helpRequest, $quotation, $request->user());
        }

        return response()->json(['data' => $this->helpRequestData($helpRequest)]);
    }

    public function saveEditorDraft(Request $request, Quotation $quotation, QuotationDocumentEditor $editor, QuotationTemplateCatalog $templates): JsonResponse
    {
        $this->authorizeQuotation($request, $quotation);
        $data = $request->validate($this->editorPayloadRules());
        $template = $templates->requireAvailable($data['template']);
        $draft = $editor->saveDraft(
            $quotation,
            $request->user(),
            $template,
            $data['mode'],
            $data['payload'],
            (int) ($data['lock_version'] ?? 0),
        );

        return response()->json(['data' => [
            'lock_version' => $draft->lock_version,
            'saved_at' => $draft->updated_at?->toIso8601String(),
        ]]);
    }

    public function previewEditor(Request $request, Quotation $quotation, QuotationDocumentEditor $editor, QuotationTemplateCatalog $templates): mixed
    {
        $this->authorizeQuotation($request, $quotation);
        $data = $request->validate(Arr::except($this->editorPayloadRules(), ['lock_version']));
        $this->rejectLockedOriginalPreview($quotation, $data['mode']);
        $template = $templates->requireAvailable($data['template']);
        $bytes = $editor->preview($quotation, $template, $data['mode'], $data['payload']);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="quotation-preview.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function previewEditorHtml(Request $request, Quotation $quotation, QuotationDocumentEditor $editor, QuotationTemplateCatalog $templates, DocumentService $documents): mixed
    {
        $this->authorizeQuotation($request, $quotation);
        $data = $request->validate(Arr::except($this->editorPayloadRules(), ['lock_version']));
        $this->rejectLockedOriginalPreview($quotation, $data['mode']);
        $template = $templates->requireAvailable($data['template']);
        $normalized = $editor->normalizedPayload($quotation, $template, $data['mode'], $data['payload']);

        return response($documents->quotationEditorHtml($normalized), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:",
        ]);
    }

    public function previewPowerOfAttorney(
        Request $request,
        Quotation $quotation,
        QuotationDocumentEditor $editor,
        QuotationTemplateCatalog $templates,
        PowerOfAttorneyService $powerOfAttorneys,
        DocumentService $documents,
    ): mixed {
        $preview = $this->powerOfAttorneyPreviewData($request, $quotation, $editor, $templates, $powerOfAttorneys);
        $bytes = $documents->powerOfAttorneyPdf($preview['payload'], $preview['sale_signature']);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="power-of-attorney-preview.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function previewPowerOfAttorneyHtml(
        Request $request,
        Quotation $quotation,
        QuotationDocumentEditor $editor,
        QuotationTemplateCatalog $templates,
        PowerOfAttorneyService $powerOfAttorneys,
        DocumentService $documents,
    ): mixed {
        $preview = $this->powerOfAttorneyPreviewData($request, $quotation, $editor, $templates, $powerOfAttorneys);

        return response($documents->powerOfAttorneyEditorHtml($preview['payload'], $preview['sale_signature']), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:",
        ]);
    }

    public function savePowerOfAttorneyDraft(
        Request $request,
        Quotation $quotation,
        QuotationTemplateCatalog $templates,
        PowerOfAttorneyService $powerOfAttorneys,
    ): JsonResponse {
        $this->authorizeQuotation($request, $quotation);
        $data = $request->validate(Arr::except($this->editorPayloadRules(), ['lock_version']));
        abort_if($data['mode'] !== 'original', 422, 'หนังสือมอบอำนาจสร้างคู่กับใบเสนอราคาต้นฉบับเท่านั้น');
        $templates->requireAvailable($data['template']);
        $bill = $quotation->billingRecord()->firstOrFail();
        $version = QuotationDocumentVersion::query()
            ->whereKey($bill->quotation_document_version_id)
            ->where('qt_id', $quotation->qt_id)
            ->where('bill_id', $bill->bill_id)
            ->firstOrFail();
        $authorization = $powerOfAttorneys->saveForQuotationVersion($quotation, $bill, $version, $data['payload']);

        return response()->json(['data' => [
            'authorization_version_id' => $authorization->authorization_version_id,
            'authorization_no' => $authorization->authorization_no,
            'filename' => $authorization->filename,
            'draft_pdf_sha256' => $authorization->draft_pdf_sha256,
        ]]);
    }

    public function confirmEditor(Request $request, Quotation $quotation, QuotationDocumentEditor $editor, QuotationTemplateCatalog $templates): JsonResponse
    {
        $this->authorizeQuotation($request, $quotation);
        $data = $request->validate(Arr::except($this->editorPayloadRules(), ['lock_version']));
        $template = $templates->requireAvailable($data['template']);
        $version = $editor->confirm($quotation, $request->user(), $template, $data['mode'], $data['payload']);

        return response()->json(['data' => [
            'document_version_id' => $version->document_version_id,
            'revision_no' => $version->revision_no,
            'filename' => $version->filename,
            'pdf_sha256' => $version->pdf_sha256,
            'created_at' => $version->created_at?->toIso8601String(),
        ]], 201);
    }

    /** @return array{payload: array<string, mixed>, sale_signature: string} */
    private function powerOfAttorneyPreviewData(
        Request $request,
        Quotation $quotation,
        QuotationDocumentEditor $editor,
        QuotationTemplateCatalog $templates,
        PowerOfAttorneyService $powerOfAttorneys,
    ): array {
        $this->authorizeQuotation($request, $quotation);
        $data = $request->validate(Arr::except($this->editorPayloadRules(), ['lock_version']));
        abort_if($data['mode'] !== 'original', 422, 'หนังสือมอบอำนาจสร้างคู่กับใบเสนอราคาต้นฉบับเท่านั้น');
        $template = $templates->requireAvailable($data['template']);
        $normalized = $editor->normalizedPayload($quotation, $template, $data['mode'], $data['payload']);

        return $powerOfAttorneys->previewData($quotation, $normalized);
    }

    public function editorVersionPdf(Request $request, Quotation $quotation, QuotationDocumentVersion $version, DocumentService $documents): mixed
    {
        $this->authorizeQuotation($request, $quotation);
        abort_unless((int) $version->qt_id === (int) $quotation->qt_id, 404);
        $bill = $quotation->billingRecord;
        $bytes = $documents->confirmedQuotationVersionPdf($version, $bill);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $documents->attachmentDisposition($version->filename),
            'X-Document-SHA256' => hash('sha256', $bytes),
        ]);
    }

    public function fromPurchaseOrder(
        Request $request,
        BillingRecord $bill,
        QuotationFromPurchaseOrder $creator,
        QuotationNumberGenerator $numbers,
    ): JsonResponse {
        if (RoleAccess::mustUseOwnBillingRecords($request->user()->role)
            && (int) ($bill->creator_user_id ?? 0) !== (int) $request->user()->id
            && ! ($bill->creator_user_id === null && $bill->created_by === $request->user()->username)) {
            abort(403, 'คุณไม่มีสิทธิ์สร้างใบเสนอราคาของรายการนี้');
        }

        $quotation = $creator->createOrGet($request->user(), $bill, $numbers);

        return response()->json(['data' => ['qt_id' => $quotation->qt_id, 'qt_no' => $quotation->qt_no]]);
    }

    public function pdf(Request $request, Quotation $quotation, DocumentService $documents, QuotationTemplateCatalog $templates): mixed
    {
        $quotation->loadMissing('creator:id,username,first_name,last_name,phone,department');
        $this->authorizeQuotation($request, $quotation);
        $data = $request->validate(['template' => ['nullable', 'string', 'max:64']]);
        $template = $templates->requireAvailable($data['template'] ?? null);
        $copy = $request->boolean('copy');
        $revised = $request->boolean('revised');
        abort_if($copy && $revised, 422, 'กรุณาเลือกประเภทเอกสารเพียงประเภทเดียว');
        $draft = $quotation->billingRecord()->with(['items', 'signatures', 'documents', 'customerAcceptanceSignatures'])->first();
        $mode = $copy ? 'copy' : ($revised ? 'revised' : 'original');
        $confirmed = $draft && $draft->quotation_document_version_id
            ? QuotationDocumentVersion::query()
                ->where('document_version_id', $draft->quotation_document_version_id)
                ->where('qt_id', $quotation->qt_id)
                ->where('template_id', $template)
                ->where('document_mode', $mode)
                ->first()
            : null;
        $bytes = $confirmed
            ? $documents->confirmedQuotationVersionPdf($confirmed, $draft)
            : $documents->quotationPdf($quotation, $copy, $draft, $revised, template: $template);
        $quotation->forceFill(['quotation_printed_at' => now(), 'quotation_printed_by' => $request->user()->username])->save();
        $filename = $documents->quotationDownloadName($quotation, $copy, $revised);
        DocumentDownloadAudit::capture($request, $copy ? 'quotation_copy' : ($revised ? 'quotation_revised' : 'quotation_original'), $quotation, $draft);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $documents->attachmentDisposition($filename),
        ]);
    }

    private function authorizeQuotation(Request $request, Quotation $quotation): void
    {
        if (RoleAccess::mustUseOwnBillingRecords($request->user()->role)
            && (int) ($quotation->creator_user_id ?? 0) !== (int) $request->user()->id
            && ! ($quotation->creator_user_id === null && $quotation->created_by === $request->user()->username)) {
            abort(403, 'คุณไม่มีสิทธิ์เข้าถึงใบเสนอราคานี้');
        }
    }

    private function rejectLockedOriginalPreview(Quotation $quotation, string $mode): void
    {
        if ($mode === 'original' && $quotation->billingRecord?->status !== null
            && $quotation->billingRecord->status !== 'ฉบับร่าง') {
            abort(409, 'ใบเสนอราคาต้นฉบับส่งอนุมัติแล้ว กรุณาเปิด PDF ฉบับที่ยืนยัน');
        }
    }

    /** @return array<string, mixed> */
    private function helpRequestData(QuotationDocumentHelpRequest $helpRequest): array
    {
        return [
            'help_request_id' => $helpRequest->help_request_id,
            'qt_id' => $helpRequest->qt_id,
            'template_id' => $helpRequest->template_id,
            'document_mode' => $helpRequest->document_mode,
            'request_note' => $helpRequest->request_note,
            'status' => $helpRequest->status,
            'requested_by' => $helpRequest->requested_by,
            'assigned_to' => $helpRequest->assigned_to,
            'resolution_note' => $helpRequest->resolution_note,
            'requested_at' => $helpRequest->requested_at?->toIso8601String(),
            'accepted_at' => $helpRequest->accepted_at?->toIso8601String(),
            'resolved_at' => $helpRequest->resolved_at?->toIso8601String(),
            'quotation' => $helpRequest->relationLoaded('quotation') ? $helpRequest->quotation : null,
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function editorPayloadRules(): array
    {
        return [
            'template' => ['required', 'string', 'max:64'],
            'mode' => ['required', 'in:original,copy,revised'],
            'lock_version' => ['sometimes', 'integer', 'min:0'],
            'payload' => ['required', 'array'],
            'payload.document' => ['required', 'array'],
            'payload.document.number' => ['required', 'string', 'max:2000'],
            'payload.document.date' => ['required', 'date_format:Y-m-d'],
            'payload.document.title' => ['nullable', 'string', 'max:2000'],
            'payload.document.english_title' => ['nullable', 'string', 'max:2000'],
            'payload.document.company_name' => ['nullable', 'string', 'max:2000'],
            'payload.document.company_address' => ['nullable', 'string', 'max:2000'],
            'payload.document.company_contact' => ['nullable', 'string', 'max:2000'],
            'payload.document.company_tax_id' => ['nullable', 'string', 'max:2000'],
            'payload.document.branch' => ['nullable', 'string', 'max:2000'],
            'payload.document.subject' => ['nullable', 'string', 'max:2000'],
            'payload.document.recipient' => ['required', 'string', 'max:2000'],
            'payload.document.intro' => ['nullable', 'string', 'max:5000'],
            'payload.document.salesperson' => ['required', 'string', 'max:2000'],
            'payload.document.signature_role' => ['nullable', 'string', 'max:2000'],
            'payload.document.contact' => ['nullable', 'string', 'max:2000'],
            'payload.document.confirmation_terms' => ['nullable', 'string', 'max:2000'],
            'payload.document.document_note' => ['nullable', 'string', 'max:2000'],
            'payload.document.summary_gross_label' => ['nullable', 'string', 'max:2000'],
            'payload.document.summary_gross_value' => ['nullable', 'string', 'max:2000'],
            'payload.document.summary_discount_label' => ['nullable', 'string', 'max:2000'],
            'payload.document.summary_discount_value' => ['nullable', 'string', 'max:2000'],
            'payload.document.summary_net_label' => ['nullable', 'string', 'max:2000'],
            'payload.document.summary_net_value' => ['nullable', 'string', 'max:2000'],
            'payload.document.summary_subtotal_label' => ['nullable', 'string', 'max:2000'],
            'payload.document.summary_subtotal_value' => ['nullable', 'string', 'max:2000'],
            'payload.document.summary_vat_label' => ['nullable', 'string', 'max:2000'],
            'payload.document.summary_vat_value' => ['nullable', 'string', 'max:2000'],
            'payload.document.summary_total_label' => ['nullable', 'string', 'max:2000'],
            'payload.document.summary_total_value' => ['nullable', 'string', 'max:2000'],
            'payload.document.summary_amount_words' => ['nullable', 'string', 'max:2000'],
            'payload.customer' => ['required', 'array'],
            'payload.customer.code' => ['nullable', 'string', 'max:255'],
            'payload.customer.address' => ['nullable', 'string', 'max:5000'],
            'payload.customer.billing_address' => ['nullable', 'string', 'max:5000'],
            'payload.customer.delivery_address' => ['nullable', 'string', 'max:5000'],
            'payload.customer.show_billing_address' => ['required', 'boolean'],
            'payload.customer.show_delivery_address' => ['required', 'boolean'],
            'payload.customer.tax_id' => ['nullable', 'string', 'max:255'],
            'payload.items' => ['required', 'array', 'min:1', 'max:100'],
            'payload.items.*.row_key' => ['required', 'string', 'max:100'],
            'payload.items.*.sku' => ['nullable', 'string', 'max:255'],
            'payload.items.*.description' => ['required', 'string', 'max:2000'],
            'payload.items.*.quantity' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'payload.items.*.quantity_display' => ['nullable', 'string', 'max:2000'],
            'payload.items.*.unit' => ['nullable', 'string', 'max:255'],
            'payload.items.*.pack' => ['nullable', 'string', 'max:255'],
            'payload.items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'payload.items.*.unit_price_display' => ['nullable', 'string', 'max:2000'],
            'payload.items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'payload.items.*.discount_display' => ['nullable', 'string', 'max:2000'],
            'payload.items.*.remark' => ['nullable', 'string', 'max:1000'],
            'payload.items.*.origin_details' => ['nullable', 'string', 'max:2000'],
            'payload.items.*.image' => ['nullable', 'string', 'max:2000000', 'regex:#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#'],
            'payload.table_layout' => ['nullable', 'array', 'min:3', 'max:12'],
            'payload.table_layout.*.id' => ['required', 'string', 'max:64', 'distinct', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'payload.table_layout.*.field' => ['required', 'in:sequence,sku,description,quantity,unit,pack,unit_price,discount,amount,remark,origin_details'],
            'payload.table_layout.*.label' => ['nullable', 'string', 'max:255'],
            'payload.table_layout.*.width' => ['required', 'numeric', 'min:5', 'max:60'],
            'payload.table_layout.*.align' => ['required', 'in:left,center,right'],
            'payload.layout' => ['nullable', 'array'],
            'payload.layout.show_summary' => ['sometimes', 'boolean'],
            'payload.layout.show_customer_details' => ['sometimes', 'boolean'],
            'payload.layout.show_confirmation_terms' => ['sometimes', 'boolean'],
            'payload.layout.show_document_note' => ['sometimes', 'boolean'],
            'payload.layout.show_signatures' => ['sometimes', 'boolean'],
            'payload.layout.show_company_signature' => ['sometimes', 'boolean'],
            'payload.layout.show_customer_signature' => ['sometimes', 'boolean'],
            'payload.layout.title_alignment' => ['sometimes', 'in:left,center,right'],
            'payload.layout.logo_alignment' => ['sometimes', 'in:left,center,right'],
            'payload.bill_discount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'payload.commercial_baseline' => ['nullable', 'string', 'max:64'],
            'payload.power_of_attorney' => ['required', 'array'],
            'payload.power_of_attorney.enabled' => ['required', 'boolean'],
            'payload.power_of_attorney.template_id' => ['required', 'in:general-company,anandamahidol,egp,grantee-company-form,anandamahidol-ebidding'],
            'payload.power_of_attorney.authorization_no' => ['required', 'string', 'max:100'],
            'payload.power_of_attorney.issue_date' => ['required', 'date_format:Y-m-d'],
            'payload.power_of_attorney.place' => ['required', 'string', 'max:500'],
            'payload.power_of_attorney.company_name' => ['required', 'string', 'max:500'],
            'payload.power_of_attorney.company_address' => ['required', 'string', 'max:1000'],
            'payload.power_of_attorney.grantor_text' => ['required', 'string', 'max:500'],
            'payload.power_of_attorney.grantor_one_name' => ['required', 'string', 'max:500'],
            'payload.power_of_attorney.grantor_two_name' => ['required', 'string', 'max:500'],
            'payload.power_of_attorney.grantee_name' => ['required', 'string', 'max:500'],
            'payload.power_of_attorney.grantee_job_title' => ['nullable', 'string', 'max:500'],
            'payload.power_of_attorney.grantee_address' => ['nullable', 'string', 'max:2000'],
            'payload.power_of_attorney.project' => ['nullable', 'string', 'max:2000'],
            'payload.power_of_attorney.organization' => ['required', 'string', 'max:1000'],
            'payload.power_of_attorney.sale_identity_no' => ['nullable', 'digits:13'],
            'payload.power_of_attorney.national_id_issued_at' => ['nullable', 'date_format:Y-m-d'],
            'payload.power_of_attorney.national_id_expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:payload.power_of_attorney.national_id_issued_at'],
            'payload.power_of_attorney.authority_note' => ['nullable', 'string', 'max:2000'],
            'payload.power_of_attorney.acceptance_text' => ['required', 'string', 'max:3000'],
            'payload.power_of_attorney.body_intro' => ['nullable', 'string', 'max:5000'],
            'payload.power_of_attorney.body_scope' => ['nullable', 'string', 'max:5000'],
            'payload.power_of_attorney.body_closing' => ['nullable', 'string', 'max:3000'],
            'payload.power_of_attorney.witness_one_name' => ['nullable', 'string', 'max:500'],
            'payload.power_of_attorney.witness_two_name' => ['nullable', 'string', 'max:500'],
            'payload.power_of_attorney.footer_text' => ['required', 'string', 'max:3000'],
            'payload.power_of_attorney.stamp_text' => ['required', 'string', 'max:500'],
            'payload.power_of_attorney.authority_quote' => ['required', 'boolean'],
            'payload.power_of_attorney.authority_documents' => ['required', 'boolean'],
            'payload.power_of_attorney.authority_negotiate' => ['required', 'boolean'],
            'payload.power_of_attorney.authority_modify' => ['required', 'boolean'],
            'payload.power_of_attorney.authority_contract' => ['required', 'boolean'],
            'payload.power_of_attorney.authority_order' => ['required', 'boolean'],
            'payload.terms' => ['nullable', 'array', 'max:30'],
            'payload.terms.*' => ['required', 'string', 'max:2000'],
        ];
    }
}
