<?php

namespace App\Services\Documents;

use App\Models\BillingRecord;
use App\Models\MasterProduct;
use App\Models\Quotation;
use App\Models\QuotationDocumentDraft;
use App\Models\QuotationDocumentVersion;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class QuotationDocumentEditor
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly QuotationTemplateCatalog $templates,
        private readonly PowerOfAttorneyService $powerOfAttorneys,
    ) {}

    /** @return array{payload: array<string, mixed>, lock_version: int, saved_at: ?string, versions: array<int, array<string, mixed>>, locked: bool, status: ?string, approved_version_id: ?int} */
    public function open(Quotation $quotation, string $template, string $mode): array
    {
        $this->templates->requireAvailable($template);
        $this->requireMode($mode);
        $draft = QuotationDocumentDraft::query()
            ->where('qt_id', $quotation->qt_id)
            ->where('template_id', $template)
            ->where('document_mode', $mode)
            ->first();
        $latest = QuotationDocumentVersion::query()
            ->where('qt_id', $quotation->qt_id)
            ->where('template_id', $template)
            ->where('document_mode', $mode)
            ->latest('revision_no')
            ->first();
        $bill = $quotation->billingRecord;
        $locked = $mode === 'original' && $bill && $bill->status !== 'ฉบับร่าง';
        $approvedVersion = $locked && $bill->quotation_document_version_id
            ? QuotationDocumentVersion::query()
                ->whereKey($bill->quotation_document_version_id)
                ->where('qt_id', $quotation->qt_id)
                ->where('bill_id', $bill->bill_id)
                ->where('template_id', $template)
                ->where('document_mode', $mode)
                ->first()
            : null;
        $payload = $approvedVersion?->payload ?? $draft?->payload ?? $latest?->payload ?? $this->initialPayload($quotation, $template, $mode);
        if (! $locked) {
            $payload = $this->normalize($quotation, $template, $mode, $payload);
        }

        return [
            'payload' => $locked ? $payload : $this->rebaseCommercialRows($quotation, $template, $mode, $payload),
            'lock_version' => (int) ($draft?->lock_version ?? 0),
            'saved_at' => $draft?->updated_at?->toIso8601String(),
            'versions' => $this->versions($quotation, $template, $mode),
            'locked' => (bool) $locked,
            'status' => $bill?->status,
            'approved_version_id' => $approvedVersion?->document_version_id,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function saveDraft(Quotation $quotation, User $actor, string $template, string $mode, array $payload, int $expectedLockVersion): QuotationDocumentDraft
    {
        $normalized = $this->normalize($quotation, $template, $mode, $payload);

        return DB::transaction(function () use ($quotation, $actor, $template, $mode, $normalized, $expectedLockVersion): QuotationDocumentDraft {
            $bill = $mode === 'original'
                ? BillingRecord::query()->where('ref_qt_id', $quotation->qt_id)->lockForUpdate()->first()
                : null;
            if ($bill && $bill->status !== 'ฉบับร่าง') {
                throw ValidationException::withMessages(['status' => 'ใบเสนอราคาต้นฉบับส่งอนุมัติแล้ว ไม่สามารถแก้ Draft ทับเอกสารที่ยืนยัน']);
            }
            $draft = QuotationDocumentDraft::query()
                ->where('qt_id', $quotation->qt_id)
                ->where('template_id', $template)
                ->where('document_mode', $mode)
                ->lockForUpdate()
                ->first();
            if ($draft && $draft->lock_version !== $expectedLockVersion) {
                throw new ConflictHttpException('เอกสารถูกแก้ไขจากอีกหน้าต่าง กรุณาโหลดข้อมูลล่าสุดก่อนบันทึก');
            }
            if (! $draft && $expectedLockVersion !== 0) {
                throw new ConflictHttpException('ไม่พบ Draft รุ่นที่กำลังแก้ไข กรุณาโหลดข้อมูลล่าสุด');
            }

            if (! $draft) {
                return QuotationDocumentDraft::query()->create([
                    'qt_id' => $quotation->qt_id,
                    'template_id' => $template,
                    'document_mode' => $mode,
                    'payload' => $normalized,
                    'lock_version' => 1,
                    'updated_by_user_id' => $actor->id,
                    'updated_by' => $actor->username,
                ]);
            }

            $draft->forceFill([
                'payload' => $normalized,
                'lock_version' => $draft->lock_version + 1,
                'updated_by_user_id' => $actor->id,
                'updated_by' => $actor->username,
            ])->save();

            return $draft->fresh();
        });
    }

    /** @param array<string, mixed> $payload */
    public function preview(Quotation $quotation, string $template, string $mode, array $payload): string
    {
        return $this->documents->quotationEditorPdf($this->normalize($quotation, $template, $mode, $payload));
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function normalizedPayload(Quotation $quotation, string $template, string $mode, array $payload): array
    {
        return $this->normalize($quotation, $template, $mode, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function confirm(Quotation $quotation, User $actor, string $template, string $mode, array $payload): QuotationDocumentVersion
    {
        $normalized = $this->normalize($quotation, $template, $mode, $payload);

        return DB::transaction(function () use ($quotation, $actor, $template, $mode, $normalized): QuotationDocumentVersion {
            $quotation = Quotation::query()->whereKey($quotation->qt_id)->lockForUpdate()->firstOrFail();
            $bill = BillingRecord::query()->where('ref_qt_id', $quotation->qt_id)->lockForUpdate()->first();
            if ($bill && $bill->status !== 'ฉบับร่าง' && $mode === 'original') {
                throw ValidationException::withMessages(['status' => 'ใบเสนอราคาต้นฉบับส่งอนุมัติแล้ว ไม่สามารถยืนยันฉบับใหม่ทับรายการเดิม']);
            }
            if ($mode === 'original'
                && ($quotation->creator_user_id
                    ? (int) $quotation->creator_user_id !== (int) $actor->id
                    : $quotation->created_by !== $actor->username)) {
                throw ValidationException::withMessages(['power_of_attorney' => 'เจ้าของใบเสนอราคา Sale ต้องเป็นผู้ตรวจและยืนยัน Draft เอกสารด้วยบัญชีของตนเอง']);
            }
            if ($bill && ! hash_equals((string) $normalized['commercial_baseline'], $this->commercialBaseline($bill))) {
                throw new ConflictHttpException('ข้อมูลสินค้าในบิลถูกแก้จากหน้าอื่น กรุณาโหลดใบเสนอราคาใหม่ก่อนยืนยัน');
            }
            $normalized = $this->syncCommercialRows($quotation, $bill, $normalized, $actor);
            $pdf = $this->documents->quotationEditorPdf($normalized);
            $revision = ((int) QuotationDocumentVersion::query()
                ->where('qt_id', $quotation->qt_id)
                ->where('template_id', $template)
                ->where('document_mode', $mode)
                ->max('revision_no')) + 1;
            $safeNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) data_get($normalized, 'document.number')) ?: 'quotation';
            $filename = "Quotation_{$safeNumber}_R{$revision}.pdf";
            $billId = $quotation->billingRecord()->value('bill_id');

            $version = QuotationDocumentVersion::query()->create([
                'qt_id' => $quotation->qt_id,
                'bill_id' => $billId,
                'template_id' => $template,
                'document_mode' => $mode,
                'revision_no' => $revision,
                'payload' => $normalized,
                'pdf_base64' => base64_encode($pdf),
                'pdf_sha256' => hash('sha256', $pdf),
                'filename' => $filename,
                'confirmed_by_user_id' => $actor->id,
                'confirmed_by' => $actor->username,
                'created_at' => now(),
            ]);

            if ($billId !== null && $mode === 'original') {
                if ((bool) data_get($normalized, 'power_of_attorney.enabled', true)) {
                    $this->powerOfAttorneys->createForQuotationVersion($quotation, $bill, $version);
                }
                DB::table('billing_records')
                    ->where('bill_id', $billId)
                    ->where('status', 'ฉบับร่าง')
                    ->update(['quotation_document_version_id' => $version->document_version_id]);
            }

            QuotationDocumentDraft::query()
                ->where('qt_id', $quotation->qt_id)
                ->where('template_id', $template)
                ->where('document_mode', $mode)
                ->update(['payload' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);

            return $version;
        });
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function syncCommercialRows(Quotation $quotation, ?BillingRecord $bill, array $payload, User $actor): array
    {
        $quotationItems = $quotation->items()->orderBy('qt_item_id')->lockForUpdate()->get();
        $billItems = $bill?->items()->orderBy('item_id')->lockForUpdate()->get() ?? collect();
        $byBillId = $billItems->keyBy('item_id');
        $byQuotationId = $quotationItems->keyBy('qt_item_id');
        $seen = [];
        $changes = $billItems->count() !== count($payload['items']);
        $rows = [];
        $gross = 0.0;

        foreach ($payload['items'] as $index => $item) {
            $key = (string) $item['row_key'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['items' => 'พบรายการสินค้าซ้ำ กรุณาโหลดเอกสารใหม่']);
            }
            $seen[$key] = true;
            $id = ctype_digit($key) ? (int) $key : (str_starts_with($key, 'bill-') ? (int) substr($key, 5) : null);
            $billItem = $id ? $byBillId->get($id) : null;
            $quotationItem = $billItem ? $quotationItems->get($billItems->search(fn ($row) => $row->item_id === $billItem->item_id)) : ($bill ? null : ($id ? $byQuotationId->get($id) : null));
            if ($id && ! $billItem && ! $quotationItem) {
                throw new ConflictHttpException('รายการสินค้าเปลี่ยนไปแล้ว กรุณาโหลดเอกสารใหม่');
            }
            $sku = trim((string) $item['sku']);
            $quantity = (float) $item['quantity'];
            $price = round((float) $item['unit_price'], 2);
            $discount = round((float) $item['discount'], 2);
            $before = round($quantity * $price, 2);
            if ($sku === '' || $quantity < 1 || floor($quantity) !== $quantity || $discount > $before) {
                throw ValidationException::withMessages(['items' => 'กรุณาเลือกรหัสสินค้า ระบุจำนวนเต็มมากกว่า 0 และตรวจส่วนลดไม่ให้เกินราคา']);
            }
            $old = $billItem ?? $quotationItem;
            $unit = trim((string) $item['unit']) ?: 'ชิ้น';
            $changes = $changes || ! $old || $sku !== (string) $old->main_category
                || $quantity !== (float) $old->quantity || $price !== (float) $old->unit_price
                || $discount !== (float) $old->sale_discount_unit || $unit !== (string) $old->unit_type;
            $gross += max($before - $discount, 0);
            $rows[] = compact('item', 'billItem', 'quotationItem', 'sku', 'quantity', 'price', 'discount', 'before', 'unit');
        }
        $tailDiscount = round((float) $payload['bill_discount'], 2);
        if ($tailDiscount > $gross) {
            throw ValidationException::withMessages(['bill_discount' => 'ส่วนลดท้ายบิลต้องไม่เกินยอดรวมรายการสินค้า']);
        }
        $changes = $changes || $tailDiscount !== (float) ($bill?->sale_discount_total ?? $quotation->sale_discount_total);
        if (! $changes) {
            return $payload;
        }
        if (! $bill || $bill->status !== 'ฉบับร่าง') {
            throw ValidationException::withMessages(['items' => 'แก้จำนวน ราคา ส่วนลด หรือเพิ่ม/ลบสินค้าได้เฉพาะใบเสนอราคาที่เป็น Draft ก่อนส่งอนุมัติ']);
        }
        $codes = collect($rows)->filter(fn (array $row): bool => ! $row['billItem'] || (string) $row['billItem']->main_category !== $row['sku'])
            ->pluck('sku')->unique()->all();
        $products = MasterProduct::query()->whereIn('sku_code', $codes)->get(['sku_code', 'sku_name'])->keyBy('sku_code');
        if ($products->count() !== count($codes)) {
            throw ValidationException::withMessages(['items' => 'มีรหัสสินค้าไม่อยู่ใน Master Product กรุณาเลือกสินค้าจากรายการ']);
        }
        $keepBill = [];
        $keepQuotation = [];
        foreach ($rows as $index => $row) {
            $item = $row['item'];
            $product = $products->get($row['sku']);
            $line = [
                'main_category' => $row['sku'], 'sub_category' => $product?->sku_name ?? $row['billItem']?->sub_category ?? $row['quotationItem']?->sub_category,
                'unit_type' => $row['unit'], 'quantity' => (int) $row['quantity'],
                'unit_price' => $row['price'], 'sale_discount_unit' => $row['discount'],
                'line_total_before_discount' => $row['before'],
                'line_total' => round($row['before'] - $row['discount'], 2),
            ];
            $quotationItem = $row['quotationItem'] ?? $quotation->items()->create($line);
            $quotationItem->forceFill($line)->save();
            $billItem = $row['billItem'] ?? $bill->items()->create($line);
            $billItem->forceFill($line)->save();
            $keepQuotation[] = $quotationItem->qt_item_id;
            $keepBill[] = $billItem->item_id;
            $payload['items'][$index]['row_key'] = (string) $billItem->item_id;
        }
        $bill->items()->whereNotIn('item_id', $keepBill)->delete();
        $quotation->items()->whereNotIn('qt_item_id', $keepQuotation)->delete();
        $total = round(max($gross - min($tailDiscount, $gross), 0), 2);
        $bill->forceFill(['sale_discount_total' => min($tailDiscount, $gross), 'total_amount' => $total, 'quotation_document_version_id' => null])->save();
        $quotation->forceFill(['sale_discount_total' => min($tailDiscount, $gross), 'total_amount' => $total])->save();
        $payload['commercial_baseline'] = $this->commercialBaseline($bill);
        DB::table('edit_logs')->insert(['bill_id' => $bill->bill_id, 'edited_by' => $actor->username, 'edit_time' => now(), 'edit_summary' => 'ปรับรายการสินค้าและยอดจากหน้าแก้ไขใบเสนอราคา']);
        QuotationDocumentDraft::query()->where('qt_id', $quotation->qt_id)->delete();

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    public function versions(Quotation $quotation, string $template, string $mode): array
    {
        return QuotationDocumentVersion::query()
            ->where('qt_id', $quotation->qt_id)
            ->where('template_id', $template)
            ->where('document_mode', $mode)
            ->latest('revision_no')
            ->get(['document_version_id', 'revision_no', 'filename', 'pdf_sha256', 'confirmed_by', 'created_at'])
            ->toArray();
    }

    /** @return array<string, mixed> */
    private function initialPayload(Quotation $quotation, string $template, string $mode): array
    {
        $quotation->loadMissing(['items', 'billingRecord.items']);
        $source = $quotation->billingRecord ?? $quotation;
        $items = $quotation->billingRecord?->items ?? $quotation->items;
        $profile = $template === QuotationTemplateCatalog::STANDARD
            ? ['subject' => 'ใบเสนอราคา', 'recipient' => (string) $source->buyer_name, 'intro' => 'บริษัท เวเลอร์ เฮลธ์ จำกัด มีความยินดีขอเสนอราคา ดังรายการต่อไปนี้', 'terms' => []]
            : $this->templates->profile($template);
        $documentNumber = (str_replace('-', '', (string) $quotation->qt_no) ?: 'QT'.str_pad((string) $quotation->qt_id, 5, '0', STR_PAD_LEFT)).'/R'.max(1, (int) ($quotation->revision_no ?? 1));
        $date = ($quotation->workflow_type === 'customer_po_direct' ? $quotation->qt_date : ($source->bill_date ?? $quotation->qt_date))?->format('Y-m-d') ?? now()->format('Y-m-d');
        $creatorId = $quotation->creator_user_id ?? $source->creator_user_id;
        $salesUser = $creatorId ? User::query()->find($creatorId, [
            'first_name', 'last_name', 'username', 'phone', 'job_title', 'representative_address',
            'national_id', 'national_id_issued_at', 'national_id_expires_at',
        ]) : null;
        $salesperson = trim((string) ($salesUser?->first_name ?? '').' '.(string) ($salesUser?->last_name ?? ''))
            ?: (string) ($source->created_by ?? $quotation->created_by ?? '');
        $phone = trim((string) ($salesUser?->phone ?? ''));
        $signatureRole = match ($template) {
            QuotationTemplateCatalog::LUANGPHO_THAWEESAK => 'ตัวแทนบริษัทฯ',
            QuotationTemplateCatalog::POLICE_GENERAL, QuotationTemplateCatalog::RAMATHIBODI => 'ผู้แทนฝ่ายขาย',
            QuotationTemplateCatalog::CHAROENKRUNG_PRACHARAK => 'ผู้แทนขาย',
            default => 'ผู้เสนอราคา',
        };
        $contact = in_array($template, [
            QuotationTemplateCatalog::LUANGPHO_THAWEESAK,
            QuotationTemplateCatalog::POLICE_GENERAL,
            QuotationTemplateCatalog::CHAROENKRUNG_PRACHARAK,
        ], true) ? 'ติดต่อสอบถามเพิ่มเติมได้ที่ คุณ'.$salesperson.($phone !== '' ? ' โทร.'.$phone : '').' Fax : 02-678-8400'
            : ($template === QuotationTemplateCatalog::RAMATHIBODI && $phone !== '' ? 'โทร.'.$phone : '');
        if ($template === QuotationTemplateCatalog::SIRIRAJ_PIYAMAHARAJKARUN) {
            $contact = 'ชื่อผู้แทน และ Client '.$salesperson.($phone !== '' ? ' เบอร์ติดต่อ '.$phone : '');
        }

        return [
            'template' => $template,
            'mode' => $mode,
            'document' => [
                'number' => $documentNumber,
                'date' => $date,
                'subject' => (string) ($profile['subject'] ?? ''),
                'recipient' => (string) ($profile['recipient'] ?? $source->buyer_name),
                'intro' => (string) ($profile['intro'] ?? ''),
                'salesperson' => $salesperson,
                'signature_role' => $signatureRole,
                'contact' => $contact,
                'confirmation_terms' => 'ลูกค้าลงนามในเอกสารนี้ หรือออก PO ที่อ้างอิงใบเสนอราคา',
                'document_note' => 'กรุณาตรวจสอบรายการ ราคา เงื่อนไขชำระเงิน และกำหนดส่งก่อนยืนยัน',
            ],
            'customer' => [
                'code' => (string) ($source->bplus_customer_code ?? ''),
                'address' => (string) ($source->address_note ?? ''),
                'tax_id' => (string) ($source->tax_id ?: '-'),
            ],
            'items' => $items->values()->map(fn ($item, int $index): array => [
                'row_key' => (string) ($item->item_id ?? $item->qt_item_id ?? 'row-'.($index + 1)),
                'sku' => (string) ($item->main_category ?? ''),
                'description' => (string) ($item->customer_product_name ?: $item->sub_category),
                'quantity' => (float) ($item->quantity ?? 0),
                'unit' => (string) ($item->unit_type ?? 'ชิ้น'),
                'pack' => '1 '.((string) ($item->unit_type ?? 'ชิ้น')),
                'unit_price' => (float) ($item->unit_price ?? 0),
                'discount' => (float) ($item->sale_discount_unit ?? 0),
                'remark' => '',
                'origin_details' => $this->templates->exampleOriginDetails($template, (string) ($item->main_category ?? '')),
            ])->all(),
            'bill_discount' => (float) ($source->sale_discount_total ?? 0),
            'commercial_baseline' => $quotation->billingRecord ? $this->commercialBaseline($quotation->billingRecord) : '',
            'power_of_attorney' => [
                'enabled' => true,
                'template_id' => str_contains((string) $source->buyer_name, 'อานันทมหิดล')
                    ? PowerOfAttorneyTemplateCatalog::ANANDAMAHIDOL
                    : PowerOfAttorneyTemplateCatalog::GENERAL,
                'authorization_no' => 'POA-'.$documentNumber,
                'issue_date' => $date,
                'place' => 'บริษัท เวเลอร์ เฮลธ์ จำกัด สำนักงานใหญ่',
                'company_name' => 'บริษัท เวเลอร์ เฮลธ์ จำกัด สำนักงานใหญ่',
                'company_address' => '2/22 อาคารไอยรา ทาวเวอร์ ชั้น 10 ถนนจันทน์ แขวงทุ่งวัดดอน เขตสาทร กรุงเทพมหานคร 10120',
                'grantor_text' => 'ผู้มีอำนาจลงนามผูกพันบริษัทฯ',
                'grantor_one_name' => 'นายประเสริฐ ไวยาวัจมัย',
                'grantor_two_name' => 'นายกฤต เติมพิริยะ',
                'grantee_name' => $salesperson,
                'grantee_job_title' => (string) ($salesUser?->job_title ?? ''),
                'grantee_address' => (string) ($salesUser?->representative_address ?? ''),
                'project' => '',
                'organization' => (string) $source->buyer_name,
                'sale_identity_no' => (string) ($salesUser?->national_id ?? ''),
                'national_id_issued_at' => $salesUser?->national_id_issued_at?->format('Y-m-d') ?? '',
                'national_id_expires_at' => $salesUser?->national_id_expires_at?->format('Y-m-d') ?? '',
                'authority_note' => '',
                'acceptance_text' => 'การใดที่ผู้รับมอบอำนาจได้กระทำภายในขอบเขตแห่งหนังสือมอบอำนาจฉบับนี้ บริษัทฯ ขอรับผิดชอบเสมือนหนึ่งบริษัทฯ เป็นผู้กระทำเองทุกประการ',
                'body_intro' => '',
                'body_scope' => '',
                'body_closing' => '',
                'witness_one_name' => '',
                'witness_two_name' => '',
                'footer_text' => 'บริษัท เวเลอร์ เฮลธ์ จำกัด 2/22 อาคารไอยรา ทาวเวอร์ ชั้น 10 ถนนจันทน์ แขวงทุ่งวัดดอน เขตสาทร กรุงเทพมหานคร 10120 โทร. 02-678-7500, 02-678-5150 แฟกซ์ 02-678-8400\nValor Health Co., Ltd. · email: valorhealth@valorhealth.co.th · เลขประจำตัวผู้เสียภาษี 0105558041179 สำนักงานใหญ่',
                'stamp_text' => 'อากรแสตมป์ตามอัตราที่กฎหมายกำหนด',
                'authority_quote' => true,
                'authority_documents' => true,
                'authority_negotiate' => true,
                'authority_modify' => true,
                'authority_contract' => false,
                'authority_order' => false,
            ],
            'terms' => [
                ...array_values($profile['terms'] ?? []),
                ...($template === QuotationTemplateCatalog::SIRIRAJ_PIYAMAHARAJKARUN && $contact !== '' ? [$contact] : []),
            ],
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalize(Quotation $quotation, string $template, string $mode, array $payload): array
    {
        $this->templates->requireAvailable($template);
        $this->requireMode($mode);
        $defaults = $this->initialPayload($quotation, $template, $mode);
        $document = array_merge($defaults['document'], Arr::only((array) ($payload['document'] ?? []), [
            'number', 'date', 'subject', 'recipient', 'intro', 'salesperson', 'signature_role', 'contact',
            'confirmation_terms', 'document_note',
        ]));
        if ($template === QuotationTemplateCatalog::RAMATHIBODI && trim((string) ($document['contact'] ?? '')) === '') {
            $document['contact'] = (string) $defaults['document']['contact'];
        }
        $items = collect($payload['items'] ?? $defaults['items'])->take(100)->values()->map(function (mixed $row, int $index) use ($template): array {
            $row = (array) $row;
            $sku = mb_substr(trim((string) ($row['sku'] ?? '')), 0, 255);
            $originDetails = mb_substr(trim((string) ($row['origin_details'] ?? '')), 0, 2000);
            if ($originDetails === '') {
                $originDetails = $this->templates->exampleOriginDetails($template, $sku);
            }

            return [
                'row_key' => mb_substr((string) ($row['row_key'] ?? 'row-'.($index + 1)), 0, 100),
                'sku' => $sku,
                'description' => mb_substr(trim((string) ($row['description'] ?? '')), 0, 2000),
                'quantity' => max(0, min((float) ($row['quantity'] ?? 0), 1_000_000)),
                'unit' => mb_substr(trim((string) ($row['unit'] ?? '')), 0, 255),
                'pack' => mb_substr(trim((string) ($row['pack'] ?? '')), 0, 255),
                'unit_price' => max(0, min((float) ($row['unit_price'] ?? 0), 999_999_999)),
                'discount' => max(0, min((float) ($row['discount'] ?? 0), 999_999_999)),
                'remark' => mb_substr(trim((string) ($row['remark'] ?? '')), 0, 1000),
                'origin_details' => $originDetails,
            ];
        })->all();
        $providedTerms = collect($payload['terms'] ?? [])
            ->take(30)
            ->map(fn (mixed $term): string => mb_substr(trim((string) $term), 0, 2000))
            ->filter()
            ->values()
            ->all();
        $terms = array_values($defaults['terms']);
        foreach ($providedTerms as $index => $term) {
            $terms[$index] = $term;
        }

        foreach ($document as $key => $value) {
            $document[$key] = mb_substr(trim((string) $value), 0, $key === 'intro' ? 5000 : 2000);
        }

        $authorizationInput = (array) ($payload['power_of_attorney'] ?? []);
        $authorizationTemplate = (new PowerOfAttorneyTemplateCatalog)->normalize(
            $authorizationInput['template_id'] ?? $defaults['power_of_attorney']['template_id'],
            (string) data_get($quotation, 'billingRecord.buyer_name', $quotation->buyer_name),
        );

        return [
            'template' => $template,
            'mode' => $mode,
            'document' => $document,
            'customer' => $defaults['customer'],
            'items' => $items,
            'bill_discount' => max(0, min((float) ($payload['bill_discount'] ?? $defaults['bill_discount']), 999_999_999)),
            'commercial_baseline' => (string) ($payload['commercial_baseline'] ?? $defaults['commercial_baseline']),
            'power_of_attorney' => [
                'enabled' => (bool) ($authorizationInput['enabled'] ?? true),
                'template_id' => $authorizationTemplate,
                'authorization_no' => mb_substr(trim((string) ($authorizationInput['authorization_no'] ?? $defaults['power_of_attorney']['authorization_no'])), 0, 100),
                'issue_date' => $this->normalizedDate((string) ($authorizationInput['issue_date'] ?? $defaults['power_of_attorney']['issue_date']), (string) $defaults['power_of_attorney']['issue_date']),
                'place' => mb_substr(trim((string) ($authorizationInput['place'] ?? $defaults['power_of_attorney']['place'])), 0, 500),
                'company_name' => mb_substr(trim((string) ($authorizationInput['company_name'] ?? $defaults['power_of_attorney']['company_name'])), 0, 500),
                'company_address' => mb_substr(trim((string) ($authorizationInput['company_address'] ?? $defaults['power_of_attorney']['company_address'])), 0, 1000),
                'grantor_text' => mb_substr(trim((string) ($authorizationInput['grantor_text'] ?? $defaults['power_of_attorney']['grantor_text'])), 0, 500),
                'grantor_one_name' => mb_substr(trim((string) ($authorizationInput['grantor_one_name'] ?? $defaults['power_of_attorney']['grantor_one_name'])), 0, 500),
                'grantor_two_name' => mb_substr(trim((string) ($authorizationInput['grantor_two_name'] ?? $defaults['power_of_attorney']['grantor_two_name'])), 0, 500),
                'grantee_name' => mb_substr(trim((string) ($authorizationInput['grantee_name'] ?? $defaults['power_of_attorney']['grantee_name'])), 0, 500),
                'grantee_job_title' => mb_substr(trim((string) ($authorizationInput['grantee_job_title'] ?? $defaults['power_of_attorney']['grantee_job_title'])), 0, 500),
                'grantee_address' => mb_substr(trim((string) ($authorizationInput['grantee_address'] ?? $defaults['power_of_attorney']['grantee_address'])), 0, 2000),
                'project' => mb_substr(trim((string) ($authorizationInput['project'] ?? '')), 0, 2000),
                'organization' => mb_substr(trim((string) ($authorizationInput['organization'] ?? $defaults['power_of_attorney']['organization'])), 0, 1000),
                'sale_identity_no' => mb_substr(preg_replace('/[^0-9]/', '', (string) ($authorizationInput['sale_identity_no'] ?? '')) ?? '', 0, 13),
                'national_id_issued_at' => $this->normalizedOptionalDate((string) ($authorizationInput['national_id_issued_at'] ?? $defaults['power_of_attorney']['national_id_issued_at'])),
                'national_id_expires_at' => $this->normalizedOptionalDate((string) ($authorizationInput['national_id_expires_at'] ?? $defaults['power_of_attorney']['national_id_expires_at'])),
                'authority_note' => mb_substr(trim((string) ($authorizationInput['authority_note'] ?? '')), 0, 2000),
                'acceptance_text' => mb_substr(trim((string) ($authorizationInput['acceptance_text'] ?? $defaults['power_of_attorney']['acceptance_text'])), 0, 3000),
                'body_intro' => mb_substr(trim((string) ($authorizationInput['body_intro'] ?? '')), 0, 5000),
                'body_scope' => mb_substr(trim((string) ($authorizationInput['body_scope'] ?? '')), 0, 5000),
                'body_closing' => mb_substr(trim((string) ($authorizationInput['body_closing'] ?? '')), 0, 3000),
                'witness_one_name' => mb_substr(trim((string) ($authorizationInput['witness_one_name'] ?? '')), 0, 500),
                'witness_two_name' => mb_substr(trim((string) ($authorizationInput['witness_two_name'] ?? '')), 0, 500),
                'footer_text' => mb_substr(trim((string) ($authorizationInput['footer_text'] ?? $defaults['power_of_attorney']['footer_text'])), 0, 3000),
                'stamp_text' => mb_substr(trim((string) ($authorizationInput['stamp_text'] ?? $defaults['power_of_attorney']['stamp_text'])), 0, 500),
                'authority_quote' => (bool) ($authorizationInput['authority_quote'] ?? true),
                'authority_documents' => (bool) ($authorizationInput['authority_documents'] ?? true),
                'authority_negotiate' => (bool) ($authorizationInput['authority_negotiate'] ?? true),
                'authority_modify' => (bool) ($authorizationInput['authority_modify'] ?? true),
                'authority_contract' => (bool) ($authorizationInput['authority_contract'] ?? false),
                'authority_order' => (bool) ($authorizationInput['authority_order'] ?? false),
            ],
            'terms' => $terms,
        ];
    }

    private function normalizedDate(string $value, string $fallback): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
    }

    private function normalizedOptionalDate(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function commercialBaseline(BillingRecord $bill): string
    {
        $rows = $bill->items()->orderBy('item_id')->get([
            'item_id', 'main_category', 'unit_type', 'quantity', 'unit_price', 'sale_discount_unit',
        ])->map(fn ($item): array => [
            (int) $item->item_id, (string) $item->main_category, (string) $item->unit_type, (int) $item->quantity,
            number_format((float) $item->unit_price, 2, '.', ''),
            number_format((float) $item->sale_discount_unit, 2, '.', ''),
        ])->all();

        return hash('sha256', json_encode([
            $rows, number_format((float) $bill->sale_discount_total, 2, '.', ''),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function rebaseCommercialRows(Quotation $quotation, string $template, string $mode, array $payload): array
    {
        $bill = $quotation->billingRecord;
        if (! $bill || ($payload['commercial_baseline'] ?? '') === $this->commercialBaseline($bill)) {
            return $payload;
        }

        $current = $this->initialPayload($quotation, $template, $mode);
        $previousItems = collect($payload['items'] ?? [])->keyBy(fn (array $item): string => (string) ($item['row_key'] ?? ''));
        foreach ($current['items'] as &$item) {
            $previous = $previousItems->get($item['row_key']);
            if ($previous && (string) ($previous['sku'] ?? '') === $item['sku']) {
                foreach (['description', 'pack', 'remark', 'origin_details'] as $field) {
                    $item[$field] = $previous[$field] ?? $item[$field];
                }
            }
        }
        unset($item);
        $current['document'] = $payload['document'] ?? $current['document'];
        $current['terms'] = $payload['terms'] ?? $current['terms'];

        return $current;
    }

    private function requireMode(string $mode): void
    {
        abort_unless(in_array($mode, ['original', 'copy', 'revised'], true), 422, 'ประเภทเอกสารไม่ถูกต้อง');
    }
}
