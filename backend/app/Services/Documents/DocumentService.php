<?php

namespace App\Services\Documents;

use App\Models\BillingRecord;
use App\Models\DocumentSignature;
use App\Models\Quotation;
use App\Models\QuotationDocumentVersion;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;

final class DocumentService
{
    private const REPORT_SUCCESS_STATUSES = ['รอแอดมินส่งออก', 'อนุมัติแล้ว', 'ดำเนินการเสร็จสิ้น'];

    public function __construct(private readonly ThaiBahtText $bahtText) {}

    public function quotationDownloadName(Quotation $quotation, bool $copy = false, bool $revised = false): string
    {
        return $this->pdfDownloadName(
            prefix: ($revised ? 'REVISED_' : ($copy ? 'COPY_' : '')).'Quotation',
            customerName: (string) $quotation->buyer_name,
            date: (string) ($quotation->qt_date?->format('Y-m-d') ?? now()->format('Y-m-d')),
        );
    }

    public function billDownloadName(BillingRecord $bill, bool $copy = false, bool $canceled = false): string
    {
        $mode = $copy ? 'COPY_' : ($canceled ? 'CANCELED_' : '');

        return $this->pdfDownloadName(
            prefix: $mode.'TaxInvoice',
            customerName: (string) ($bill->tax_cust_name ?: $bill->buyer_name),
            date: (string) ($bill->bill_date?->format('Y-m-d') ?? now()->format('Y-m-d')),
        );
    }

    public function attachmentDisposition(string $filename): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii($filename)) ?: 'document.pdf';

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $fallback);
    }

    public function billPdf(BillingRecord $bill, bool $copy = false, bool $canceled = false): string
    {
        $bill->loadMissing('items');

        return $this->renderPdf(
            title: 'ใบขนส่ง / ใบกำกับภาษี',
            englishTitle: 'Delivery / Tax Invoice',
            documentNo: $this->documentNumber($bill),
            date: (string) ($bill->bill_date?->format('d-m-Y') ?? now()->format('d-m-Y')),
            customerName: (string) ($bill->tax_cust_name ?: $bill->buyer_name),
            customerCode: (string) ($bill->bplus_customer_code ?? ''),
            customerAddress: (string) ($bill->tax_cust_address ?: $bill->address_note),
            customerTaxId: (string) ($bill->tax_cust_tax_id ?: $bill->tax_id ?: '-'),
            salesperson: (string) ($bill->created_by ?? ''),
            department: 'VH000',
            note: (string) ($bill->bill_note ?? '-'),
            total: (float) $bill->total_amount,
            billDiscount: (float) ($bill->sale_discount_total ?? 0),
            items: $bill->items,
            copy: $copy,
            canceled: $canceled,
        );
    }

    /** @param array<int, string> $itemNameOverrides */
    public function quotationPdf(
        Quotation $quotation,
        bool $copy = false,
        ?BillingRecord $draft = null,
        bool $revised = false,
        array $itemNameOverrides = [],
        bool $htmlOnly = false,
        string $template = QuotationTemplateCatalog::STANDARD,
    ): string {
        app(QuotationTemplateCatalog::class)->requireAvailable($template);
        $quotation->loadMissing('items');
        if (! $quotation->relationLoaded('creator') && $quotation->creator_user_id) {
            $quotation->load('creator');
        }
        $draft?->loadMissing('items');
        $source = $draft ?? $quotation;
        $sourceItems = $draft?->items ?? $quotation->items;
        $signature = $draft && $draft->relationLoaded('signatures')
            ? $draft->signatures
                ->where('signature_type', 'internal_quotation_approval')
                ->where('revision_no', max(1, (int) ($source->revision_no ?? 1)))
                ->whereNull('revoked_at')
                ->sortByDesc('signed_at')
                ->first()
            : null;
        $customerEvidence = $draft && $draft->relationLoaded('documents')
            ? $draft->documents->whereIn('document_type', ['customer_po', 'signed_quotation'])->first()
            : null;
        $customerAcceptance = $draft && $draft->relationLoaded('customerAcceptanceSignatures')
            ? $draft->customerAcceptanceSignatures
                ->where('revision_no', max(1, (int) ($source->revision_no ?? 1)))
                ->whereNull('revoked_at')
                ->sortByDesc('accepted_at')
                ->first()
            : null;
        $items = $sourceItems->map(function ($item) use ($itemNameOverrides, $revised) {
            $renderItem = clone $item;
            $itemId = (int) ($item->item_id ?? $item->qt_item_id ?? 0);
            $systemName = trim((string) ($item->sub_category ?? ''));
            $customerName = trim((string) ($itemNameOverrides[$itemId] ?? $item->customer_product_name ?? ''));
            $renderItem->sub_category = $revised
                ? ($customerName !== '' ? $customerName : 'ยังไม่ได้ระบุชื่อสินค้าตามที่โรงพยาบาลต้องการ')
                : ($customerName !== '' && $customerName !== $systemName
                    ? "{$systemName} ({$customerName})"
                    : $systemName);

            return $renderItem;
        });

        $documentNo = (string) ((str_replace('-', '', $quotation->qt_no) ?: 'QT'.str_pad((string) $quotation->qt_id, 5, '0', STR_PAD_LEFT)).'/R'.max(1, (int) ($source->revision_no ?? 1)));
        $documentDate = (string) (($quotation->workflow_type === 'customer_po_direct'
                ? $quotation->qt_date
                : ($draft?->bill_date ?? $quotation->qt_date))?->format('d-m-Y') ?? now()->format('d-m-Y'));
        $approval = $signature ? [
            'name' => $signature->signer_name ?: $signature->signer_username,
            'role' => $signature->signer_role,
            'signed_at' => $signature->signed_at?->timezone('Asia/Bangkok')->format('d-m-Y H:i:s'),
            'hash' => $signature->payload_sha256,
            'signature_image' => $signature->signature_image_base64,
            'signature_image_hash' => $signature->signature_image_sha256,
            'customer_po_no' => $source->customer_po_no,
            'customer_confirmed_at' => $source->customer_confirmed_at,
            'customer_evidence_type' => $customerEvidence?->document_type,
            'customer_evidence_file' => $customerEvidence?->file_name,
            'customer_signature_image' => $customerAcceptance?->signature_image_base64,
            'customer_signature_hash' => $customerAcceptance?->signature_image_sha256,
            'customer_payload_hash' => $customerAcceptance?->payload_sha256,
            'customer_signer_name' => $customerAcceptance?->signer_name,
            'customer_signer_position' => $customerAcceptance?->signer_position,
            'customer_signer_organization' => $customerAcceptance?->signer_organization,
            'customer_accepted_at' => $customerAcceptance?->accepted_at?->timezone('Asia/Bangkok')->format('d-m-Y H:i:s'),
        ] : null;

        if ($template !== QuotationTemplateCatalog::STANDARD) {
            $salesUser = $quotation->creator;
            $saleName = trim((string) ($salesUser?->first_name ?? '').' '.(string) ($salesUser?->last_name ?? ''))
                ?: (string) ($source->created_by ?? '');
            $salePhone = trim((string) ($salesUser?->phone ?? ''));
            $contact = match ($template) {
                QuotationTemplateCatalog::RAMATHIBODI => $salePhone !== '' ? 'โทร. '.$salePhone : '',
                QuotationTemplateCatalog::SIRIRAJ_PIYAMAHARAJKARUN => 'ชื่อผู้แทน และ Client '.$saleName.($salePhone !== '' ? ' เบอร์ติดต่อ '.$salePhone : ''),
                default => 'ติดต่อสอบถามเพิ่มเติมได้ที่ คุณ'.$saleName.($salePhone !== '' ? ' โทร.'.$salePhone : '').' Fax : 02-678-8400',
            };

            return $this->renderHospitalQuotationPdf(
                template: $template,
                documentNo: $documentNo,
                date: $documentDate,
                salesperson: $saleName,
                note: $this->quotationNote($source),
                total: (float) $source->total_amount,
                billDiscount: (float) ($source->sale_discount_total ?? 0),
                items: $items,
                copy: $copy,
                rejected: $draft?->status === 'ไม่อนุมัติ',
                approvalEvidence: $approval,
                htmlOnly: $htmlOnly,
                overrides: $contact !== '' ? ['document' => ['contact' => $contact]] : [],
            );
        }

        return $this->renderPdf(
            title: 'ใบเสนอราคา',
            englishTitle: 'Quotation',
            documentNo: $documentNo,
            date: $documentDate,
            customerName: (string) $source->buyer_name,
            customerCode: (string) ($source->bplus_customer_code ?? ''),
            customerAddress: (string) ($source->address_note ?? ''),
            customerTaxId: (string) ($source->tax_id ?: '-'),
            salesperson: (string) ($source->created_by ?? ''),
            department: trim((string) ($quotation->creator?->department ?? '')) ?: '-',
            note: $this->quotationNote($source),
            total: (float) $source->total_amount,
            billDiscount: (float) ($source->sale_discount_total ?? 0),
            items: $items,
            copy: $copy,
            canceled: false,
            rejected: $draft?->status === 'ไม่อนุมัติ',
            approvalEvidence: $approval,
            htmlOnly: $htmlOnly,
        );
    }

    /** @param array<string, mixed> $payload */
    public function quotationEditorPdf(array $payload, bool $htmlOnly = false, ?array $approvalEvidence = null): string
    {
        $items = collect($payload['items'] ?? [])->map(function (array $item): object {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $beforeDiscount = round($quantity * $unitPrice, 2);
            $discount = min(max((float) ($item['discount'] ?? 0), 0), $beforeDiscount);

            return (object) [
                'main_category' => (string) ($item['sku'] ?? ''),
                'sub_category' => (string) ($item['description'] ?? ''),
                'unit_type' => (string) ($item['unit'] ?? ''),
                'document_pack' => (string) ($item['pack'] ?? ''),
                'document_remark' => (string) ($item['remark'] ?? ''),
                'document_origin_details' => (string) ($item['origin_details'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'sale_discount_unit' => $discount,
                'line_total_before_discount' => $beforeDiscount,
                'line_total' => round($beforeDiscount - $discount, 2),
            ];
        });
        $billDiscount = max((float) ($payload['bill_discount'] ?? 0), 0);
        $enteredTotal = max(round((float) $items->sum('line_total') - $billDiscount, 2), 0);
        $priceVatMode = ($payload['price_vat_mode'] ?? 'in_vat') === 'ex_vat' ? 'ex_vat' : 'in_vat';
        $total = $priceVatMode === 'ex_vat'
            ? round($enteredTotal * 1.07, 2)
            : $enteredTotal;
        $document = (array) ($payload['document'] ?? []);
        $customer = (array) ($payload['customer'] ?? []);
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', (string) ($document['date'] ?? ''));
        if (($payload['template'] ?? QuotationTemplateCatalog::STANDARD) === QuotationTemplateCatalog::STANDARD) {
            $billingAddress = trim((string) ($customer['billing_address'] ?? $customer['address'] ?? ''));
            $deliveryAddress = trim((string) ($customer['delivery_address'] ?? ''));
            $addressLines = [];
            $showBilling = (bool) ($customer['show_billing_address'] ?? true);
            $showDelivery = (bool) ($customer['show_delivery_address'] ?? false);
            if ($showBilling && $billingAddress !== '') {
                $addressLines[] = 'ที่อยู่ออกบิล: '.$billingAddress;
            }
            if ($showDelivery && $deliveryAddress !== '' && (! $showBilling || $deliveryAddress !== $billingAddress)) {
                $addressLines[] = 'ที่อยู่จัดส่ง: '.$deliveryAddress;
            }
            if ($addressLines === []) {
                $addressLines[] = $billingAddress !== '' ? $billingAddress : $deliveryAddress;
            }
            $note = implode(' | ', array_filter([
                (string) ($document['subject'] ?? ''),
                (string) ($document['intro'] ?? ''),
                ...array_map('strval', (array) ($payload['terms'] ?? [])),
            ]));

            return $this->renderPdf(
                title: 'ใบเสนอราคา',
                englishTitle: 'Quotation',
                documentNo: (string) ($document['number'] ?? ''),
                date: $date?->format('d-m-Y') ?? now()->format('d-m-Y'),
                customerName: (string) ($document['recipient'] ?? ''),
                customerCode: (string) ($customer['code'] ?? ''),
                customerAddress: implode("\n", $addressLines),
                customerTaxId: (string) ($customer['tax_id'] ?? '-'),
                salesperson: (string) ($document['salesperson'] ?? ''),
                department: '',
                note: $note ?: '-',
                total: $total,
                billDiscount: $billDiscount,
                items: $items,
                copy: ($payload['mode'] ?? 'original') === 'copy',
                canceled: false,
                htmlOnly: $htmlOnly,
                editorPayload: $payload,
                approvalEvidence: $approvalEvidence,
            );
        }

        return $this->renderHospitalQuotationPdf(
            template: (string) ($payload['template'] ?? QuotationTemplateCatalog::STANDARD),
            documentNo: (string) ($document['number'] ?? ''),
            date: $date?->format('d-m-Y') ?? now()->format('d-m-Y'),
            salesperson: (string) ($document['salesperson'] ?? ''),
            note: '-',
            total: $total,
            billDiscount: $billDiscount,
            items: $items,
            copy: ($payload['mode'] ?? 'original') === 'copy',
            rejected: false,
            approvalEvidence: $approvalEvidence,
            htmlOnly: $htmlOnly,
            overrides: $payload,
        );
    }

    public function confirmedQuotationVersionPdf(QuotationDocumentVersion $version, ?BillingRecord $bill = null): string
    {
        $original = base64_decode((string) $version->pdf_base64, true);
        abort_if($original === false || ! hash_equals((string) $version->pdf_sha256, hash('sha256', $original)), 500, 'ไฟล์ใบเสนอราคาที่จัดเก็บไม่ผ่านการตรวจสอบความถูกต้อง');
        if (! $bill || (int) $bill->quotation_document_version_id !== (int) $version->document_version_id) {
            return $original;
        }
        $signature = $bill->signatures()
            ->where('signature_type', 'internal_quotation_approval')
            ->where('revision_no', max(1, (int) $bill->revision_no))
            ->whereNull('revoked_at')
            ->latest('signed_at')->first();
        $signatureMatches = $signature
            && (int) $signature->qt_id === (int) $version->qt_id
            && (int) data_get($signature->payload_snapshot, 'quotation_document.document_version_id') === (int) $version->document_version_id
            && hash_equals((string) data_get($signature->payload_snapshot, 'quotation_document.pdf_sha256', ''), (string) $version->pdf_sha256);
        if (! $signatureMatches) {
            abort_if(in_array($bill->status, ['รอลูกค้ายืนยัน', 'รอแอดมินส่งออก', 'อนุมัติแล้ว'], true), 409, 'ใบเสนอราคานี้ยังไม่มีลายเซ็นผู้อนุมัติที่ตรงกับไฟล์ฉบับปัจจุบัน');

            return $original;
        }

        return $this->quotationEditorPdf((array) $version->payload, approvalEvidence: [
            'name' => $signature->signer_name ?: $signature->signer_username,
            'role' => $signature->signer_role,
            'signed_at' => $signature->signed_at?->timezone('Asia/Bangkok')->format('d-m-Y H:i:s'),
            'hash' => $signature->payload_sha256,
            'signature_image' => $signature->signature_image_base64,
            'signature_image_hash' => $signature->signature_image_sha256,
        ]);
    }

    /** @param array<string, mixed> $payload
     * @param  array<string, mixed>|null  $approvalEvidence
     */
    public function powerOfAttorneyPdf(array $payload, string $saleSignatureBase64, ?array $approvalEvidence = null, bool $htmlOnly = false): string
    {
        // หนังสือมอบอำนาจเป็น Draft เท่านั้น จึงแสดงพื้นที่ลงลายมือชื่อจริงโดยไม่ฝัง e-signature
        $saleSignature = '<span class="signature-placeholder" aria-hidden="true">&nbsp;</span>';
        $fontPath = realpath(resource_path('assets/fonts/THSarabunNew.ttf'));
        $fontUrl = $fontPath !== false ? $this->fileUrl($fontPath) : '';
        $logoPath = realpath(resource_path('assets/images/valorhealth.png'));
        $logoUrl = $logoPath !== false ? $this->fileUrl($logoPath) : '';
        $template = (new PowerOfAttorneyTemplateCatalog)->normalize(
            $payload['template_id'] ?? null,
            (string) ($payload['hospital_name'] ?? ''),
        );
        $documentBody = $this->powerOfAttorneyBody(
            $template,
            $payload,
            $logoUrl,
            $saleSignature,
        );
        $showFooter = $template !== PowerOfAttorneyTemplateCatalog::ANANDAMAHIDOL_EBIDDING;
        $html = '<!doctype html><html lang="th"><head><meta charset="utf-8"><style>
            @font-face{font-family:THSarabun;src:url("'.$fontUrl.'") format("truetype")}@page{size:A4;margin:13mm 17mm 14mm}
            *{box-sizing:border-box}body{font-family:THSarabun,sans-serif;font-size:15px;line-height:1.22;color:#111;margin:0;padding-bottom:14mm;overflow-wrap:break-word;word-break:normal}
            .poa-header{position:relative;min-height:24mm}.poa-header h1{text-align:center;font-size:27px;margin:7mm 0 0}.poa-logo{position:absolute;left:0;top:0;width:40mm;height:17mm;object-fit:contain}.doc-no{position:absolute;left:0;top:19mm}.date{text-align:right;margin:-2mm 0 3mm;line-height:1.3}
            p{margin:1.5mm 0;text-align:left;text-indent:10mm;white-space:pre-wrap}.no-indent{text-indent:0}.center{text-align:center;text-indent:0}.authority-options{width:78%;margin:4mm auto}.authority-options td{padding:1mm 3mm;border:0}.check-box{display:inline-block;width:12px;height:12px;margin-right:5px;border:1px solid #111;vertical-align:-1px;text-align:center;font:700 10px/10px Arial}.check-box.checked:after{content:"X"}
            .signatures{width:100%;margin-top:3mm;table-layout:fixed;border-collapse:collapse}.signatures tr{page-break-inside:avoid}.signatures td{width:50%;height:17mm;padding:1mm 3mm;border:0;text-align:center;vertical-align:middle}.signature{display:inline-block;width:27mm;height:11mm;object-fit:contain;vertical-align:middle;margin:-3mm 1mm}.signature-placeholder{display:inline-block;width:34mm;height:7mm;color:#777;border-bottom:1px dotted #777;font-size:10px;vertical-align:middle}.signature-name{margin-top:.5mm;min-height:3mm}.witness{min-height:12mm}.signature-stack{width:100%;margin:0}.signature-stack td{width:100%;height:14mm;padding:1mm}.proof{font-size:8px;color:#555;line-height:1.1;word-break:break-all;margin-top:1px}
            .stamp-duty{width:30mm;min-height:20mm;padding:4mm 2mm;border:1px solid transparent;text-align:center;color:#555}.stamp-duty--top{position:absolute;right:0;top:0}.stamp-row{white-space:nowrap}.stamp-row--top{position:absolute;right:0;top:0}.stamp-row span{display:inline-block;width:20mm;height:25mm;margin-left:2mm;padding:5mm 1mm;border:1px solid transparent;text-align:center;color:#555}.poa-photo{width:29mm;height:38mm;margin:2mm auto;padding-top:14mm;border:1px solid transparent;text-align:center;color:#777}.anand-copy{margin-top:8mm}.anand-copy p{margin:2.5mm 0}
            .poa-page{position:relative}.poa-page--plain .poa-logo{display:none}.poa-page--small-logo{font-size:14px;line-height:1.16}.poa-page--small-logo .poa-header{min-height:21mm}.poa-page--small-logo .poa-logo{left:50%;width:27mm;height:12mm;transform:translateX(-50%)}.poa-page--small-logo .poa-header h1{padding-top:10mm;margin-top:4mm}.poa-page--small-logo .poa-copy p{margin:1mm 0}.poa-page--stamps-header .poa-header h1{width:55%;margin-left:38mm;text-align:center}.poa-copy{margin:2mm 0}.poa-copy--wide{margin-inline:8mm}.signing-layout-table{width:100%;margin-top:2mm;border-collapse:collapse;page-break-inside:avoid}.signing-layout-table>tbody>tr>td{padding:0;border:0;vertical-align:top}.poa-signing-aside{width:39%;text-align:center}.poa-signing-main{width:61%}.poa-seal{width:34mm;height:34mm;margin:2mm auto;padding-top:13mm;border:1px solid transparent;border-radius:50%;color:#777;text-align:center}.poa-stamps{white-space:nowrap}.poa-stamps span{display:inline-block;width:20mm;height:27mm;margin:2mm 1mm 0;padding-top:9mm;border:1px solid transparent;color:#777;text-align:center}.poa-page--small-logo .poa-photo{width:25mm;height:31mm;margin:1mm auto;padding-top:11mm}.poa-page--small-logo .poa-seal{width:28mm;height:28mm;margin:1mm auto;padding-top:10mm}.poa-page--small-logo .poa-stamps span{width:18mm;height:22mm;margin:1mm .5mm 0;padding-top:7mm}.poa-page--small-logo .signature-stack td{height:11mm;padding:.5mm}.poa-options{width:68%;margin:4mm auto;border-collapse:collapse}.poa-options td{padding:1mm 4mm;border:0}.poa-source-note{margin-top:2mm;text-align:center;text-indent:0}.poa-bottom-assets{width:100%;margin-top:2mm;border-collapse:collapse;page-break-inside:avoid}.poa-bottom-assets td{padding:0;border:0;vertical-align:bottom}.poa-bottom-assets .poa-stamps{width:60%;text-align:center}.poa-bottom-assets .poa-seal-cell{width:40%;text-align:center}
            .poa-page--plain{font-size:14px;line-height:1.16}.poa-page--plain .signatures td{height:14mm}
            .poa-page--general .poa-header{min-height:31mm}.poa-page--general .poa-header h1{padding-top:10mm}.poa-page--general .date{margin-top:-4mm;margin-bottom:7mm}.poa-page--general .poa-copy{margin-top:7mm}.poa-page--general .signing-layout-table{margin-top:3mm}.poa-page--general .poa-signing-aside{padding-top:2mm}.poa-page--general .poa-seal{margin-bottom:7mm}
            .poa-page--anand .poa-header{min-height:19mm}.poa-page--anand .poa-header h1{padding-top:9mm}.poa-page--anand .date{margin:0 0 4mm}.poa-page--anand .poa-copy{margin-top:2mm}.poa-page--anand .signing-layout-table{margin-top:1mm}.poa-page--anand .poa-signing-aside{padding-top:4mm}.poa-certification{margin-top:4mm;text-align:center;page-break-inside:avoid}.poa-certification-title{margin-bottom:2mm}.poa-certification .signatures{width:70%;margin:0 auto}
            .poa-page--egp .poa-header{min-height:25mm}.poa-page--egp .date{margin:-2mm 0 7mm}.poa-page--egp .poa-copy{margin-top:4mm}.poa-page--egp .signatures{margin-top:7mm}.poa-page--egp .poa-bottom-assets{margin-top:6mm}.poa-page--egp .poa-stamps{width:65%}.poa-page--egp .poa-seal-cell{width:35%}
            .poa-page--grantee .poa-header{min-height:35mm}.poa-page--grantee .poa-header h1{padding-top:8mm}.poa-page--grantee .date{margin:-3mm 0 5mm}.poa-page--grantee .poa-options{margin-top:6mm;margin-bottom:6mm}.poa-page--grantee .signatures{margin-top:6mm}.poa-page--grantee .poa-seal{margin-top:4mm}
            .poa-page--ebidding{font-size:16px;line-height:1.25}.poa-page--ebidding .poa-header{min-height:34mm}.poa-page--ebidding .poa-header h1{padding-top:8mm}.poa-page--ebidding .doc-no{top:25mm}.poa-page--ebidding .date{margin:-3mm 0 5mm}.poa-page--ebidding .poa-options{margin-top:7mm;margin-bottom:7mm}.poa-page--ebidding .signatures{margin-top:9mm}.poa-page--ebidding .signatures td{height:20mm}
            .footer{position:fixed;right:0;bottom:0;left:0;padding-top:2mm;border-top:3px solid #c72d3a;text-align:center;font-size:10px;color:#555;line-height:1.22}.footer strong{color:#c72d3a;font-size:12px}
            [data-edit-path][contenteditable="true"]{border-radius:2px;cursor:text;white-space:pre-wrap;overflow-wrap:anywhere;transition:outline-color .15s,background .15s}[data-edit-path][contenteditable="true"]:hover{outline:1px dashed #60a5fa;background:#eff6ff}[data-edit-path][contenteditable="true"]:focus{outline:2px solid #2563eb;background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.14)}
        </style></head><body>'.$documentBody.($showFooter ? '
            <div class="footer">'.$this->powerOfAttorneyField('footer_text', (string) ($payload['footer_text'] ?? "บริษัท เวเลอร์ เฮลธ์ จำกัด 2/22 อาคารไอยรา ทาวเวอร์ ชั้น 10 ถนนจันทน์ แขวงทุ่งวัดดอน เขตสาทร กรุงเทพมหานคร 10120 โทร. 02-678-7500, 02-678-5150 แฟกซ์ 02-678-8400\nValor Health Co., Ltd. · email: valorhealth@valorhealth.co.th · เลขประจำตัวผู้เสียภาษี 0105558041179 สำนักงานใหญ่"), (bool) ($payload['_editor'] ?? false)).'</div>' : '').'
        </body></html>';

        return $htmlOnly ? $html : $this->dompdfOutput($html);
    }

    /** @param array<string, mixed> $payload */
    public function powerOfAttorneyEditorHtml(array $payload, string $saleSignatureBase64): string
    {
        $payload['_editor'] = true;
        $html = $this->powerOfAttorneyPdf($payload, $saleSignatureBase64, htmlOnly: true);
        $assets = [
            resource_path('assets/fonts/THSarabunNew.ttf') => 'font/ttf',
            resource_path('assets/images/valorhealth.png') => 'image/png',
        ];
        foreach ($assets as $path => $mime) {
            if (is_file($path)) {
                $html = str_replace($this->fileUrl($path), 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path)), $html);
            }
        }

        return $html;
    }

    /** @param array<string, mixed> $payload */
    private function powerOfAttorneyBody(
        string $template,
        array $payload,
        string $logoUrl,
        string $saleSignature,
    ): string {
        $editor = (bool) ($payload['_editor'] ?? false);
        $saleName = $this->powerOfAttorneyField('grantee_name', (string) ($payload['grantee_name'] ?? $payload['sale_name'] ?? '-'), $editor);
        $hospital = $this->powerOfAttorneyField('organization', (string) ($payload['organization'] ?? $payload['hospital_name'] ?? '-'), $editor);
        $project = $this->powerOfAttorneyField('project', (string) ($payload['project'] ?? collect((array) ($payload['items'] ?? []))->pluck('description')->filter()->take(3)->implode(', ')), $editor);
        $quotationNo = (string) ($payload['quotation_no'] ?? '-');
        $issueDate = e((string) ($payload['issue_date_long_th'] ?? $payload['issue_date_th'] ?? '-'));
        $authorizationNo = $this->powerOfAttorneyField('authorization_no', (string) ($payload['authorization_no'] ?? '-'), $editor);
        $place = $this->powerOfAttorneyField('place', (string) ($payload['place'] ?? 'บริษัท เวเลอร์ เฮลธ์ จำกัด สำนักงานใหญ่'), $editor);
        $companyName = $this->powerOfAttorneyField('company_name', (string) ($payload['company_name'] ?? 'บริษัท เวเลอร์ เฮลธ์ จำกัด สำนักงานใหญ่'), $editor);
        $companyAddress = $this->powerOfAttorneyField('company_address', (string) ($payload['company_address'] ?? '2/22 อาคารไอยรา ทาวเวอร์ ชั้น 10 ถนนจันทน์ แขวงทุ่งวัดดอน เขตสาทร กรุงเทพมหานคร 10120'), $editor);
        $grantorText = $this->powerOfAttorneyField('grantor_text', (string) ($payload['grantor_text'] ?? 'ผู้มีอำนาจลงนามผูกพันบริษัทฯ'), $editor);
        $grantorOne = $this->powerOfAttorneyField('grantor_one_name', (string) ($payload['grantor_one_name'] ?? 'นายประเสริฐ ไวยาวัจมัย'), $editor);
        $grantorTwo = $this->powerOfAttorneyField('grantor_two_name', (string) ($payload['grantor_two_name'] ?? 'นายกฤต เติมพิริยะ'), $editor);
        $granteeJob = $this->powerOfAttorneyField('grantee_job_title', (string) ($payload['grantee_job_title'] ?? 'ผู้แทนฝ่ายขาย'), $editor);
        $granteeAddress = $this->powerOfAttorneyField('grantee_address', (string) ($payload['grantee_address'] ?? 'กรอกที่อยู่ผู้รับมอบอำนาจ'), $editor);
        $identity = preg_replace('/[^0-9]/', '', (string) ($payload['sale_identity_no'] ?? '')) ?? '';
        $identityText = $this->powerOfAttorneyField('sale_identity_no', $identity !== '' ? $identity : 'กรอกเลขบัตรประชาชน 13 หลัก', $editor);
        $identityIssuedAtValue = trim((string) ($payload['national_id_issued_at'] ?? ''));
        $identityExpiresAtValue = trim((string) ($payload['national_id_expires_at'] ?? ''));
        $identityDates = '';
        if ($identityIssuedAtValue !== '' || $identityExpiresAtValue !== '') {
            $issuedAt = $this->powerOfAttorneyField('national_id_issued_at', $identityIssuedAtValue !== '' ? $identityIssuedAtValue : '-', $editor);
            $expiresAt = $this->powerOfAttorneyField('national_id_expires_at', $identityExpiresAtValue !== '' ? $identityExpiresAtValue : '-', $editor);
            $identityDates = ' ออกบัตรวันที่ '.$issuedAt.' หมดอายุวันที่ '.$expiresAt;
        }
        $note = trim((string) ($payload['authority_note'] ?? ''));
        $noteHtml = '<p>'.$this->powerOfAttorneyField('authority_note', $note !== '' ? $note : 'ข้อความขอบเขตอำนาจเพิ่มเติม', $editor).'</p>';
        $acceptance = $this->powerOfAttorneyField('acceptance_text', (string) ($payload['acceptance_text'] ?? 'การใดที่ผู้รับมอบอำนาจได้กระทำภายในขอบเขตแห่งหนังสือมอบอำนาจฉบับนี้ บริษัทฯ ขอรับผิดชอบเสมือนหนึ่งบริษัทฯ เป็นผู้กระทำเองทุกประการ'), $editor);
        $witnessOne = $this->powerOfAttorneyField('witness_one_name', trim((string) ($payload['witness_one_name'] ?? '')) ?: 'กรอกชื่อพยานคนที่ 1', $editor);
        $witnessTwo = $this->powerOfAttorneyField('witness_two_name', trim((string) ($payload['witness_two_name'] ?? '')) ?: 'กรอกชื่อพยานคนที่ 2', $editor);
        $stampText = $this->powerOfAttorneyField('stamp_text', (string) ($payload['stamp_text'] ?? 'อากรแสตมป์ตามอัตราที่กฎหมายกำหนด'), $editor);
        $introDefaults = [
            PowerOfAttorneyTemplateCatalog::EGP => 'โดยหนังสือฉบับนี้ บริษัทฯ โดยผู้มีอำนาจลงนามทั้งสองคน ขอมอบอำนาจให้ผู้รับมอบอำนาจซึ่งมีรายละเอียดตามที่ระบุข้างต้น เป็นผู้ดำเนินการแทนบริษัทฯ',
            PowerOfAttorneyTemplateCatalog::ANANDAMAHIDOL_EBIDDING => 'โดยหนังสือฉบับนี้ บริษัทฯ โดยผู้มีอำนาจลงนามทั้งสองคน ขอมอบอำนาจให้ผู้รับมอบอำนาจกระทำการแทนบริษัทฯ ตามขอบเขตที่ระบุในหนังสือฉบับนี้',
        ];
        $scopeDefaults = [
            PowerOfAttorneyTemplateCatalog::EGP => 'มีอำนาจลงทะเบียนผู้ปฏิบัติงานของผู้ค้ากับภาครัฐ และเสนอราคาผ่านระบบจัดซื้อจัดจ้างภาครัฐด้วยอิเล็กทรอนิกส์ (e-GP) รวมทั้งลงนาม ยื่น รับ แก้ไข และรับรองเอกสารที่เกี่ยวข้องจนเสร็จการ',
            PowerOfAttorneyTemplateCatalog::ANANDAMAHIDOL => 'มีอำนาจเจรจาต่อรองราคา ยื่นข้อเสนอ ลงนาม แก้ไข ตก เติมในเอกสารสัญญา และดำเนินการอื่นใดที่เกี่ยวข้องกับการเสนอราคาจนเสร็จการ',
        ];
        $intro = $this->powerOfAttorneyField('body_intro', trim((string) ($payload['body_intro'] ?? '')) ?: ($introDefaults[$template] ?? 'โดยหนังสือฉบับนี้ บริษัทฯ โดยผู้มีอำนาจลงนามทั้งสองคน ขอมอบอำนาจให้ผู้รับมอบอำนาจกระทำการแทนบริษัทฯ'), $editor);
        $scope = $this->powerOfAttorneyField('body_scope', trim((string) ($payload['body_scope'] ?? '')) ?: ($scopeDefaults[$template] ?? 'มีอำนาจยื่นเสนอราคา ต่อรองราคา ลงนามในใบเสนอราคา แก้ไขหรือเพิ่มเติม ลงนามในเอกสาร ติดต่อประสานงาน รับและส่งเอกสาร ตลอดจนดำเนินการอื่นใดที่เกี่ยวข้องจนเสร็จการ'), $editor);
        $closing = $this->powerOfAttorneyField('body_closing', trim((string) ($payload['body_closing'] ?? '')) ?: 'เพื่อเป็นหลักฐานแห่งการนี้ จึงได้ลงลายมือชื่อพร้อมทั้งประทับตรา (ถ้ามี) ไว้ต่อหน้าพยานเป็นสำคัญ', $editor);
        $signedLine = static function (string $fallback, string $role): string {
            return 'ลงชื่อ <span class="signature-placeholder" aria-hidden="true">&nbsp;</span> '.$role.'<div class="signature-name">('.$fallback.')</div>';
        };
        $grantorOneLine = $signedLine($grantorOne, 'ผู้มอบอำนาจ');
        $grantorTwoLine = $signedLine($grantorTwo, 'ผู้มอบอำนาจ');
        $granteeLine = 'ลงชื่อ '.$saleSignature.' ผู้รับมอบอำนาจ<div class="signature-name">('.$saleName.') · '.$granteeJob.'</div>';
        $witnessOneLine = $signedLine($witnessOne, 'พยาน');
        $witnessTwoLine = $signedLine($witnessTwo, 'พยาน');
        $signatureGrid = '<table class="signatures"><tr><td>'.$grantorOneLine.'</td><td>'.$grantorTwoLine.'</td></tr><tr><td colspan="2">'.$granteeLine.'</td></tr><tr><td class="witness">'.$witnessOneLine.'</td><td class="witness">'.$witnessTwoLine.'</td></tr></table>';
        $signatureStack = '<table class="signatures signature-stack"><tr><td>'.$grantorOneLine.'</td></tr><tr><td>'.$grantorTwoLine.'</td></tr><tr><td>'.$granteeLine.'</td></tr><tr><td>'.$witnessOneLine.'</td></tr><tr><td>'.$witnessTwoLine.'</td></tr></table>';
        $check = static fn (bool $checked): string => '<span class="check-box'.($checked ? ' checked' : '').'"></span>';
        $options = '<table class="poa-options"><tr><td>'.$check((bool) ($payload['authority_quote'] ?? true)).'ลงนามในใบเสนอราคา</td><td>'.$check((bool) ($payload['authority_documents'] ?? true)).'ลงนามในเอกสาร</td></tr><tr><td>'.$check((bool) ($payload['authority_negotiate'] ?? true)).'ต่อรองราคา</td><td>'.$check((bool) ($payload['authority_modify'] ?? true)).'แก้ไขข้อความในเอกสาร</td></tr><tr><td>'.$check((bool) ($payload['authority_contract'] ?? false)).'ลงนามในสัญญา</td><td>'.$check((bool) ($payload['authority_order'] ?? false)).'ลงนามในใบสั่ง</td></tr></table>';
        $companyDetails = '<p class="no-indent">'.$companyName.' สำนักงานตั้งอยู่เลขที่ '.$companyAddress.' โดยผู้มีอำนาจลงนามผูกพันบริษัทฯ ได้แก่ '.$grantorOne.' และ '.$grantorTwo.'</p>';
        $granteeDetails = '<p class="no-indent">ขอมอบอำนาจให้ '.$saleName.' ตำแหน่ง '.$granteeJob.' เลขบัตรประชาชน '.$identityText.$identityDates.' ที่อยู่ '.$granteeAddress.' เป็นผู้รับมอบอำนาจ</p>';
        $projectDetails = '<p class="no-indent">รายการ/โครงการ '.$project.' กับ '.$hospital.' อ้างอิงใบเสนอราคาเลขที่ '.e($quotationNo).'</p>';
        $copy = '<div class="poa-copy"><p>'.$intro.'</p>'.$companyDetails.$granteeDetails.'<p>'.$scope.'</p>'.$projectDetails.$noteHtml.'<p>'.$acceptance.'</p><p>'.$closing.'</p></div>';
        $logo = '<img class="poa-logo" src="'.$logoUrl.'" alt="Valor Health">';

        if ($template === PowerOfAttorneyTemplateCatalog::ANANDAMAHIDOL_EBIDDING) {
            return '<div class="poa-page poa-page--plain poa-page--ebidding"><div class="poa-header"><div class="doc-no">ที่ '.$authorizationNo.'</div><h1>หนังสือมอบอำนาจ</h1><div class="stamp-duty stamp-duty--top">อากรแสตมป์<br>30 บาท / 10 บาท</div></div><div class="date">ทำที่ '.$place.'<br>วันที่ '.$issueDate.'</div>'.$companyDetails.$granteeDetails.$options.$projectDetails.'<p>'.$acceptance.'</p><p>'.$closing.'</p>'.$signatureGrid.'</div>';
        }
        if ($template === PowerOfAttorneyTemplateCatalog::GRANTEE_FORM) {
            return '<div class="poa-page poa-page--stamps-header poa-page--grantee"><div class="poa-header">'.$logo.'<div class="doc-no">ที่ '.$authorizationNo.'</div><h1>หนังสือมอบอำนาจ</h1><div class="stamp-row stamp-row--top"><span>อากรแสตมป์<br>20 บาท</span><span>อากรแสตมป์<br>5 บาท</span><span>อากรแสตมป์<br>5 บาท</span></div></div><div class="date">ทำที่ '.$place.'<br>วันที่ '.$issueDate.'</div>'.$companyDetails.$granteeDetails.$options.$projectDetails.'<p>'.$acceptance.'</p><p>'.$closing.'</p>'.$signatureGrid.'<div class="poa-seal" style="margin-left:auto;margin-right:11%">ตราประทับบริษัท</div><div class="poa-source-note">'.$stampText.'</div></div>';
        }
        if ($template === PowerOfAttorneyTemplateCatalog::GENERAL) {
            return '<div class="poa-page poa-page--general"><div class="poa-header">'.$logo.'<h1>หนังสือมอบอำนาจ</h1></div><div class="date">เขียนที่ '.$place.'<br>วันที่ '.$issueDate.'</div>'.$copy.'<table class="signing-layout-table"><tr><td class="poa-signing-aside"><div class="poa-seal">ตราประทับบริษัท</div><div class="poa-stamps"><span>อากรแสตมป์<br>20 บาท</span><span>5 บาท</span><span>5 บาท</span></div></td><td class="poa-signing-main">'.$signatureStack.'</td></tr></table></div>';
        }
        if ($template === PowerOfAttorneyTemplateCatalog::ANANDAMAHIDOL) {
            $certification = '<div class="poa-certification"><div class="poa-certification-title">ขอรับรองว่าเป็นภาพถ่ายและลายมือชื่อของผู้รับมอบอำนาจจริง</div><table class="signatures"><tr><td>'.$grantorOneLine.'</td><td>'.$grantorTwoLine.'</td></tr></table></div>';
            return '<div class="poa-page poa-page--small-logo poa-page--anand"><div class="poa-header">'.$logo.'<h1>หนังสือมอบอำนาจ</h1></div><div class="date">วันที่ '.$issueDate.'</div>'.$copy.'<table class="signing-layout-table"><tr><td class="poa-signing-aside"><div class="poa-photo">รูปถ่ายผู้รับมอบอำนาจ</div><div class="poa-seal">ตราประทับบริษัท</div><div class="poa-stamps"><span>5 บาท</span><span>5 บาท</span><span>20 บาท</span></div></td><td class="poa-signing-main">'.$signatureStack.'</td></tr></table>'.$certification.'</div>';
        }

        return '<div class="poa-page poa-page--egp"><div class="poa-header">'.$logo.'<h1>หนังสือมอบอำนาจ</h1></div><div class="date">วันที่ '.$issueDate.'</div><div class="poa-copy"><p>'.$intro.'</p>'.$companyDetails.$granteeDetails.'<p>'.$scope.'</p><p>'.$acceptance.'</p><p>'.$closing.'</p></div>'.$signatureGrid.'<table class="poa-bottom-assets"><tr><td class="poa-stamps"><span>อากรแสตมป์<br>20 บาท</span><span>5 บาท</span><span>5 บาท</span></td><td class="poa-seal-cell"><div class="poa-seal">ตราประทับบริษัท</div></td></tr></table></div>';
    }

    private function powerOfAttorneyField(string $key, string $value, bool $editor): string
    {
        return $editor
            ? '<span data-edit-path="power_of_attorney.'.e($key).'" contenteditable="true" spellcheck="false">'.e($value).'</span>'
            : e($value);
    }

    /** @param array<string, mixed> $payload */
    public function quotationEditorHtml(array $payload): string
    {
        $payload['_editor'] = true;
        $html = $this->quotationEditorPdf($payload, true);
        $assets = [
            resource_path('assets/fonts/THSarabunNew.ttf') => 'font/ttf',
            resource_path('assets/images/valorhealth.png') => 'image/png',
        ];
        foreach ($assets as $path => $mime) {
            if (is_file($path)) {
                $html = str_replace($this->fileUrl($path), 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path)), $html);
            }
        }

        return $html;
    }

    /** @param Collection<int, BillingRecord> $bills */
    public function quotationBatchPdf(Collection $bills, bool $htmlOnly = false): string
    {
        $pages = [];
        $styles = '';
        foreach ($bills as $bill) {
            $quotation = $bill->quotation;
            if (! $quotation) {
                throw new RuntimeException("Bill #{$bill->bill_id} ไม่มีใบเสนอราคาอ้างอิง");
            }
            $template = (string) ($bill->quotationDocumentVersion?->template_id ?? '');
            if ($template === '') {
                throw new RuntimeException("Bill #{$bill->bill_id} ยังไม่มี Template ใบเสนอราคาที่ Sale ยืนยัน");
            }
            app(QuotationTemplateCatalog::class)->requireAvailable($template);
            $html = $this->quotationPdf($quotation, draft: $bill, htmlOnly: true, template: $template);
            if (preg_match('/<style>(.*?)<\/style>/s', $html, $styleMatch) === 1) {
                $styles .= $styleMatch[1];
            }
            if (preg_match('/<body>(.*?)<\/body>/s', $html, $bodyMatch) !== 1) {
                throw new RuntimeException("ไม่สามารถรวมใบเสนอราคาของ Bill #{$bill->bill_id} ได้");
            }
            $pages[] = '<section class="batch-document">'.$bodyMatch[1].'</section>';
        }

        $html = '<!doctype html><html lang="th"><head><meta charset="utf-8"><style>'
            .$styles.'.batch-document{page-break-after:always}.batch-document:last-child{page-break-after:auto}'
            .'</style></head><body>'.implode('', $pages).'</body></html>';

        return $htmlOnly ? $html : $this->dompdfOutput($html);
    }

    private function quotationNote(object $source): string
    {
        $details = array_filter([
            trim((string) ($source->bill_note ?? '')),
            $source->customer_po_no ? 'PO: '.$source->customer_po_no : null,
            $source->customer_confirmed_at ? 'ยืนยันเมื่อ: '.(string) $source->customer_confirmed_at : null,
            $source->customer_contact_name ? 'ผู้ติดต่อ: '.$source->customer_contact_name.($source->customer_contact_channel ? ' ('.$source->customer_contact_channel.')' : '') : null,
            $source->quotation_valid_until ? 'ใบเสนอราคาหมดอายุ: '.(string) $source->quotation_valid_until : null,
            $source->payment_terms ? 'ชำระเงิน: '.$source->payment_terms : null,
            $source->delivery_due_date ? 'กำหนดส่ง: '.(string) $source->delivery_due_date : null,
            $source->delivery_terms ? 'เงื่อนไขส่ง: '.$source->delivery_terms : null,
        ]);

        return $details === [] ? '-' : implode(' | ', $details);
    }

    /** @param Collection<int, object> $items */
    private function renderHospitalQuotationPdf(
        string $template,
        string $documentNo,
        string $date,
        string $salesperson,
        string $note,
        float $total,
        float $billDiscount,
        Collection $items,
        bool $copy,
        bool $rejected,
        ?array $approvalEvidence,
        bool $htmlOnly,
        array $overrides = [],
    ): string {
        app(QuotationTemplateCatalog::class)->profile($template);
        $fontPath = realpath(resource_path('assets/fonts/THSarabunNew.ttf'));
        $logoPath = realpath(resource_path('assets/images/valorhealth.png'));
        $fontUrl = $fontPath !== false ? $this->fileUrl($fontPath) : '';
        $logoUrl = $logoPath !== false ? $this->fileUrl($logoPath) : '';
        $fontFamily = $fontUrl !== '' ? 'THSarabun,DejaVu Sans,sans-serif' : 'DejaVu Sans,sans-serif';
        $lineDiscount = 0.0;
        $gross = 0.0;
        $itemRows = [];
        foreach ($items->values() as $index => $item) {
            $quantity = (float) ($item->quantity ?? 0);
            $unitPrice = (float) ($item->unit_price ?? 0);
            $before = (float) ($item->line_total_before_discount ?? ($unitPrice * $quantity));
            $lineTotal = (float) ($item->line_total ?? $before);
            $gross += $before;
            $lineDiscount += max($before - $lineTotal, (float) ($item->sale_discount_unit ?? 0), 0);
            $unit = trim((string) ($item->unit_type ?? '')) ?: 'ชิ้น';
            $originDetails = trim((string) ($item->document_origin_details ?? ''));
            if ($originDetails === '') {
                $originDetails = app(QuotationTemplateCatalog::class)->exampleOriginDetails($template, (string) ($item->main_category ?? ''));
            }
            $itemRows[] = [
                'sequence' => $index + 1,
                'sku' => trim((string) ($item->main_category ?? '')),
                'description' => trim((string) ($item->sub_category ?? '')),
                'quantity' => $this->quotationQuantity($quantity),
                'unit' => $unit,
                'pack' => trim((string) ($item->document_pack ?? '')) ?: '1 '.$unit,
                'unit_price' => number_format($unitPrice, 2),
                'amount' => number_format($lineTotal, 2),
                'remark' => trim((string) ($item->document_remark ?? '')),
                'origin_details' => $originDetails,
            ];
        }

        $discount = $lineDiscount + max($billDiscount, 0);
        $subtotal = $total / 1.07;
        $vat = $total - $subtotal;
        $signatureImage = ! empty($approvalEvidence['signature_image'])
            ? '<img class="signature-image" src="data:image/png;base64,'.e((string) $approvalEvidence['signature_image']).'" alt="ลายเซ็นผู้อนุมัติ">'
            : '<span class="signature-space"></span>';
        $context = [
            'logoUrl' => $logoUrl,
            'documentNo' => $documentNo,
            'date' => $this->thaiLongDate($date),
            'salesperson' => $salesperson ?: 'ผู้แทนฝ่ายขาย',
            'note' => $note,
            'total' => $total,
            'subtotal' => $subtotal,
            'vat' => $vat,
            'gross' => $gross > 0 ? $gross : $total + $discount,
            'discount' => $discount,
            'items' => $itemRows,
            'signature' => $signatureImage,
            'approval' => $approvalEvidence,
            'overrides' => $overrides,
        ];
        $body = match ($template) {
            QuotationTemplateCatalog::SIRIRAJ => $this->sirirajQuotationTemplate($context),
            QuotationTemplateCatalog::LUANGPHO_THAWEESAK => $this->luangphoThaweesakQuotationTemplate($context),
            QuotationTemplateCatalog::POLICE_GENERAL => $this->policeGeneralQuotationTemplate($context),
            QuotationTemplateCatalog::CHAROENKRUNG_PRACHARAK => $this->charoenkrungPracharakQuotationTemplate($context),
            QuotationTemplateCatalog::RAMATHIBODI => $this->ramathibodiQuotationTemplate($context),
            QuotationTemplateCatalog::SIRIRAJ_PIYAMAHARAJKARUN => $this->sirirajPiyamaharajkarunQuotationTemplate($context),
        };

        $watermark = $rejected ? 'ไม่อนุมัติ' : ($copy ? 'สำเนา (COPY)' : '');
        $html = '<!doctype html><html lang="th"><head><meta charset="utf-8"><style>
            @page{size:A4 portrait;margin:26px 58px 76px}
            *{box-sizing:border-box}body{font-family:'.$fontFamily.';font-size:14px;line-height:1.28;color:#111;margin:0}
            .document-header{height:173px;position:relative}.logo{position:absolute;left:18px;top:6px;width:145px;height:auto}.document-title{position:absolute;left:33%;right:33%;top:107px;text-align:center;font-size:24px;font-weight:bold}.document-meta{position:absolute;right:6px;top:135px;text-align:left;line-height:1.6}.document-meta.no-date{top:143px}
            .letter{margin:7px 20px 10px;line-height:1.65}.letter-row{margin:0}.label{display:inline-block;width:42px}.intro{margin:10px 20px 8px;text-align:left}.intro.center{text-align:center}
            table{border-collapse:collapse}.items{width:100%;table-layout:fixed}.items thead{display:table-header-group}.items tr{page-break-inside:avoid}.items th,.items td{border:1px solid #111;padding:4px 5px;vertical-align:top}.items th{text-align:center;font-weight:normal;line-height:1.15}.items td.center{text-align:center}.items td.number{text-align:right;white-space:nowrap}.items .description{line-height:1.28}.items .tall td{height:134px}.items .empty-row{text-align:center;height:58px;vertical-align:middle}
            .summary{margin-left:auto;border-collapse:collapse}.summary td{border:1px solid #111;padding:3px 6px}.summary .summary-label{text-align:right}.summary .number{text-align:right;white-space:nowrap}.summary .shade{background:#c9c9c9;text-align:left}.amount-words{text-align:center;font-weight:bold;margin:8px 0}.amount-words.left{text-align:left;font-weight:normal}.terms{margin:7px 20px 0;page-break-inside:avoid}.terms-title{font-weight:bold}.terms-line{margin:4px 0}.terms .numbered{padding-left:43px;text-indent:-18px}
            .signature{width:42%;margin:18px 5% 0 auto;text-align:center;page-break-inside:avoid}.signature td{width:100%;padding:0;border:0;text-align:center}.signature-space{display:block;height:45px}.signature-image{display:block;max-width:155px;max-height:48px;margin:4px auto}.acknowledgement{border:1px solid #111;height:78px;padding:45px 9px 6px;text-align:center}.closing-grid{width:86%;margin:24px auto 0;page-break-inside:avoid}.closing-grid td{width:50%;vertical-align:top}.closing-grid .signature{width:100%;margin:0;text-align:center}
            .contact{text-align:center;margin-top:28px;font-size:12px}.footer{position:fixed;bottom:-62px;left:28px;right:28px;height:47px;text-align:center;font-size:9px;color:#444;line-height:1.3}.footer-bar{height:8px;background:#d71920;margin-bottom:4px}.footer-bar span{display:inline-block;height:8px;width:18%;background:#92241f;margin-right:10%}.footer strong{color:#e11;font-size:12px}.footer .company-en{color:#e11;font-weight:bold;font-size:11px}.editor-mode .footer{position:static;height:auto;margin:24px 28px 0}
            .luangpho{font-size:14px}.luangpho .items .tall td{height:122px}.luangpho .terms{font-size:13px;margin-top:5px}.luangpho .signature{margin-top:4px!important}.luangpho .signature-space{height:24px}.luangpho .contact{margin-top:0}.police-general{font-size:14px}.police-general .letter{margin-top:45px}.charoenkrung{font-size:14px}.charoenkrung .letter{margin-top:18px}.charoenkrung .signature{margin-top:5px!important}.charoenkrung .signature-space{height:24px}.charoenkrung .contact{margin-top:2px}.compact{font-size:12.5px}.compact .items th,.compact .items td{padding:2px 4px}.ramathibodi .items{margin-top:24px}.ramathibodi .terms{margin-top:10px}.siriraj-annual{font-size:14px}.siriraj-annual .document-header{height:72px}.siriraj-annual .document-title{left:55%;right:0;top:12px}.siriraj-annual .letter{margin-top:0;margin-left:6%}.siriraj-annual .intro{margin-top:28px}.siriraj-annual .items td{height:auto;padding-top:5px;padding-bottom:5px}.siriraj-summary{width:58%;margin:16px 7% 0 auto}.siriraj-summary td{border:0;padding:3px 6px}.siriraj-summary .number{text-align:right;white-space:nowrap}.police-price-terms{vertical-align:top!important;line-height:1.65;padding:4px 2px!important}.police-price-terms div{margin-bottom:3px}.police-long-condition{margin:22px 3% 0;line-height:1.65}.charoen-summary-label{display:table;width:100%}.charoen-summary-label span{display:table-cell}.charoen-summary-label .value{text-align:right;white-space:nowrap}.siriraj-legal .document-meta{display:none}.siriraj-legal .items{margin:6px auto;width:91%}.siriraj-legal .closing{margin:48px 8% 0}.siriraj-legal .signature{margin-top:8px}.police-general .signature{margin-top:18px}.police-general .contact{margin-top:18px}.piyamaharajkarun .items{font-size:12.5px}.piyamaharajkarun .items th,.piyamaharajkarun .items td{padding:2px 4px}.piyamaharajkarun-price-condition{margin-left:15%;line-height:1.55}
            .origin-details{margin-top:18px;white-space:pre-line;line-height:1.28}.items td.shade{background:#c9c9c9}.items .summary-row td{padding-top:1px;padding-bottom:1px}.watermark{position:fixed;top:43%;left:14%;font-size:84px;color:rgba(150,0,0,.14);transform:rotate(-35deg);z-index:-1}
            [data-edit-path][contenteditable="true"]{border-radius:2px;cursor:text;transition:outline-color .15s,background .15s}[data-edit-path][contenteditable="true"]:hover{outline:1px dashed #60a5fa;background:#eff6ff}[data-edit-path][contenteditable="true"]:focus{outline:2px solid #2563eb;background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.14)}
        </style></head><body'.(data_get($context, 'overrides._editor', false) ? ' class="editor-mode"' : '').'>'.($watermark !== '' ? '<div class="watermark">'.e($watermark).'</div>' : '').$body
            .($template === QuotationTemplateCatalog::SIRIRAJ ? '' : $this->hospitalQuotationFooter($template)).'</body></html>';

        return $htmlOnly ? $html : $this->dompdfOutput($html);
    }

    private function quotationQuantity(float $quantity): string
    {
        return number_format($quantity, $quantity === floor($quantity) ? 0 : 2);
    }

    /** @param array<string, mixed> $context */
    private function sirirajQuotationTemplate(array $context): string
    {
        $headers = [
            ['label' => 'ลำดับ', 'width' => 8, 'key' => 'sequence', 'class' => 'center'],
            ['label' => 'รหัสเวชภัณฑ์', 'width' => 14, 'key' => 'sku', 'class' => 'center'],
            ['label' => 'ชื่อเวชภัณฑ์', 'width' => 34, 'key' => 'description', 'class' => 'description'],
            ['label' => 'จำนวนและขนาดบรรจุ', 'width' => 18, 'key' => 'pack', 'class' => 'center'],
            ['label' => 'ราคาต่อหน่วย / บาท รวมภาษี', 'width' => 26, 'key' => 'unit_price', 'class' => 'number'],
        ];

        $items = array_map(static function (array $item): array {
            $item['origin_details'] = '';

            return $item;
        }, $context['items']);
        $defaultTerms = [
            'กำหนดยืนราคา ถึง 30 กันยายน 2570',
            'กำหนดส่งของ ภายใน 5 วัน หรือตามที่คณะฯ กำหนด หลังจากได้รับใบสั่งซื้อ ตามสัญญาจะซื้อจะขาย แบบราคาคงที่ไม่จำกัดปริมาณ',
            'เวชภัณฑ์ดังกล่าว เป็นผลิตภัณฑ์จากประเทศ China',
            'ซึ่งบริษัท เวเลอร์ เฮลธ์ จำกัด เป็นผู้แทนจำหน่ายแต่เพียงผู้เดียวในประเทศไทย',
        ];
        $terms = $this->resolvedHospitalTerms($context, $defaultTerms);
        $termsHtml = '<div class="terms" style="margin-left:16%">';
        foreach ($terms as $index => $term) {
            $termsHtml .= '<div class="terms-line">'.$this->editableField('terms.'.$index, (string) $term, $context).'</div>';
        }
        $termsHtml .= '</div>';

        return '<main class="siriraj-annual">'.$this->hospitalQuotationHeader($context, false, false)
            .'<div class="letter"><div class="letter-row"><span class="label">เรื่อง</span> '.$this->hospitalField($context, 'subject', 'ใบเสนอราคาเวชภัณฑ์').'</div>'
            .'<div class="letter-row"><span class="label">เรียน</span> '.$this->hospitalField($context, 'recipient', 'คณบดี คณะแพทยศาสตร์ศิริราชพยาบาล').'</div></div>'
            .'<div class="intro center">'.$this->hospitalField($context, 'intro', 'บริษัท เวเลอร์ เฮลธ์ จำกัด มีความยินดีขอเสนอ ราคาเวชภัณฑ์ ดังต่อไปนี้').'</div>'
            .$this->hospitalItemsTable($headers, $items, false, $context)
            .'<table class="siriraj-summary"><tr><td>ราคาสินค้าก่อนรวมภาษีมูลค่าเพิ่ม</td><td class="number">'.number_format($context['subtotal'], 2).'</td></tr>'
            .'<tr><td>ภาษีมูลค่าเพิ่ม 7%</td><td class="number">'.number_format($context['vat'], 2).'</td></tr>'
            .'<tr><td>ยอดรวมสุทธิ</td><td class="number">'.number_format($context['total'], 2).'</td></tr></table>'
            .'<div class="amount-words left" style="margin-left:23%">ตัวอักษร&nbsp;&nbsp;('.e($this->bahtText->convert($context['total'])).')</div>'
            .$termsHtml
            .$this->hospitalSignature($context, 'ผู้เสนอราคา').'</main>';
    }

    /** @param array<string, mixed> $context */
    private function luangphoThaweesakQuotationTemplate(array $context): string
    {
        $headers = [
            ['label' => '<strong>รหัสสินค้า</strong><br>Pcode', 'width' => 11, 'key' => 'sku', 'class' => 'center'],
            ['label' => '<strong>รายการ</strong><br>Description', 'width' => 34, 'key' => 'description', 'class' => 'description'],
            ['label' => '<strong>หน่วย</strong><br>UOM', 'width' => 8, 'key' => 'unit', 'class' => 'center'],
            ['label' => '<strong>จำนวน</strong><br>Quantity', 'width' => 18, 'key' => 'quantity', 'class' => 'number'],
            ['label' => '<strong>ราคาต่อหน่วย</strong><br>Unit Price', 'width' => 16, 'key' => 'unit_price', 'class' => 'number'],
            ['label' => '<strong>จำนวนเงิน</strong><br>Amount', 'width' => 13, 'key' => 'amount', 'class' => 'number'],
        ];
        $items = $context['items'];
        $table = $this->hospitalItemsTable($headers, $items, true, $context);
        $summaryRows = [
            'รวมเป็นเงิน / Gross Amount' => $context['gross'],
            'ส่วนลด / Discount' => $context['discount'],
            'จำนวนเงินหลังหักส่วนลด / Net Amount' => $context['subtotal'],
            'จำนวนภาษีมูลค่าเพิ่ม / Value Added Tax 7%' => $context['vat'],
            'จำนวนเงินรวมทั้งสิ้น (บาท) / Total Amount' => $context['total'],
        ];
        $summary = '';
        foreach ($summaryRows as $index => $value) {
            $summary .= '<tr class="summary-row"><td colspan="3"'.($index === 'จำนวนเงินรวมทั้งสิ้น (บาท) / Total Amount' ? ' style="text-align:center"' : '').'>'
                .($index === 'รวมเป็นเงิน / Gross Amount' ? 'หมายเหตุ<br>:' : ($index === 'จำนวนเงินรวมทั้งสิ้น (บาท) / Total Amount' ? e($this->bahtText->convert($context['total'])) : '&nbsp;'))
                .'</td><td colspan="2" class="shade">'.e($index).'</td><td class="number">'.number_format((float) $value, 2).'</td></tr>';
        }
        $table = str_replace('</tbody></table>', $summary.'</tbody></table>', $table);

        return '<main class="luangpho">'.$this->hospitalQuotationHeader($context, true, false)
            .'<div class="letter"><div class="letter-row"><span class="label">เรียน</span> '.$this->hospitalField($context, 'recipient', 'หัวหน้าเจ้าหน้าที่กลุ่มงานเภสัชกรรม โรงพยาบาลหลวงพ่อทวีศักดิ์').'</div></div>'
            .'<div class="intro">'.$this->hospitalField($context, 'intro', 'บริษัท เวเลอร์ เฮลธ์ จำกัด มีความยินดีขอเสนอราคาผลิตภัณฑ์ ดังรายการต่อไปนี้').'</div>'
            .$table
            .$this->hospitalTerms($context, [
                'ยืนยันราคาภายใน 60 วัน',
                'กำหนดส่งของภายใน 5 วัน นับถัดจากวันที่ได้รับใบสั่งซื้อ',
                'เจ้าหน้าที่ได้ต่อรองราคาแล้ว บริษัทฯ ไม่สามารถลดลงได้อีกเพราะราคาที่เสนอมาเป็นราคาที่ต่ำสุดแล้ว',
            ], '', true)
            .$this->hospitalSignature($context, 'ตัวแทนบริษัทฯ')
            .'<div class="contact">'.$this->hospitalField($context, 'contact', 'ติดต่อสอบถามเพิ่มเติมได้ที่ '.$context['salesperson'].' Fax : 02-678-8400').'</div></main>';
    }

    /** @param array<string, mixed> $context */
    private function policeGeneralQuotationTemplate(array $context): string
    {
        $headers = [
            ['label' => 'ลำดับ', 'width' => 9, 'key' => 'sequence', 'class' => 'center'],
            ['label' => 'รหัสสินค้า', 'width' => 11, 'key' => 'sku', 'class' => 'center'],
            ['label' => 'รายการ', 'width' => 31, 'key' => 'description', 'class' => 'description'],
            ['label' => 'จำนวน', 'width' => 7, 'key' => 'quantity', 'class' => 'center'],
            ['label' => '', 'width' => 8, 'key' => 'unit', 'class' => 'center'],
            ['label' => 'รวม VAT', 'width' => 17, 'key' => 'unit_price', 'class' => 'number'],
            ['label' => 'รวม VAT', 'width' => 17, 'key' => 'amount', 'class' => 'number'],
        ];

        $items = array_map(static function (array $item): array {
            $item['origin_details'] = '';

            return $item;
        }, $context['items']);
        $defaultTerms = [
            'กำหนดยืนยันราคา 60 วัน',
            'กำหนดส่งของภายใน 10 วัน นับถัดจากวันที่ได้รับใบสั่งซื้อ',
            'ผลิตภัณฑ์ AVANOS ประเทศสหรัฐอเมริกา',
            'เจ้าหน้าที่ของโรงพยาบาลได้ต่อรองราคาแล้ว แต่ทางบริษัทไม่สามารถลดลงได้อีก เนื่องจากเสนอราคาสุทธิที่ต่ำสุดแล้ว และราคาที่เสนอนี้เป็นราคาที่ไม่สูงไปกว่าราคาอ้างอิง ที่จำหน่ายให้กับสถานพยาบาลอื่น',
        ];
        $terms = $this->resolvedHospitalTerms($context, $defaultTerms);
        $leftTerms = '';
        foreach (array_slice($terms, 0, 3) as $index => $term) {
            $leftTerms .= '<div>'.$this->editableField('terms.'.$index, (string) $term, $context).'</div>';
        }
        $table = $this->hospitalItemsTable($headers, $items, false, $context);
        $summaryRows = '<tr><td colspan="5" rowspan="3" class="police-price-terms">'.$leftTerms.'</td><td>รวมเป็นเงิน</td><td class="number">'.number_format($context['subtotal'], 2).'</td></tr>'
            .'<tr><td>ภาษีมูลค่าเพิ่ม 7%</td><td class="number">'.number_format($context['vat'], 2).'</td></tr>'
            .'<tr><td>จำนวนเงินรวม</td><td class="number">'.number_format($context['total'], 2).'</td></tr>';
        $table = str_replace('</tbody></table>', $summaryRows.'</tbody></table>', $table);
        $longCondition = isset($terms[3])
            ? '<div class="police-long-condition">'.$this->editableField('terms.3', (string) $terms[3], $context).'</div>'
            : '';

        return '<main class="police-general">'.$this->hospitalQuotationHeader($context, true, true)
            .'<div class="letter"><div class="letter-row"><span class="label">เรื่อง</span> '.$this->hospitalField($context, 'subject', 'ขอเสนอราคาวัสดุอุปกรณ์ทางการแพทย์').'</div>'
            .'<div class="letter-row"><span class="label">เรียน</span> '.$this->hospitalField($context, 'recipient', 'นายแพทย์ใหญ่ (สบ 8) โรงพยาบาลตำรวจ').'</div></div>'
            .'<div class="intro">'.$this->hospitalField($context, 'intro', 'บริษัท เวเลอร์ เฮลธ์ จำกัด มีความยินดีเสนอราคาผลิตภัณฑ์ของ AVANOS ดังรายการต่อไปนี้').'</div>'
            .$table
            .'<div class="amount-words" style="margin-left:48%">'.e($this->bahtText->convert($context['total'])).'</div>'
            .$longCondition
            .$this->hospitalSignature($context, 'ผู้แทนฝ่ายขาย')
            .'<div class="contact">'.$this->hospitalField($context, 'contact', 'ติดต่อสอบถามเพิ่มเติมได้ที่ '.$context['salesperson'].' FAX.02-678-8400').'</div></main>';
    }

    /** @param array<string, mixed> $context */
    private function charoenkrungPracharakQuotationTemplate(array $context): string
    {
        $headers = [
            ['label' => 'ลำดับ', 'width' => 6, 'key' => 'sequence', 'class' => 'center'],
            ['label' => 'รหัสสินค้า', 'width' => 12, 'key' => 'sku', 'class' => 'center'],
            ['label' => 'รายการครุภัณฑ์', 'width' => 42, 'key' => 'description', 'class' => 'description'],
            ['label' => 'จำนวน', 'width' => 9, 'key' => 'quantity', 'class' => 'center'],
            ['label' => 'หน่วยนับ', 'width' => 10, 'key' => 'unit', 'class' => 'center'],
            ['label' => 'ราคาต่อหน่วย<br>(รวมภาษีมูลค่าเพิ่ม)', 'width' => 21, 'key' => 'unit_price', 'class' => 'number'],
        ];

        $table = $this->hospitalItemsTable($headers, $context['items'], true, $context);
        $summaryRows = '<tr><td colspan="5" rowspan="3" style="text-align:center;vertical-align:bottom">'.e($this->bahtText->convert($context['total'])).'</td>'
            .'<td><div class="charoen-summary-label"><span>ราคาไม่รวมภาษี</span><span class="value">'.number_format($context['subtotal'], 2).'</span></div></td></tr>'
            .'<tr><td><div class="charoen-summary-label"><span>ภาษีมูลค่าเพิ่ม 7%</span><span class="value">'.number_format($context['vat'], 2).'</span></div></td></tr>'
            .'<tr><td><div class="charoen-summary-label"><span>จำนวนเงินสุทธิ</span><span class="value">'.number_format($context['total'], 2).'</span></div></td></tr>';
        $table = str_replace('</tbody></table>', $summaryRows.'</tbody></table>', $table);

        return '<main class="charoenkrung">'.$this->hospitalQuotationHeader($context, true, false)
            .'<div class="letter"><div class="letter-row"><span class="label">เรียน</span> '.$this->hospitalField($context, 'recipient', 'ผู้อำนวยการ โรงพยาบาลเจริญกรุงประชารักษ์').'</div></div>'
            .'<div class="intro">'.$this->hospitalField($context, 'intro', 'บริษัท เวเลอร์ เฮลธ์ จำกัด มีความยินดีขอเสนอราคาผลิตภัณฑ์ของ AVANOS ดังรายการต่อไปนี้').'</div>'
            .$table
            .$this->hospitalTerms($context, [
                'กำหนดยืนราคา 360 วัน',
                'กำหนดส่งมอบสินค้า 5 วัน นับถัดจากวันลงนามในใบสั่งซื้อ',
                'ตามที่เจ้าหน้าที่ได้ต่อรองราคาตกลงนั้น บริษัทฯไม่สามารถลดลงได้อีก เนื่องจากเป็นราคาต่ำสุด',
                'บริษัท เวเลอร์ เฮลธ์ จำกัด ธนาคารไทยพาณิชย์ สาขาถนนสาทร เลขที่ 101-242025-0',
            ], '', true, 'หมายเหตุ :')
            .$this->hospitalSignature($context, 'ผู้แทนขาย')
            .'<div class="contact">'.$this->hospitalField($context, 'contact', 'ติดต่อสอบถามเพิ่มเติมได้ที่ '.$context['salesperson'].' FAX.02-678-8400').'</div></main>';
    }

    /** @param array<string, mixed> $context */
    private function ramathibodiQuotationTemplate(array $context): string
    {
        $headers = [
            ['label' => 'ลำดับ', 'width' => 7, 'key' => 'sequence', 'class' => 'center'],
            ['label' => 'รหัสสินค้า', 'width' => 12, 'key' => 'sku', 'class' => 'center'],
            ['label' => 'รายละเอียด', 'width' => 42, 'key' => 'description', 'class' => 'description'],
            ['label' => 'ลักษณะนาม', 'width' => 10, 'key' => 'unit', 'class' => 'center'],
            ['label' => 'ราคาที่เสนอ<br><small>(ราคารวม VAT 7% แล้ว)</small>', 'width' => 19, 'key' => 'unit_price', 'class' => 'number'],
            ['label' => 'หมายเหตุ', 'width' => 10, 'key' => 'remark', 'class' => 'center'],
        ];
        $items = array_map(static function (array $item): array {
            $item['remark'] = trim((string) ($item['remark'] ?? '')) ?: '1 '.$item['unit'].' ต่อ 1 กล่อง';
            $item['origin_details'] = '';

            return $item;
        }, $context['items']);

        return '<main class="ramathibodi compact">'.$this->hospitalQuotationHeader($context, false, true)
            .'<div class="letter"><div class="letter-row"><span class="label">เรื่อง</span> '.$this->hospitalField($context, 'subject', 'ขอเสนอราคาเวชภัณฑ์ทางการแพทย์').'</div>'
            .'<div class="letter-row"><span class="label">เรียน</span> '.$this->hospitalField($context, 'recipient', 'ผู้อำนวยการ โรงพยาบาลรามาธิบดี').'</div></div>'
            .'<div class="intro">'.$this->hospitalField($context, 'intro', 'บริษัท เวเลอร์ เฮลธ์ จำกัด มีความยินดีขอเสนอราคาดังรายการต่อไปนี้').'</div>'
            .$this->hospitalItemsTable($headers, $items, false, $context)
            .$this->hospitalTerms($context, [
                'ราคาที่เสนอนี้รวมภาษีมูลค่าเพิ่ม 7% แล้ว',
                'ผลิตภัณฑ์จากประเทศ USA',
                'กำหนดส่งของ 5 วันทำการ',
                'รับประกันคุณภาพ 1 ปี',
                'กำหนดยืนราคาไม่น้อยกว่า 1 ปี',
                'สินค้ามีวันหมดอายุข้างกล่อง',
                'สินค้าเปลี่ยนแปลงรายการชื่อเวชภัณฑ์ จาก Kimberly เป็น Avanos',
            ])
            .'<table class="closing-grid"><tr><td><div class="acknowledgement">ผู้ใช้งานรับทราบการเปลี่ยนแปลงชื่อเวชภัณฑ์</div>'
            .'<div style="margin:72px 0 0 4%">(ผู้แทนขาย '.e($context['salesperson']).')<br>'.$this->hospitalField($context, 'contact', 'โทร. -').'</div></td>'
            .'<td>'.$this->hospitalSignature($context, '(ผู้แทนฝ่ายขาย)', '0').'</td></tr></table></main>';
    }

    /** @param array<string, mixed> $context */
    private function sirirajPiyamaharajkarunQuotationTemplate(array $context): string
    {
        $headers = [
            ['label' => 'ลำดับที่', 'width' => 7, 'key' => 'sequence', 'class' => 'center'],
            ['label' => 'รหัส SAP', 'width' => 13, 'key' => 'sku', 'class' => 'center'],
            ['label' => 'รายการเวชภัณฑ์', 'width' => 33, 'key' => 'description', 'class' => 'description'],
            ['label' => 'จำนวน', 'width' => 7, 'key' => 'quantity', 'class' => 'center'],
            ['label' => 'หน่วย<br>สั่งซื้อ', 'width' => 9, 'key' => 'unit', 'class' => 'center'],
            ['label' => 'ขนาดบรรจุ', 'width' => 10, 'key' => 'pack', 'class' => 'center'],
            ['label' => 'ราคาต่อ<br>หน่วยรวม', 'width' => 11, 'key' => 'unit_price', 'class' => 'number'],
            ['label' => 'จำนวน<br>เงินรวม', 'width' => 10, 'key' => 'amount', 'class' => 'number'],
        ];

        $terms = $this->resolvedHospitalTerms($context, [
            'กำหนดยืนยันราคา วันที่ 1 ตุลาคม 2569 ถึง 30 กันยายน 2570',
            'กำหนดส่งของภายใน 5 วันทำการ นับจากวันที่ได้รับใบสั่งซื้อ',
            'ราคาที่ทำการซื้อขายต้องเป็นราคาเดียวกับโรงพยาบาลศิริราช โดยหากทางโรงพยาบาลศิริราช ปิยมหาราชการุณย์ตรวจพบว่ามีราคาต่างกัน บริษัทจะเสียค่าปรับเท่ากับมูลค่าที่แตกต่างกัน ซึ่งมีผลย้อนหลังไปถึงวันที่ผู้จะขายส่งมอบสินค้าที่มีราคาไม่เท่ากันให้แก่โรงพยาบาลฯ',
            'เงื่อนไขในการแลกเปลี่ยนสินค้าก่อนหมดอายุ.....1....ปี',
            'ยอดขั้นต่ำในการเปิดบิล 1,000 บาท',
            trim((string) $this->hospitalText($context, 'contact', '')) ?: 'ชื่อผู้แทน และ Client '.$context['salesperson'],
        ]);
        $termsHtml = '<div class="terms piyamaharajkarun-terms"><div class="terms-title">หมายเหตุ</div>';
        foreach ($terms as $index => $term) {
            $prefix = $index < 2 ? '- ' : '';
            $class = $index === 2 ? 'terms-line piyamaharajkarun-price-condition' : 'terms-line';
            $termsHtml .= '<div class="'.$class.'">'.e($prefix)
                .$this->editableField('terms.'.$index, (string) $term, $context).'</div>';
        }
        $termsHtml .= '</div>';

        return '<main class="piyamaharajkarun">'.$this->hospitalQuotationHeader($context, true, true)
            .'<div class="letter"><div class="letter-row"><span class="label">เรื่อง</span> '.$this->hospitalField($context, 'subject', 'ขอเสนอราคาเวชภัณฑ์').'</div>'
            .'<div class="letter-row"><span class="label">เรียน</span> '.$this->hospitalField($context, 'recipient', 'ประธานคณะกรรมการจัดหา โรงพยาบาลศิริราช ปิยมหาราชการุณย์').'</div></div>'
            .$this->hospitalItemsTable($headers, $context['items'], false, $context)
            .$this->hospitalSummaryTable([
                'ราคาสินค้าก่อนรวมภาษีมูลค่าเพิ่ม' => $context['subtotal'],
                'ภาษีมูลค่าเพิ่ม 7%' => $context['vat'],
                'ยอดรวมสุทธิ' => $context['total'],
            ], 'width:34%')
            .'<div class="amount-words">'.e($this->bahtText->convert($context['total'])).'</div>'
            .$termsHtml
            .$this->hospitalSignature($context, 'ผู้เสนอราคา').'</main>';
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, array{label:string,width:int,key:string,class:string}>  $headers
     * @param  array<int, array<string, mixed>>  $items
     */
    private function hospitalItemsTable(array $headers, array $items, bool $tall = false, array $context = []): string
    {
        $head = '';
        foreach ($headers as $header) {
            $head .= '<th style="width:'.$header['width'].'%">'.$header['label'].'</th>';
        }
        $rows = '';
        foreach ($items as $index => $item) {
            $rows .= '<tr'.($tall && count($items) === 1 ? ' class="tall"' : '').'>';
            foreach ($headers as $header) {
                $value = (string) ($item[$header['key']] ?? '');
                $editable = ! in_array($header['key'], ['sequence', 'amount'], true);
                $content = $editable
                    ? $this->editableField('items.'.$index.'.'.$header['key'], $value, $context)
                    : e($value);
                if ($header['key'] === 'description' && ! empty($item['origin_details'])) {
                    $content .= '<div class="origin-details">'.$this->editableField('items.'.$index.'.origin_details', (string) $item['origin_details'], $context).'</div>';
                }
                $rows .= '<td class="'.$header['class'].'">'.$content.'</td>';
            }
            $rows .= '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="'.count($headers).'" class="empty-row">ไม่มีรายการสินค้า</td></tr>';
        }

        return '<table class="items"><thead><tr>'.$head.'</tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    /** @param array<string, float|int> $rows */
    private function hospitalSummaryTable(array $rows, string $style, bool $shaded = false): string
    {
        $html = '<table class="summary" style="'.$style.'">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td class="summary-label'.($shaded ? ' shade' : '').'">'.e($label).'</td><td class="number">'.number_format((float) $value, 2).'</td></tr>';
        }

        return $html.'</table>';
    }

    /** @param array<string, mixed> $context */
    private function hospitalQuotationHeader(array $context, bool $showNumber, bool $showDate): string
    {
        $logo = $context['logoUrl'] !== '' ? '<img class="logo" src="'.e($context['logoUrl']).'" alt="Valor Health">' : '';
        $meta = [];
        if ($showNumber) {
            $meta[] = 'เลขที่ '.$this->editableField('document.number', (string) $context['documentNo'], $context);
        }
        if ($showDate) {
            $meta[] = 'วันที่ '.e($context['date']);
        }

        return '<header class="document-header">'.$logo.'<div class="document-title">ใบเสนอราคา</div>'
            .($meta === [] ? '' : '<div class="document-meta'.(! $showDate ? ' no-date' : '').'">'.implode('<br>', $meta).'</div>')
            .'</header>';
    }

    /** @param array<string, mixed> $context */
    private function hospitalSignature(array $context, string $role, string $marginTop = '18px'): string
    {
        return '<table class="signature" style="margin-top:'.$marginTop.'"><tbody>'
            .'<tr><td>ขอแสดงความนับถือ</td></tr>'
            .'<tr><td>'.$context['signature'].'</td></tr>'
            .'<tr><td>('.(! empty($context['approval']['name']) ? e((string) $context['approval']['name']) : $this->hospitalField($context, 'salesperson', (string) $context['salesperson'])).')</td></tr>'
            .'<tr><td>'.(! empty($context['approval']) ? 'ผู้มีอำนาจอนุมัติของบริษัท' : $this->hospitalField($context, 'signature_role', $role)).'</td></tr>'
            .'</tbody></table>';
    }

    /** @param array<string, mixed> $context */
    private function hospitalText(array $context, string $key, string $default): string
    {
        $value = data_get($context, 'overrides.document.'.$key);

        return is_string($value) ? $value : $default;
    }

    /** @param array<string, mixed> $context */
    private function hospitalField(array $context, string $key, string $default): string
    {
        return $this->editableField('document.'.$key, $this->hospitalText($context, $key, $default), $context);
    }

    /** @param array<string, mixed> $context */
    private function editableField(string $path, string $value, array $context): string
    {
        if (! data_get($context, 'overrides._editor', false)) {
            return e($value);
        }

        return '<span data-edit-path="'.e($path).'" contenteditable="true" spellcheck="false">'.e($value).'</span>';
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, string>  $defaults
     */
    private function hospitalTerms(array $context, array $defaults, string $style = '', bool $numbered = false, string $title = 'หมายเหตุ'): string
    {
        $terms = $this->resolvedHospitalTerms($context, $defaults);
        $lines = '';
        foreach (array_values($terms) as $index => $term) {
            $prefix = $numbered ? ($index + 1).'. ' : '- ';
            $lines .= '<div class="terms-line">'.e($prefix).$this->editableField('terms.'.$index, (string) $term, $context).'</div>';
        }

        return '<div class="terms"'.($style !== '' ? ' style="'.$style.'"' : '').'><div class="terms-title">'.e($title).'</div>'.$lines.'</div>';
    }

    /**
     * Keep mandatory template wording when an older draft or version contains only part of the term list.
     * User-edited rows continue to override the matching positions, while missing rows fall back to the template.
     *
     * @param  array<string, mixed>  $context
     * @param  array<int, string>  $defaults
     * @return array<int, string>
     */
    private function resolvedHospitalTerms(array $context, array $defaults): array
    {
        $terms = array_values($defaults);
        $overrides = data_get($context, 'overrides.terms');
        if (! is_array($overrides)) {
            return $terms;
        }

        foreach (array_values($overrides) as $index => $term) {
            $value = trim((string) $term);
            if ($value !== '') {
                $terms[$index] = $value;
            }
        }

        return array_values($terms);
    }

    /** @param array<string, mixed> $context */
    private function additionalQuotationNote(array $context): string
    {
        $note = trim((string) ($context['note'] ?? ''));

        return $note === '' || $note === '-' ? '' : '<div class="terms-line">หมายเหตุเพิ่มเติม: '.e($note).'</div>';
    }

    private function hospitalQuotationFooter(string $template): string
    {
        if ($template === QuotationTemplateCatalog::CHAROENKRUNG_PRACHARAK) {
            return '<footer class="footer"><div class="footer-bar"><span></span><span></span><span></span></div>'
                .'<strong>บริษัท เวเลอร์ เฮลธ์ จำกัด</strong> 2/22 ตึกไอยราทาวเวอร์ ชั้น 10 ถนนจันทน์ แขวงทุ่งวัดดอน เขตสาทร กรุงเทพฯ 10120 โทร.02-678-5150<br>'
                .'Valor Health Co., Ltd. 2/22 IYARA TOWER 10FR, Chan Road, Tungwatdon, Sathorn, Bangkok 10120<br>'
                .'Tax ID.0105558041179, Email: valorhealth@valorhealth.co.th</footer>';
        }

        return '<footer class="footer"><div class="footer-bar"><span></span><span></span><span></span></div>'
            .'<strong>บริษัท เวเลอร์ เฮลธ์ จำกัด</strong> 2/22 ถนน จันทน์ แขวงทุ่งวัดดอน เขตสาทร กรุงเทพ 10120 โทร.02-678-5150 Tax ID.0105558041179<br>'
            .'<span class="company-en">Valor Health Co., Ltd.</span> 2/22 Chan Road, Tungwatdon, Sathorn, Bangkok 10120, Thailand Tel.+662-678-5150<br>'
            .'Email: valorhealth@valorhealth.co.th</footer>';
    }

    private function thaiLongDate(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('d-m-Y', $date);
        if (! $parsed) {
            return $date;
        }
        $months = [1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

        return $parsed->format('j').' '.$months[(int) $parsed->format('n')].' '.((int) $parsed->format('Y') + 543);
    }

    /** @param Collection<int, object> $items */
    private function renderPdf(
        string $title,
        string $englishTitle,
        string $documentNo,
        string $date,
        string $customerName,
        string $customerCode,
        string $customerAddress,
        string $customerTaxId,
        string $salesperson,
        string $department,
        string $note,
        float $total,
        float $billDiscount,
        Collection $items,
        bool $copy,
        bool $canceled,
        bool $rejected = false,
        ?array $approvalEvidence = null,
        bool $htmlOnly = false,
        array $editorPayload = [],
    ): string {
        $fontPath = realpath(resource_path('assets/fonts/THSarabunNew.ttf'));
        $logoPath = realpath(resource_path('assets/images/valorhealth.png'));
        $fontUrl = $fontPath !== false ? $this->fileUrl($fontPath) : '';
        $logoUrl = $logoPath !== false ? $this->fileUrl($logoPath) : '';
        $fontFamily = $fontUrl !== '' ? 'THSarabun,DejaVu Sans,sans-serif' : 'DejaVu Sans,sans-serif';
        $vat = $total * 7 / 107;
        $subtotal = $total - $vat;
        $lineDiscount = 0.0;
        $rows = '';
        $editorContext = ['overrides' => $editorPayload];
        foreach ($items->values() as $index => $item) {
            $before = (float) ($item->line_total_before_discount ?? ((float) $item->unit_price * (int) $item->quantity));
            $lineTotal = (float) ($item->line_total ?? 0);
            $discount = max($before - $lineTotal, (float) ($item->sale_discount_unit ?? 0), 0);
            $lineDiscount += $discount;
            $rows .= '<tr><td>'.$this->editableField('items.'.$index.'.sku', (string) $item->main_category, $editorContext).'</td><td>'.$this->editableField('items.'.$index.'.description', (string) $item->sub_category, $editorContext).'</td><td class="num">'.$this->editableField('items.'.$index.'.quantity', number_format((float) $item->quantity), $editorContext).'</td><td class="num">'.$this->editableField('items.'.$index.'.unit_price', number_format((float) $item->unit_price, 2), $editorContext).'</td><td class="num">'.$this->editableField('items.'.$index.'.discount', number_format($discount, 2), $editorContext).'</td><td class="num">'.number_format($lineTotal, 2).'</td></tr>';
        }
        for ($index = $items->count(); $index < 4; $index++) {
            $rows .= '<tr class="empty"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>';
        }

        $watermark = $rejected ? 'ไม่อนุมัติ' : ($canceled ? 'ยกเลิกเอกสาร' : ($copy ? 'สำเนา (COPY)' : ''));
        $watermarkClass = $canceled ? ' canceled' : '';
        $rejectionCross = $rejected
            ? '<div class="rejection-cross rejection-cross--forward"></div><div class="rejection-cross rejection-cross--backward"></div>'
            : '';
        $isQuotation = $title === 'ใบเสนอราคา';
        $isStandardEditor = $isQuotation && data_get($editorPayload, 'template') === QuotationTemplateCatalog::STANDARD;
        $addressHeading = $isStandardEditor ? '' : '<strong>ที่อยู่:</strong> ';
        $customerAddressHtml = nl2br(e($customerAddress));
        $customerCodeHtml = e($customerCode);
        $customerTaxIdHtml = e($customerTaxId);
        if ($isStandardEditor) {
            $customerPayload = (array) ($editorPayload['customer'] ?? []);
            $addressParts = [];
            if ((bool) ($customerPayload['show_billing_address'] ?? true)) {
                $addressParts[] = '<strong>ที่อยู่ออกบิล:</strong> '.$this->editableField('customer.billing_address', (string) ($customerPayload['billing_address'] ?? ''), $editorContext);
            }
            if ((bool) ($customerPayload['show_delivery_address'] ?? false)
                && (! (bool) ($customerPayload['show_billing_address'] ?? true)
                    || trim((string) ($customerPayload['delivery_address'] ?? '')) !== trim((string) ($customerPayload['billing_address'] ?? '')))) {
                $addressParts[] = '<strong>ที่อยู่จัดส่ง:</strong> '.$this->editableField('customer.delivery_address', (string) ($customerPayload['delivery_address'] ?? ''), $editorContext);
            }
            $customerAddressHtml = implode('<br>', $addressParts);
            $customerCodeHtml = $this->editableField('customer.code', (string) ($customerPayload['code'] ?? ''), $editorContext);
            $customerTaxIdHtml = $this->editableField('customer.tax_id', (string) ($customerPayload['tax_id'] ?? '-'), $editorContext);
        }
        $documentBranch = $isQuotation
            ? 'สำนักงานใหญ่<br>สาขา '.e($department)
            : 'สาขาที่ออกใบกำกับภาษี<br>สาขา '.e($department);
        $leftDocumentText = $isQuotation
            ? '<div class="received">เงื่อนไขการยืนยัน: '.($editorPayload
                ? $this->editableField('document.confirmation_terms', (string) data_get($editorPayload, 'document.confirmation_terms', 'ลูกค้าลงนามในเอกสารนี้ หรือออก PO ที่อ้างอิงใบเสนอราคา'), $editorContext)
                : 'ลูกค้าลงนามในเอกสารนี้ หรือออก PO ที่อ้างอิงใบเสนอราคา').'</div><div class="document-note"><strong>หมายเหตุ:</strong> '.($editorPayload
                ? $this->editableField('document.document_note', (string) data_get($editorPayload, 'document.document_note', 'กรุณาตรวจสอบรายการ ราคา เงื่อนไขชำระเงิน และกำหนดส่งก่อนยืนยัน'), $editorContext)
                : 'กรุณาตรวจสอบรายการ ราคา เงื่อนไขชำระเงิน และกำหนดส่งก่อนยืนยัน').'</div>'
            : '<div class="received">○ ได้รับสินค้าตามรายการนี้ในสภาพสมบูรณ์และจำนวนครบถ้วน</div><div class="document-note"><strong>หมายเหตุ:</strong> เป็นการยกเลิกใบกำกับภาษีอย่างย่อ<br>และออกใบกำกับภาษีเต็มรูปแบบแทน</div>';
        $customerNoteHtml = e($note);
        if ($isStandardEditor) {
            $noteParts = [
                $this->editableField('document.subject', (string) data_get($editorPayload, 'document.subject', ''), $editorContext),
                $this->editableField('document.intro', (string) data_get($editorPayload, 'document.intro', ''), $editorContext),
            ];
            foreach ((array) ($editorPayload['terms'] ?? []) as $index => $term) {
                $noteParts[] = $this->editableField('terms.'.$index, (string) $term, $editorContext);
            }
            $customerNoteHtml = implode(' | ', array_filter($noteParts, fn (string $part): bool => trim(strip_tags($part)) !== '')) ?: '-';
        }
        $signatureHtml = '';
        if ($title === 'ใบเสนอราคา') {
            $signatureImage = ! empty($approvalEvidence['signature_image'])
                ? '<img class="signature-image" src="data:image/png;base64,'.e($approvalEvidence['signature_image']).'" alt="ลายเซ็นผู้อนุมัติ"><br>'
                : '';
            $companyApproval = $approvalEvidence
                ? '<strong>อนุมัติด้วยลายมือชื่ออิเล็กทรอนิกส์ทั่วไป</strong><br>'.$signatureImage.e($approvalEvidence['name']).' ('.e($approvalEvidence['role']).')<br>'.e($approvalEvidence['signed_at']).'<br><span class="hash">Document SHA-256: '.e($approvalEvidence['hash']).'</span><br><span class="hash">Signature image SHA-256: '.e((string) ($approvalEvidence['signature_image_hash'] ?? '-')).'</span>'
                : '<span class="signature-line">ลงชื่อ __________________________________</span><br>ผู้มีอำนาจอนุมัติของบริษัท<br>วันที่ __________________';
            if (! empty($approvalEvidence['customer_signature_image'])) {
                $customerAcceptance = '<strong>ลูกค้ายืนยันผ่าน Customer Portal</strong><br><img class="signature-image" src="data:image/png;base64,'.e((string) $approvalEvidence['customer_signature_image']).'" alt="ลายเซ็นลูกค้า"><br>'.e((string) $approvalEvidence['customer_signer_name']).' · '.e((string) $approvalEvidence['customer_signer_position']).'<br>'.e((string) $approvalEvidence['customer_signer_organization']).'<br>'.e((string) $approvalEvidence['customer_accepted_at']).'<br><span class="hash">Document SHA-256: '.e((string) $approvalEvidence['customer_payload_hash']).'</span><br><span class="hash">Signature SHA-256: '.e((string) $approvalEvidence['customer_signature_hash']).'</span>';
            } elseif (($approvalEvidence['customer_evidence_type'] ?? null) === 'signed_quotation') {
                $customerAcceptance = '<strong>ลูกค้ายืนยันด้วยใบเสนอราคาที่ลงชื่อ</strong><br>'.e((string) $approvalEvidence['customer_evidence_file']).'<br>วันที่ '.e((string) ($approvalEvidence['customer_confirmed_at'] ?: '-')).'<br>ไฟล์ต้นฉบับเก็บแยกในระบบ';
            } elseif (! empty($approvalEvidence['customer_po_no']) || ($approvalEvidence['customer_evidence_type'] ?? null) === 'customer_po') {
                $customerAcceptance = '<strong>ลูกค้ายืนยันด้วย PO</strong><br>เลขที่ '.e((string) ($approvalEvidence['customer_po_no'] ?: '-')).'<br>วันที่ '.e((string) ($approvalEvidence['customer_confirmed_at'] ?: '-')).'<br>PO ต้นฉบับเก็บแยกในระบบ';
            } else {
                $customerAcceptance = '<span class="signature-line">ลงชื่อ __________________________________</span><br>ผู้มีอำนาจของลูกค้า / ผู้ยืนยันคำสั่งซื้อ<br>ชื่อ __________________ วันที่ __________________';
            }
            $signatureHtml = '<table class="signature-table"><tr><td>'.$companyApproval.'</td><td>'.$customerAcceptance.'</td></tr></table><div class="signature-note">หลักฐานอิเล็กทรอนิกส์ผูกกับ Revision และค่า SHA-256 โปรดตรวจสอบรายละเอียดจากใบรับรองการอนุมัติในระบบ</div>';
        }
        $logo = $logoUrl ? '<div class="logo-wrap"><img class="logo" src="'.e($logoUrl).'" alt="Valor Health"></div>' : '';
        $html = '<!doctype html><html lang="th"><head><meta charset="utf-8"><style>
            @page{margin:24px 27px 30px}
            body{font-family:'.$fontFamily.';font-size:16px;line-height:1.18;color:#222}
            .logo-wrap{text-align:center;margin:0 0 10px}.logo{display:inline-block;width:135px;height:auto}
            table{border-spacing:0}.header{width:100%;border-collapse:collapse}.header td{vertical-align:top}.header .company-cell{width:49%}.header .title-cell{width:25%;text-align:center}.header .document-cell{width:26%;text-align:right}
            .company{font-size:17px;font-weight:bold}.header-line{margin-bottom:3px}.document-title{font-size:21px;font-weight:bold;line-height:1.25;margin:0}.document-title span{font-size:18px;font-weight:normal}
            .customer{width:100%;border-collapse:collapse;border:1px solid #222;margin-top:10px}.customer td{vertical-align:top;padding:6px 7px}.customer-left{width:66%;border-right:1px solid #222}.customer-right{width:34%}.customer-line{min-height:19px;margin-bottom:2px}
            .items{width:100%;border-collapse:collapse;margin-top:10px;table-layout:fixed}.items th,.items td{border:1px solid #222;padding:4px 5px;font-size:15px;vertical-align:top}.items th{text-align:center;font-weight:normal;height:43px;vertical-align:middle}.items th strong{font-weight:bold}.items td:nth-child(1){width:13%}.items td:nth-child(2){width:35%}.items td:nth-child(3){width:8%}.items td:nth-child(4){width:13%}.items td:nth-child(5){width:11%}.items td:nth-child(6){width:20%}.num{text-align:right}.empty{height:24px}
            .after-items{width:100%;border-collapse:collapse;margin-top:-1px;table-layout:fixed}.after-items>tbody>tr>td{padding:0;vertical-align:top}.after-left{width:56%;padding:7px 10px 0 5px!important}.after-right{width:44%}.words{text-align:center;font-weight:bold;margin-bottom:12px}.received{margin:0 0 18px}.document-note{line-height:1.25}
            .summary{width:100%;border-collapse:collapse}.summary td{border:1px solid #222;padding:5px 6px;height:29px;vertical-align:middle}.summary .label{width:64%}.summary .value{width:36%;text-align:right}
            .footer{text-align:center;color:#e3202f;font-size:14px;line-height:1.3;margin-top:16px}.watermark{position:fixed;top:43%;left:12%;font-size:86px;color:rgba(100,100,100,.16);transform:rotate(-35deg);z-index:-1}.watermark.canceled{color:rgba(220,0,0,.18)}
            .rejection-cross{position:fixed;top:49%;left:8%;width:84%;border-top:14px solid rgba(220,0,0,.14);z-index:-2}.rejection-cross--forward{transform:rotate(35deg)}.rejection-cross--backward{transform:rotate(-35deg)}
            .signature-table{width:100%;border-collapse:collapse;margin-top:13px;table-layout:fixed}.signature-table td{width:50%;border:1px solid #222;padding:8px;text-align:center;vertical-align:middle;height:66px}.signature-line{line-height:2}.hash{font-size:9px;word-break:break-all}.signature-note{text-align:center;font-size:11px;margin-top:4px;color:#555}
            .signature-image{display:inline-block;max-width:150px;max-height:55px;object-fit:contain;margin:3px auto}
            [data-edit-path][contenteditable="true"]{border-radius:2px;cursor:text}[data-edit-path][contenteditable="true"]:hover{outline:1px dashed #60a5fa;background:#eff6ff}[data-edit-path][contenteditable="true"]:focus{outline:2px solid #2563eb;background:#fff}
        </style></head><body>'.$rejectionCross.($watermark ? '<div class="watermark'.$watermarkClass.'">'.e($watermark).'</div>' : '').$logo.'
        <table class="header"><tr>
            <td class="company-cell"><div class="company header-line">'.($isStandardEditor ? $this->editableField('document.company_name', (string) data_get($editorPayload, 'document.company_name', 'บริษัท เวเลอร์ เฮลธ์ จำกัด (สำนักงานใหญ่)'), $editorContext) : 'บริษัท เวเลอร์ เฮลธ์ จำกัด (สำนักงานใหญ่)').'</div><div class="header-line">'.($isStandardEditor ? $this->editableField('document.company_address', (string) data_get($editorPayload, 'document.company_address', ''), $editorContext) : '2/22 อาคารไอยรา ถ.จันทน์ แขวงทุ่งวัดดอน เขตสาทร จ.กรุงเทพมหานคร 10120').'</div><div class="header-line">'.($isStandardEditor ? $this->editableField('document.company_contact', (string) data_get($editorPayload, 'document.company_contact', ''), $editorContext) : 'โทรศัพท์ +66-655219785').'</div><div>'.($isStandardEditor ? $this->editableField('document.company_tax_id', (string) data_get($editorPayload, 'document.company_tax_id', ''), $editorContext) : 'เลขผู้เสียภาษี 0105558041179').'</div></td>
            <td class="title-cell"><div class="document-title">'.($isStandardEditor ? $this->editableField('document.title', (string) data_get($editorPayload, 'document.title', $title), $editorContext) : e($title)).'<br><span>'.($isStandardEditor ? $this->editableField('document.english_title', (string) data_get($editorPayload, 'document.english_title', $englishTitle), $editorContext) : e($englishTitle)).'</span></div></td>
            <td class="document-cell">'.($isStandardEditor ? $this->editableField('document.branch', (string) data_get($editorPayload, 'document.branch', 'สำนักงานใหญ่ สาขา'), $editorContext) : $documentBranch).'<br><br>เลขที่ '.$this->editableField('document.number', $documentNo, $editorContext).'<br>วันที่ '.$this->editableField('document.date', (string) data_get($editorPayload, 'document.date', $date), $editorContext).'</td>
        </tr></table>
        <table class="customer"><tr>
            <td class="customer-left"><div class="customer-line"><strong>ชื่อลูกค้า:</strong> '.$this->editableField('document.recipient', $customerName, $editorContext).'</div><div class="customer-line">'.$addressHeading.$customerAddressHtml.'</div><div><strong>เลขที่ผู้เสียภาษี:</strong> '.$customerTaxIdHtml.'</div><div><strong>หมายเหตุ:</strong> '.$customerNoteHtml.'</div></td>
            <td class="customer-right"><div class="customer-line"><strong>รหัสลูกค้า:</strong> '.$customerCodeHtml.'</div><div style="height:35px"></div><div><strong>พนักงานขาย:</strong> '.$this->editableField('document.salesperson', $salesperson, $editorContext).'</div></td>
        </tr></table>
        <table class="items"><thead><tr><th style="width:13%"><strong>รหัสสินค้า</strong><br>Product ID</th><th style="width:35%"><strong>รายการ</strong><br>Description</th><th style="width:8%"><strong>จำนวน</strong><br>Quantity</th><th style="width:13%"><strong>ราคาต่อหน่วย</strong><br>Unit Price</th><th style="width:11%"><strong>ส่วนลด</strong><br>Discount</th><th style="width:20%"><strong>จำนวนเงิน</strong><br>Amount (Baht)</th></tr></thead><tbody>'.$rows.'</tbody></table>
        <table class="after-items"><tr><td class="after-left"><div class="words">-'.e($this->bahtText->convert($total)).'-</div>'.$leftDocumentText.'</td><td class="after-right">
            <table class="summary"><tr><td class="label">มูลค่าราคารวมทั้งสิ้น (Total Amount)</td><td class="value">'.number_format($subtotal, 2).'</td></tr><tr><td class="label">รวมส่วนลดทั้งหมด (Total Discount)</td><td class="value">'.number_format($lineDiscount + $billDiscount, 2).'</td></tr><tr><td class="label">จำนวนภาษีมูลค่าเพิ่ม (VAT)</td><td class="value">'.number_format($vat, 2).'</td></tr><tr><td class="label">จำนวนเงินรวมทั้งสิ้น (Grand Total)</td><td class="value">'.number_format($total, 2).'</td></tr></table>
        </td></tr></table>
        '.$signatureHtml.'
        <div class="footer">'.($isQuotation
            ? 'ใบเสนอราคานี้ไม่ใช่ใบกำกับภาษี และใช้ตามเงื่อนไข/ระยะเวลาที่ระบุในเอกสาร'
            : 'เอกสารนี้ได้จัดทำและส่งข้อมูลให้แก่กรมสรรพากรด้วยวิธีการทางอิเล็กทรอนิกส์<br>ทางบริษัทฯ ขอสงวนสิทธิ์ในการแก้ไข เปลี่ยนแปลง หรือยกเลิกเอกสารฉบับนี้ ภายใน 7 วัน นับจากวันที่ระบุในเอกสาร').'</div>
        </body></html>';

        return $htmlOnly ? $html : $this->dompdfOutput($html);
    }

    public function approvalCertificatePdf(BillingRecord $bill, DocumentSignature $signature): string
    {
        $bill->loadMissing('documents');
        $documentRows = $bill->documents->map(fn ($document): string => '<tr><td>'.e($document->document_type).'</td><td>'.e($document->file_name).'</td><td>v'.e((string) $document->version_no).'</td><td class="hash">'.e((string) ($document->sha256 ?: '-')).'</td></tr>')->implode('');
        $signedDocumentRows = collect(data_get($signature->payload_snapshot, 'evidence_documents', []))
            ->map(fn (array $document): string => '<tr><td>'.e((string) ($document['document_type'] ?? '-')).'</td><td>'.e((string) ($document['file_name'] ?? '-')).'</td><td>v'.e((string) ($document['version_no'] ?? '-')).'</td><td class="hash">'.e((string) ($document['sha256'] ?? '-')).'</td></tr>')
            ->implode('');
        $fontPath = realpath(resource_path('assets/fonts/THSarabunNew.ttf'));
        $fontUrl = $fontPath !== false ? $this->fileUrl($fontPath) : '';
        $fontFamily = $fontUrl !== '' ? 'THSarabun,DejaVu Sans,sans-serif' : 'DejaVu Sans,sans-serif';
        $documentLabel = $bill->workflow_type === 'customer_po_direct'
            ? 'รายการเปิดบิลจาก PO ลูกค้า Bill #'.e((string) $bill->bill_id)
            : 'ใบเสนอราคาอ้างอิง Bill #'.e((string) $bill->bill_id).' / Revision R'.e((string) $signature->revision_no);
        $quotationDocument = data_get($signature->payload_snapshot, 'quotation_document');
        $quotationDocumentEvidence = is_array($quotationDocument)
            ? '<div class="box"><strong>PDF ใบเสนอราคาที่อนุมัติ:</strong> '.e((string) ($quotationDocument['filename'] ?? '-')).'<br><strong>Template:</strong> '.e((string) ($quotationDocument['template_id'] ?? '-')).' · <strong>Document Revision:</strong> '.e((string) ($quotationDocument['revision_no'] ?? '-')).'<br><strong>PDF SHA-256:</strong> <span class="hash">'.e((string) ($quotationDocument['pdf_sha256'] ?? '-')).'</span></div>'
            : '';
        $html = '<!doctype html><html lang="th"><head><meta charset="utf-8"><style>@page{margin:32px}body{font-family:'.$fontFamily.';font-size:17px;color:#172033}h1{color:#b82032;margin:0 0 5px}.sub{color:#687084}.box{border:1px solid #ccd1d9;padding:12px;margin-top:14px}table{width:100%;border-collapse:collapse;margin-top:10px}td,th{border:1px solid #ccd1d9;padding:6px;text-align:left;vertical-align:top}.hash{font-family:monospace;font-size:9px;word-break:break-all}.notice{margin-top:16px;padding:10px;background:#fff4f5;border-left:4px solid #b82032;font-size:14px}</style></head><body>
            <h1>ใบรับรองหลักฐานการอนุมัติอิเล็กทรอนิกส์</h1><div class="sub">Electronic Approval Evidence Certificate</div>
            <div class="box"><strong>เอกสาร:</strong> '.$documentLabel.'<br><strong>ลูกค้า:</strong> '.e((string) $bill->buyer_name).'<br><strong>ยอดรวม:</strong> '.number_format((float) $bill->total_amount, 2).' บาท</div>'.$quotationDocumentEvidence.'
            <table><tr><th>ผู้แสดงเจตนา</th><td>'.(! empty($signature->signature_image_base64) ? '<img style="max-width:170px;max-height:65px" src="data:image/png;base64,'.e($signature->signature_image_base64).'" alt="ลายเซ็น"><br>' : '').e((string) ($signature->signer_name ?: $signature->signer_username)).' ('.e((string) $signature->signer_role).')</td></tr><tr><th>บัญชี / อีเมล</th><td>'.e((string) $signature->signer_username).' / '.e((string) ($signature->signer_email ?: '-')).'</td></tr><tr><th>วันเวลาลงนาม</th><td>'.e((string) $signature->signed_at?->timezone('Asia/Bangkok')->format('d-m-Y H:i:s')).' (Asia/Bangkok)</td></tr><tr><th>เจตนา</th><td>'.e((string) $signature->intent_text).'</td></tr><tr><th>วิธีการ</th><td>ภาพลายเซ็น Version ที่ผูกกับบัญชี + ยืนยันบัญชีและกรอกรหัสผ่านซ้ำ (ลายมือชื่ออิเล็กทรอนิกส์ทั่วไป)</td></tr><tr><th>SHA-256 ของภาพลายเซ็น</th><td class="hash">'.e((string) ($signature->signature_image_sha256 ?: '-')).'</td></tr><tr><th>IP / User Agent</th><td>'.e((string) ($signature->ip_address ?: '-')).'<br>'.e((string) ($signature->user_agent ?: '-')).'</td></tr><tr><th>SHA-256 ของข้อมูลที่อนุมัติ</th><td class="hash">'.e((string) $signature->payload_sha256).'</td></tr></table>
            <h3>ไฟล์หลักฐานที่อยู่ใน Snapshot ขณะอนุมัติ</h3><table><tr><th>ชนิด</th><th>ชื่อไฟล์</th><th>รุ่น</th><th>SHA-256</th></tr>'.($signedDocumentRows ?: '<tr><td colspan="4">ไม่มีไฟล์หลักฐานลูกค้าในขณะบริษัทอนุมัติ</td></tr>').'</table>
            <h3>ไฟล์ยืนยันจากลูกค้าปัจจุบัน (อาจได้รับหลังบริษัทอนุมัติ)</h3><table><tr><th>ชนิด</th><th>ชื่อไฟล์</th><th>รุ่น</th><th>SHA-256</th></tr>'.($documentRows ?: '<tr><td colspan="4">ยังไม่มีไฟล์หลักฐานลูกค้า</td></tr>').'</table>
            <div class="notice">เอกสารนี้เป็นหลักฐานของลายมือชื่ออิเล็กทรอนิกส์ทั่วไปตามกระบวนการภายในระบบ ไม่ใช่ลายมือชื่อดิจิทัลแบบ PKI และไม่ใช่ใบรับรองจากผู้ให้บริการออกใบรับรอง</div></body></html>';

        return $this->dompdfOutput($html);
    }

    private function dompdfOutput(string $html): string
    {
        $fontPath = realpath(resource_path('assets/fonts/THSarabunNew.ttf'));
        $fontUrl = $fontPath !== false ? $this->fileUrl($fontPath) : '';
        $dompdfDirectory = storage_path('app/private/dompdf');
        $fontDirectory = $dompdfDirectory.DIRECTORY_SEPARATOR.'fonts';
        File::ensureDirectoryExists($fontDirectory);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', dirname(base_path()));
        $options->setTempDir($dompdfDirectory);
        $options->setFontDir($fontDirectory);
        $options->setFontCache($fontDirectory);

        // Font subsetting creates temporary files while output() is running.
        // On Windows that temporary path can become empty under the PHP dev
        // server, so embed the complete Thai font for deterministic exports.
        $options->setIsFontSubsettingEnabled(false);
        $dompdf = new Dompdf($options);
        if ($fontUrl !== '') {
            $fontMetrics = $dompdf->getFontMetrics();
            $fontMetrics->registerFont(['family' => 'THSarabun', 'weight' => 'normal', 'style' => 'normal'], $fontUrl);
            $fontMetrics->registerFont(['family' => 'THSarabun', 'weight' => 'bold', 'style' => 'normal'], $fontUrl);
        }
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    private function fileUrl(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        // DomPDF removes the literal "file://" prefix before realpath().
        // Keeping the Windows drive directly after that prefix produces C:/...
        // instead of the invalid /C:/... path created by file:///C:/....
        return 'file://'.$normalized;
    }

    private function pdfDownloadName(string $prefix, string $customerName, string $date): string
    {
        $customer = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', trim($customerName));
        $customer = preg_replace('/\s+/u', ' ', (string) $customer);

        return $prefix.'_'.($customer !== '' ? $customer : 'Customer').'_'.$date.'.pdf';
    }

    /** @param Collection<int, BillingRecord> $bills */
    public function bplusWorkbook(Collection $bills): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bplus Import');
        $headers = [
            'วันที่เอกสาร', 'เลขที่เอกสาร', 'วันที่ใบกำกับภาษี', 'เลขที่ใบกำกับภาษี',
            'รหัสลูกหนี้', 'ส่วนลดท้ายบิล', 'รหัสสาขา', 'รหัสแผนก', 'รหัสโครงการ',
            'รหัสพนักงานขาย', 'หมายเหตุ', 'รหัสซื้อขาย', 'จำนวน', 'แถม', 'ราคาต่อหน่วย',
            'ส่วนลดต่อหน่วย', 'น้ำหนักต่อหน่วย', 'เลขล็อต', 'เลขกำกับ', 'ตำแหน่งเก็บ',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $rowNumber = 2;
        $reviewRows = [];
        foreach ($bills as $bill) {
            $saleDepartment = trim((string) ($bill->creator?->department ?? ''));
            $effectiveBillDiscount = (float) ($bill->admin_discount_final ?? 0) > 0
                ? (float) $bill->admin_discount_final
                : (float) ($bill->sale_discount_total ?? 0);
            foreach ($bill->items as $item) {
                $effectiveItemDiscount = (float) ($item->admin_disocount_unit ?? 0) > 0
                    ? (float) $item->admin_disocount_unit
                    : (float) ($item->sale_discount_unit ?? 0);
                $sheet->fromArray([
                    $bill->bill_date?->format('Y-m-d'), $bill->bill_id, '',
                    '', $bill->bplus_customer_code, '',
                    $saleDepartment, $saleDepartment, $saleDepartment, $bill->bplus_salesman_code,
                    '', $item->main_category, $item->quantity, $item->admin_free_qty,
                    $item->unit_price, $effectiveItemDiscount, '',
                    '', '', '',
                ], null, 'A'.$rowNumber++);
                $reviewRows[] = [
                    $bill->bill_id,
                    $bill->bplus_sale_type_code,
                    $item->main_category,
                    $bill->sale_discount_total,
                    $bill->admin_discount_final,
                    $effectiveBillDiscount,
                    $item->sale_discount_unit,
                    $item->admin_disocount_unit,
                    $effectiveItemDiscount,
                ];
            }
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        foreach (range('A', 'T') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $review = $spreadsheet->createSheet();
        $review->setTitle('ข้อมูลตรวจสอบ');
        $review->fromArray([
            'Bill ID', 'ประเภทการขาย', 'SKU', 'ส่วนลดท้ายบิล Sale', 'ส่วนลดท้ายบิล Admin',
            'ส่วนลดท้ายบิลที่ส่ง Bplus', 'ส่วนลดรายการ Sale', 'ส่วนลดรายการ Admin',
            'ส่วนลดรายการที่ส่ง Bplus',
        ], null, 'A1');
        if ($reviewRows !== []) {
            $review->fromArray($reviewRows, null, 'A2');
        }
        $review->freezePane('A2');
        $review->setAutoFilter($review->calculateWorksheetDimension());
        foreach (range('A', 'I') as $column) {
            $review->getColumnDimension($column)->setAutoSize(true);
        }
        $spreadsheet->setActiveSheetIndex(0);

        return $this->xlsxBytes($spreadsheet, 'bplus-');
    }

    /**
     * @param  Collection<int, BillingRecord>  $bills
     * @param  array{date_from?: ?string, date_to?: ?string, generated_by?: ?string}  $metadata
     */
    public function salesReportWorkbook(Collection $bills, array $metadata = []): string
    {
        $billRows = [];
        $detailRows = [];
        $statusCounts = [];
        $sales = [];
        $saleCustomers = [];
        $customers = [];
        $customerProducts = [];
        $products = [];
        $months = [];

        $accumulate = static function (array &$bucket, string $key, array $identity, array $detail): void {
            $bucket[$key] ??= [
                ...$identity,
                'bill_ids' => [],
                'customers' => [],
                'products' => [],
                'sales' => [],
                'quantity' => 0,
                'free_quantity' => 0,
                'gross' => 0.0,
                'discount' => 0.0,
                'net' => 0.0,
            ];
            $row = &$bucket[$key];
            $row['bill_ids'][(string) $detail['bill_id']] = true;
            $row['customers'][(string) $detail['buyer_name']] = true;
            $row['products'][(string) $detail['sku']] = true;
            $row['sales'][(string) $detail['sale']] = true;
            $row['quantity'] += $detail['quantity'];
            $row['free_quantity'] += $detail['free_quantity'];
            $row['gross'] += $detail['gross'];
            $row['discount'] += $detail['discount'];
            $row['net'] += $detail['net'];
        };

        foreach ($bills as $bill) {
            $status = (string) ($bill->status ?: 'ไม่ระบุ');
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $items = $bill->items->values();
            $itemNetTotal = $items->sum(fn ($item): float => $this->itemLineNet($item));
            $billDiscount = min(max((float) ($bill->sale_discount_total ?? 0), 0), $itemNetTotal);
            $adminBillDiscount = max((float) ($bill->admin_discount_final ?? 0), 0);
            $effectiveBplusBillDiscount = $adminBillDiscount > 0 ? $adminBillDiscount : $billDiscount;
            $allocatedDiscount = 0.0;
            $billGross = 0.0;
            $billLineDiscount = 0.0;
            $billQuantity = 0;

            foreach ($items as $index => $item) {
                $gross = $this->itemGross($item);
                $lineNet = $this->itemLineNet($item);
                $lineDiscount = max($gross - $lineNet, 0);
                $billDiscountShare = $index === $items->count() - 1
                    ? round($billDiscount - $allocatedDiscount, 2)
                    : round($itemNetTotal > 0 ? $billDiscount * $lineNet / $itemNetTotal : 0, 2);
                $allocatedDiscount += $billDiscountShare;
                $discount = round($lineDiscount + $billDiscountShare, 2);
                $net = round(max($lineNet - $billDiscountShare, 0), 2);
                $quantity = (int) ($item->quantity ?? 0);
                $freeQuantity = (int) ($item->admin_free_qty ?? 0);
                $adminItemDiscount = max((float) ($item->admin_disocount_unit ?? 0), 0);
                $effectiveBplusItemDiscount = $adminItemDiscount > 0 ? $adminItemDiscount : $lineDiscount;
                $sku = (string) ($item->main_category ?: 'ไม่ระบุ SKU');
                $productName = (string) ($item->sub_category ?: 'ไม่ระบุชื่อสินค้า');
                $sale = (string) ($bill->created_by ?: 'ไม่ระบุ Sale');
                $buyerName = (string) ($bill->buyer_name ?: 'ไม่ระบุลูกค้า');
                $month = $bill->bill_date?->format('Y-m') ?? 'ไม่ระบุเดือน';
                $detail = [
                    'bill_id' => (int) $bill->bill_id,
                    'buyer_name' => $buyerName,
                    'sku' => $sku,
                    'sale' => $sale,
                    'quantity' => $quantity,
                    'free_quantity' => $freeQuantity,
                    'gross' => $gross,
                    'discount' => $discount,
                    'net' => $net,
                ];

                $detailRows[] = [
                    (int) $bill->bill_id,
                    (string) ($bill->tax_invoice_no ?? ''),
                    $this->excelDate($bill->bill_date),
                    $this->excelDate($bill->issued_at ?? null, true),
                    $sale,
                    $buyerName,
                    (string) ($bill->tax_id ?? ''),
                    $status,
                    $sku,
                    $productName,
                    (string) ($item->unit_type ?? ''),
                    $quantity,
                    $freeQuantity,
                    (float) ($item->unit_price ?? 0),
                    $gross,
                    $lineDiscount,
                    $billDiscountShare,
                    $discount,
                    $net,
                    (string) ($bill->admin_branch ?? ''),
                    (string) ($bill->admin_project ?? ''),
                    (string) ($bill->bplus_sale_type_code ?? ''),
                    $adminItemDiscount,
                    $adminBillDiscount,
                    $effectiveBplusItemDiscount,
                    $effectiveBplusBillDiscount,
                ];

                $billGross += $gross;
                $billLineDiscount += $lineDiscount;
                $billQuantity += $quantity;

                if (! in_array($status, self::REPORT_SUCCESS_STATUSES, true)) {
                    continue;
                }

                $accumulate($sales, $sale, ['sale' => $sale], $detail);
                $accumulate($saleCustomers, $sale."\0".$buyerName, ['sale' => $sale, 'buyer_name' => $buyerName], $detail);
                $accumulate($customers, $buyerName, ['buyer_name' => $buyerName, 'tax_id' => (string) ($bill->tax_id ?? '')], $detail);
                $accumulate($customerProducts, $buyerName."\0".$sku, ['buyer_name' => $buyerName, 'sku' => $sku, 'product_name' => $productName], $detail);
                $accumulate($products, $sku, ['sku' => $sku, 'product_name' => $productName, 'unit_type' => (string) ($item->unit_type ?? '')], $detail);
                $accumulate($months, $month, ['month' => $month], $detail);
            }

            $billRows[] = [
                (int) $bill->bill_id,
                (string) ($bill->tax_invoice_no ?? ''),
                $this->excelDate($bill->bill_date),
                $this->excelDate($bill->issued_at ?? null, true),
                (string) ($bill->buyer_name ?? ''),
                (string) ($bill->tax_id ?? ''),
                (string) ($bill->created_by ?? ''),
                $status,
                $items->count(),
                $billQuantity,
                round($billGross, 2),
                round($billLineDiscount, 2),
                round($billDiscount, 2),
                round($billLineDiscount + $billDiscount, 2),
                (float) ($bill->total_amount ?? 0),
                $this->excelDate($bill->created_at ?? null, true),
                $this->excelDate($bill->submitted_at ?? null, true),
                (string) ($bill->bplus_sale_type_code ?? ''),
                (float) ($bill->admin_discount_final ?? 0),
            ];
        }

        $aggregateRows = static function (array $bucket, array $identityKeys, array $setKeys = []): array {
            $rows = [];
            foreach ($bucket as $record) {
                $row = [];
                foreach ($identityKeys as $key) {
                    $row[] = $record[$key] ?? '';
                }
                foreach ($setKeys as $key) {
                    $row[] = count($record[$key] ?? []);
                }
                $row[] = (int) $record['quantity'];
                $row[] = (int) $record['free_quantity'];
                $row[] = round((float) $record['gross'], 2);
                $row[] = round((float) $record['discount'], 2);
                $row[] = round((float) $record['net'], 2);
                $rows[] = $row;
            }
            usort($rows, fn (array $left, array $right): int => ($right[count($right) - 1] ?? 0) <=> ($left[count($left) - 1] ?? 0));

            return $rows;
        };

        $salesRows = $aggregateRows($sales, ['sale'], ['bill_ids', 'customers', 'products']);
        $saleCustomerRows = $aggregateRows($saleCustomers, ['sale', 'buyer_name'], ['bill_ids', 'products']);
        $customerRows = $aggregateRows($customers, ['buyer_name', 'tax_id'], ['bill_ids', 'sales', 'products']);
        $customerProductRows = $aggregateRows($customerProducts, ['buyer_name', 'sku', 'product_name'], ['bill_ids', 'sales']);
        $productRows = $aggregateRows($products, ['sku', 'product_name', 'unit_type'], ['bill_ids', 'customers', 'sales']);
        $monthRows = $aggregateRows($months, ['month'], ['bill_ids', 'customers', 'sales', 'products']);
        usort($monthRows, fn (array $left, array $right): int => strcmp((string) $left[0], (string) $right[0]));

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator((string) ($metadata['generated_by'] ?? 'Valor ERP'))
            ->setTitle('Valor ERP Sales Report')
            ->setSubject('Sales, customers, products and discounts');
        $this->buildSalesSummarySheet(
            $spreadsheet->getActiveSheet(),
            $bills,
            $detailRows,
            $salesRows,
            $customerRows,
            $productRows,
            $statusCounts,
            $metadata,
        );
        $this->addReportSheet($spreadsheet, 'ประวัติบิล', [
            'Bill ID', 'เลขใบกำกับภาษี', 'วันที่บิล', 'วันที่ออกจริง', 'ลูกค้า', 'Tax ID', 'Sale', 'สถานะ',
            'จำนวนรายการ', 'จำนวนชิ้น', 'ยอดก่อนส่วนลด', 'ส่วนลดรายการ', 'ส่วนลดท้ายบิล', 'ส่วนลดรวม',
            'ยอดสุทธิ', 'สร้างเมื่อ', 'ส่งอนุมัติเมื่อ', 'ประเภทการขาย', 'ส่วนลดท้ายบิล Admin สำหรับ Bplus',
        ], $billRows, currencyColumns: ['K', 'L', 'M', 'N', 'O', 'S'], integerColumns: ['A', 'I', 'J'], dateColumns: ['C'], dateTimeColumns: ['D', 'P', 'Q'], textColumns: ['B', 'F']);
        $this->addReportSheet($spreadsheet, 'รายละเอียดการขาย', [
            'Bill ID', 'เลขใบกำกับภาษี', 'วันที่บิล', 'วันที่ออกจริง', 'Sale', 'ลูกค้า', 'Tax ID', 'สถานะ',
            'SKU', 'สินค้า', 'หน่วย', 'จำนวนขาย', 'จำนวนแถม', 'ราคาต่อหน่วย', 'ยอดก่อนส่วนลด',
            'ส่วนลดรายการ', 'ส่วนลดท้ายบิลที่ปันส่วน', 'ส่วนลดรวม', 'ยอดสุทธิ', 'สาขา', 'โครงการ',
            'ประเภทการขาย', 'ส่วนลดรายการ Admin', 'ส่วนลดท้ายบิล Admin',
            'ส่วนลดรายการที่ส่ง Bplus', 'ส่วนลดท้ายบิลที่ส่ง Bplus',
        ], $detailRows, currencyColumns: ['N', 'O', 'P', 'Q', 'R', 'S', 'W', 'X', 'Y', 'Z'], integerColumns: ['A', 'L', 'M'], dateColumns: ['C'], dateTimeColumns: ['D'], textColumns: ['B', 'G', 'I']);
        $this->addReportSheet($spreadsheet, 'ยอดขายตาม Sale', [
            'Sale', 'จำนวนบิล', 'จำนวนลูกค้า', 'จำนวน SKU', 'จำนวนขาย', 'จำนวนแถม',
            'ยอดก่อนส่วนลด', 'ส่วนลดรวม', 'ยอดขายสุทธิ',
        ], $salesRows, currencyColumns: ['G', 'H', 'I'], integerColumns: ['B', 'C', 'D', 'E', 'F']);
        $this->addReportSheet($spreadsheet, 'Sale x ลูกค้า', [
            'Sale', 'ลูกค้า', 'จำนวนบิล', 'จำนวน SKU', 'จำนวนขาย', 'จำนวนแถม',
            'ยอดก่อนส่วนลด', 'ส่วนลดรวม', 'ยอดขายสุทธิ',
        ], $saleCustomerRows, currencyColumns: ['G', 'H', 'I'], integerColumns: ['C', 'D', 'E', 'F']);
        $this->addReportSheet($spreadsheet, 'ลูกค้า', [
            'ลูกค้า', 'Tax ID', 'จำนวนบิล', 'จำนวน Sale', 'จำนวน SKU', 'จำนวนขาย', 'จำนวนแถม',
            'ยอดก่อนส่วนลด', 'ส่วนลดรวม', 'ยอดซื้อสุทธิ',
        ], $customerRows, currencyColumns: ['H', 'I', 'J'], integerColumns: ['C', 'D', 'E', 'F', 'G'], textColumns: ['B']);
        $this->addReportSheet($spreadsheet, 'ลูกค้า x สินค้า', [
            'ลูกค้า', 'SKU', 'สินค้า', 'จำนวนบิล', 'จำนวน Sale', 'จำนวนขาย', 'จำนวนแถม',
            'ยอดก่อนส่วนลด', 'ส่วนลดรวม', 'ยอดซื้อสุทธิ',
        ], $customerProductRows, currencyColumns: ['H', 'I', 'J'], integerColumns: ['D', 'E', 'F', 'G'], textColumns: ['B']);
        $this->addReportSheet($spreadsheet, 'สินค้า', [
            'SKU', 'สินค้า', 'หน่วย', 'จำนวนบิล', 'จำนวนลูกค้า', 'จำนวน Sale', 'จำนวนขาย', 'จำนวนแถม',
            'ยอดก่อนส่วนลด', 'ส่วนลดรวม', 'ยอดขายสุทธิ',
        ], $productRows, currencyColumns: ['I', 'J', 'K'], integerColumns: ['D', 'E', 'F', 'G', 'H'], textColumns: ['A']);
        $this->addReportSheet($spreadsheet, 'รายเดือน', [
            'เดือน', 'จำนวนบิล', 'จำนวนลูกค้า', 'จำนวน Sale', 'จำนวน SKU', 'จำนวนขาย', 'จำนวนแถม',
            'ยอดก่อนส่วนลด', 'ส่วนลดรวม', 'ยอดขายสุทธิ',
        ], $monthRows, currencyColumns: ['H', 'I', 'J'], integerColumns: ['B', 'C', 'D', 'E', 'F', 'G']);

        $spreadsheet->setActiveSheetIndex(0);

        return $this->xlsxBytes($spreadsheet, 'sales-report-');
    }

    /** @param array<int, array<int, mixed>> $detailRows @param array<int, array<int, mixed>> $salesRows @param array<int, array<int, mixed>> $customerRows @param array<int, array<int, mixed>> $productRows @param array<string, int> $statusCounts */
    private function buildSalesSummarySheet(
        Worksheet $sheet,
        Collection $bills,
        array $detailRows,
        array $salesRows,
        array $customerRows,
        array $productRows,
        array $statusCounts,
        array $metadata,
    ): void {
        $sheet->setTitle('ภาพรวม');
        $sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:M1');
        $sheet->setCellValue('A1', 'VALOR ERP — รายงานการซื้อขาย');
        $sheet->mergeCells('A2:M2');
        $sheet->setCellValue('A2', sprintf(
            'ช่วงวันที่ %s ถึง %s | สร้างเมื่อ %s | โดย %s',
            $metadata['date_from'] ?? 'ทั้งหมด',
            $metadata['date_to'] ?? 'ปัจจุบัน',
            now()->format('Y-m-d H:i'),
            $metadata['generated_by'] ?? '-',
        ));

        $successfulDetails = array_values(array_filter(
            $detailRows,
            fn (array $row): bool => in_array($row[7], self::REPORT_SUCCESS_STATUSES, true),
        ));
        $successfulBillIds = array_unique(array_column($successfulDetails, 0));
        $gross = array_sum(array_column($successfulDetails, 14));
        $discount = array_sum(array_column($successfulDetails, 17));
        $net = array_sum(array_column($successfulDetails, 18));
        $quantity = array_sum(array_column($successfulDetails, 11));

        $sheet->fromArray([
            ['บิลทั้งหมด', $bills->count(), 'บิลยอดขายสำเร็จ', count($successfulBillIds), 'ลูกค้าที่ซื้อ', count($customerRows), 'จำนวนสินค้าที่ขาย', $quantity],
            ['ยอดก่อนส่วนลด', $gross, 'ส่วนลดทั้งหมด', $discount, 'ยอดขายสุทธิ', $net, 'อัตราส่วนลด', null],
        ], null, 'A4');
        $sheet->setCellValue('H5', '=IF(B5=0,0,D5/B5)');
        $sheet->getStyle('B5')->getNumberFormat()->setFormatCode('#,##0.00;[Red](#,##0.00);-');
        $sheet->getStyle('D5')->getNumberFormat()->setFormatCode('#,##0.00;[Red](#,##0.00);-');
        $sheet->getStyle('F5')->getNumberFormat()->setFormatCode('#,##0.00;[Red](#,##0.00);-');
        $sheet->getStyle('H5')->getNumberFormat()->setFormatCode('0.0%');

        $sheet->setCellValue('A8', 'ยอดขายตาม Sale (Top 10)');
        $sheet->fromArray(['Sale', 'จำนวนบิล', 'จำนวนลูกค้า', 'จำนวนขาย', 'ส่วนลดรวม', 'ยอดขายสุทธิ'], null, 'A9');
        foreach (array_slice($salesRows, 0, 10) as $index => $row) {
            $sheet->fromArray([$row[0], $row[1], $row[2], $row[4], $row[7], $row[8]], null, 'A'.($index + 10));
        }

        $sheet->setCellValue('H8', 'ลูกค้าที่ซื้อสูงสุด (Top 10)');
        $sheet->fromArray(['ลูกค้า', 'จำนวนบิล', 'จำนวน SKU', 'จำนวนขาย', 'ส่วนลดรวม', 'ยอดซื้อสุทธิ'], null, 'H9');
        foreach (array_slice($customerRows, 0, 10) as $index => $row) {
            $sheet->fromArray([$row[0], $row[2], $row[4], $row[5], $row[8], $row[9]], null, 'H'.($index + 10));
        }

        $sheet->setCellValue('A22', 'สินค้าขายดี (Top 10)');
        $sheet->fromArray(['SKU', 'สินค้า', 'จำนวนขาย', 'จำนวนลูกค้า', 'ส่วนลดรวม', 'ยอดขายสุทธิ'], null, 'A23');
        foreach (array_slice($productRows, 0, 10) as $index => $row) {
            $targetRow = $index + 24;
            $sheet->fromArray([$row[0], $row[1], $row[6], $row[4], $row[9], $row[10]], null, 'A'.$targetRow);
            $sheet->setCellValueExplicit('A'.$targetRow, (string) $row[0], DataType::TYPE_STRING);
        }

        $sheet->setCellValue('H22', 'จำนวนบิลตามสถานะ');
        $sheet->fromArray(['สถานะ', 'จำนวนบิล'], null, 'H23');
        arsort($statusCounts);
        $statusRow = 24;
        foreach ($statusCounts as $status => $count) {
            $sheet->fromArray([$status, $count], null, 'H'.$statusRow++);
        }

        $sheet->getStyle('A1:M1')->applyFromArray([
            'font' => ['name' => 'Tahoma', 'bold' => true, 'size' => 18, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B82032']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);
        $sheet->getStyle('A2:M2')->applyFromArray([
            'font' => ['name' => 'Tahoma', 'size' => 9, 'color' => ['rgb' => '5D6573']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F4F5F7']],
        ]);
        $sheet->getStyle('A4:H5')->applyFromArray([
            'font' => ['name' => 'Tahoma'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF4F5']],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E5EA']]],
        ]);
        foreach (['A8:F8', 'H8:M8', 'A22:F22', 'H22:I22'] as $range) {
            $sheet->getStyle($range)->applyFromArray([
                'font' => ['name' => 'Tahoma', 'bold' => true, 'size' => 12, 'color' => ['rgb' => '172033']],
            ]);
        }
        foreach (['A9:F9', 'H9:M9', 'A23:F23', 'H23:I23'] as $range) {
            $sheet->getStyle($range)->applyFromArray($this->reportHeaderStyle());
        }
        foreach (['E10:F19', 'L10:M19', 'E24:F33'] as $range) {
            $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0.00;[Red](#,##0.00);-');
        }
        foreach (range('A', 'M') as $column) {
            $sheet->getColumnDimension($column)->setWidth($column === 'B' ? 38 : (in_array($column, ['H', 'I'], true) ? 28 : 16));
        }
        $sheet->freezePane('A4');
    }

    /** @param array<int, string> $headers @param array<int, array<int, mixed>> $rows @param array<int, string> $currencyColumns @param array<int, string> $integerColumns @param array<int, string> $dateColumns @param array<int, string> $dateTimeColumns @param array<int, string> $textColumns */
    private function addReportSheet(
        Spreadsheet $spreadsheet,
        string $title,
        array $headers,
        array $rows,
        array $currencyColumns = [],
        array $integerColumns = [],
        array $dateColumns = [],
        array $dateTimeColumns = [],
        array $textColumns = [],
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $sheet->fromArray($headers, null, 'A1');
        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
        }
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $lastRow = max(count($rows) + 1, 2);
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray($this->reportHeaderStyle());
        $sheet->getStyle("A2:{$lastColumn}{$lastRow}")->applyFromArray([
            'font' => ['name' => 'Tahoma', 'size' => 9],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E8EAED']]],
        ]);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getAlignment()->setWrapText(false);
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
        $sheet->freezePane('A2');
        foreach (range(1, count($headers)) as $columnIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
        }
        foreach ($currencyColumns as $column) {
            $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00;[Red](#,##0.00);-');
        }
        foreach ($integerColumns as $column) {
            $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        }
        foreach ($dateColumns as $column) {
            $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        }
        foreach ($dateTimeColumns as $column) {
            $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');
        }
        foreach ($textColumns as $column) {
            $textStyle = $sheet->getStyle("{$column}2:{$column}{$lastRow}");
            $textStyle->getNumberFormat()->setFormatCode('@');
            $textStyle->setQuotePrefix(true);
            for ($row = 2; $row <= $lastRow; $row++) {
                $cell = $sheet->getCell($column.$row);
                $value = $cell->getValue();
                if ($value !== null && $value !== '') {
                    $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function reportHeaderStyle(): array
    {
        return [
            'font' => ['name' => 'Tahoma', 'bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B82032']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '7A1422']]],
        ];
    }

    private function itemGross(mixed $item): float
    {
        return round((float) ($item->line_total_before_discount ?? ((int) ($item->quantity ?? 0) * (float) ($item->unit_price ?? 0))), 2);
    }

    private function itemLineNet(mixed $item): float
    {
        return round((float) ($item->line_total ?? max($this->itemGross($item) - (float) ($item->sale_discount_unit ?? 0), 0)), 2);
    }

    private function excelDate(mixed $value, bool $withTime = false): float|string
    {
        if (! $value) {
            return '';
        }

        try {
            $date = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value);

            return ExcelDate::PHPToExcel($date);
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function xlsxBytes(Spreadsheet $spreadsheet, string $prefix): string
    {
        $directory = storage_path('app/private/exports');
        File::ensureDirectoryExists($directory);
        $path = tempnam($directory, $prefix);

        if ($path === false) {
            $spreadsheet->disconnectWorksheets();

            throw new RuntimeException('Unable to create a temporary workbook.');
        }

        try {
            (new Xlsx($spreadsheet))->save($path);
            $bytes = file_get_contents($path);

            if ($bytes === false) {
                throw new RuntimeException('Unable to read the generated workbook.');
            }

            return $bytes;
        } finally {
            $spreadsheet->disconnectWorksheets();
            File::delete($path);
        }
    }

    private function documentNumber(BillingRecord $bill): string
    {
        if ($bill->tax_invoice_no) {
            return (string) $bill->tax_invoice_no;
        }

        return DB::transaction(function () use ($bill): string {
            $lockedBill = BillingRecord::query()->lockForUpdate()->findOrFail($bill->bill_id);
            if ($lockedBill->tax_invoice_no) {
                return (string) $lockedBill->tax_invoice_no;
            }

            $prefix = $lockedBill->bill_date?->format('ym') ?? now()->format('ym');
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["tax-invoice-{$prefix}"]);
            $latest = BillingRecord::query()
                ->where('tax_invoice_no', 'like', "VHB{$prefix}%")
                ->lockForUpdate()
                ->pluck('tax_invoice_no')
                ->map(fn ($number) => (int) substr((string) $number, -5))
                ->max() ?? 0;
            $number = 'VHB'.$prefix.str_pad((string) ($latest + 1), 5, '0', STR_PAD_LEFT);
            $lockedBill->forceFill(['tax_invoice_no' => $number, 'issued_at' => now()])->save();
            $bill->setAttribute('tax_invoice_no', $number);
            $bill->setAttribute('issued_at', $lockedBill->issued_at);

            return $number;
        });
    }
}
