<?php

namespace App\Services\Documents;

use App\Models\BillingRecord;
use App\Models\Quotation;
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
    private const REPORT_SUCCESS_STATUSES = ['อนุมัติแล้ว', 'เตรียมส่งออก', 'ดำเนินการเสร็จสิ้น'];

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
    ): string
    {
        $quotation->loadMissing('items');
        $draft?->loadMissing('items');
        $source = $draft ?? $quotation;
        $sourceItems = $draft?->items ?? $quotation->items;
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

        return $this->renderPdf(
            title: $revised ? 'ใบเสนอราคา' : 'ใบเสนอราคา',
            englishTitle: $revised ? 'Quotation' : 'Quotation',
            documentNo: (string) (str_replace('-', '', $quotation->qt_no) ?: 'QT'.str_pad((string) $quotation->qt_id, 5, '0', STR_PAD_LEFT)),
            date: (string) (($draft?->bill_date ?? $quotation->qt_date)?->format('d-m-Y') ?? now()->format('d-m-Y')),
            customerName: (string) $source->buyer_name,
            customerCode: (string) ($source->bplus_customer_code ?? ''),
            customerAddress: (string) ($source->address_note ?? ''),
            customerTaxId: (string) ($source->tax_id ?: '-'),
            salesperson: (string) ($source->created_by ?? ''),
            note: (string) ($source->bill_note ?? '-'),
            total: (float) $source->total_amount,
            billDiscount: (float) ($source->sale_discount_total ?? 0),
            items: $items,
            copy: $copy,
            canceled: false,
        );
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
        string $note,
        float $total,
        float $billDiscount,
        Collection $items,
        bool $copy,
        bool $canceled,
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
        foreach ($items as $item) {
            $before = (float) ($item->line_total_before_discount ?? ((float) $item->unit_price * (int) $item->quantity));
            $lineTotal = (float) ($item->line_total ?? 0);
            $discount = max($before - $lineTotal, (float) ($item->sale_discount_unit ?? 0), 0);
            $lineDiscount += $discount;
            $rows .= '<tr><td>'.e($item->main_category).'</td><td>'.e($item->sub_category).'</td><td class="num">'.number_format((float) $item->quantity).'</td><td class="num">'.number_format((float) $item->unit_price, 2).'</td><td class="num">'.number_format($discount, 2).'</td><td class="num">'.number_format($lineTotal, 2).'</td></tr>';
        }
        for ($index = $items->count(); $index < 4; $index++) {
            $rows .= '<tr class="empty"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>';
        }

        $watermark = $canceled ? 'ยกเลิกเอกสาร' : ($copy ? 'สำเนา (COPY)' : '');
        $watermarkClass = $canceled ? ' canceled' : '';
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
        </style></head><body>'.($watermark ? '<div class="watermark'.$watermarkClass.'">'.e($watermark).'</div>' : '').$logo.'
        <table class="header"><tr>
            <td class="company-cell"><div class="company header-line">บริษัท เวเลอร์ เฮลธ์ จำกัด (สำนักงานใหญ่)</div><div class="header-line">2/22 อาคารไอยรา ถ.จันทน์ แขวงทุ่งวัดดอน เขตสาทร</div><div class="header-line">จ.กรุงเทพมหานคร 10120</div><div class="header-line">โทรศัพท์ +66-655219785</div><div>เลขผู้เสียภาษี 0105558041179</div></td>
            <td class="title-cell"><div class="document-title">'.e($title).'<br><span>'.e($englishTitle).'</span></div></td>
            <td class="document-cell">สาขาที่ออกใบกำกับภาษี<br>สาขา VH000<br><br>เลขที่ '.e($documentNo).'<br>วันที่ '.e($date).'</td>
        </tr></table>
        <table class="customer"><tr>
            <td class="customer-left"><div class="customer-line"><strong>ชื่อลูกค้า:</strong> '.e($customerName).'</div><div class="customer-line"><strong>ที่อยู่:</strong> '.e($customerAddress).'</div><div><strong>เลขที่ผู้เสียภาษี:</strong> '.e($customerTaxId).'</div><div><strong>หมายเหตุ:</strong> '.e($note).'</div></td>
            <td class="customer-right"><div class="customer-line"><strong>รหัสลูกค้า:</strong> '.e($customerCode).'</div><div style="height:35px"></div><div><strong>พนักงานขาย:</strong> '.e($salesperson).'</div></td>
        </tr></table>
        <table class="items"><thead><tr><th style="width:13%"><strong>รหัสสินค้า</strong><br>Product ID</th><th style="width:35%"><strong>รายการ</strong><br>Description</th><th style="width:8%"><strong>จำนวน</strong><br>Quantity</th><th style="width:13%"><strong>ราคาต่อหน่วย</strong><br>Unit Price</th><th style="width:11%"><strong>ส่วนลด</strong><br>Discount</th><th style="width:20%"><strong>จำนวนเงิน</strong><br>Amount (Baht)</th></tr></thead><tbody>'.$rows.'</tbody></table>
        <table class="after-items"><tr><td class="after-left"><div class="words">-'.e($this->bahtText->convert($total)).'-</div><div class="received">○ ได้รับสินค้าตามรายการนี้ในสภาพสมบูรณ์และจำนวนครบถ้วน</div><div class="document-note"><strong>หมายเหตุ:</strong> เป็นการยกเลิกใบกำกับภาษีอย่างย่อ<br>และออกใบกำกับภาษีเต็มรูปแบบแทน</div></td><td class="after-right">
            <table class="summary"><tr><td class="label">มูลค่าราคารวมทั้งสิ้น (Total Amount)</td><td class="value">'.number_format($subtotal, 2).'</td></tr><tr><td class="label">รวมส่วนลดทั้งหมด (Total Discount)</td><td class="value">'.number_format($lineDiscount + $billDiscount, 2).'</td></tr><tr><td class="label">จำนวนภาษีมูลค่าเพิ่ม (VAT)</td><td class="value">'.number_format($vat, 2).'</td></tr><tr><td class="label">จำนวนเงินรวมทั้งสิ้น (Grand Total)</td><td class="value">'.number_format($total, 2).'</td></tr></table>
        </td></tr></table>
        <div class="footer">เอกสารนี้ได้จัดทำและส่งข้อมูลให้แก่กรมสรรพากรด้วยวิธีการทางอิเล็กทรอนิกส์<br>ทางบริษัทฯ ขอสงวนสิทธิ์ในการแก้ไข เปลี่ยนแปลง หรือยกเลิกเอกสารฉบับนี้ ภายใน 7 วัน นับจากวันที่ระบุในเอกสาร</div>
        </body></html>';

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
            $effectiveBillDiscount = (float) ($bill->admin_discount_final ?? 0) > 0
                ? (float) $bill->admin_discount_final
                : (float) ($bill->sale_discount_total ?? 0);
            foreach ($bill->items as $item) {
                $effectiveItemDiscount = (float) ($item->admin_disocount_unit ?? 0) > 0
                    ? (float) $item->admin_disocount_unit
                    : (float) ($item->sale_discount_unit ?? 0);
                $sheet->fromArray([
                    $bill->bill_date?->format('Y-m-d'), $bill->bill_id, $bill->bill_date?->format('Y-m-d'),
                    $bill->tax_invoice_no, $bill->bplus_customer_code, $effectiveBillDiscount,
                    $bill->admin_branch, $bill->admin_dept, $bill->admin_project, $bill->bplus_salesman_code,
                    $bill->bill_note, $item->main_category, $item->quantity, $item->admin_free_qty,
                    $item->unit_price, $effectiveItemDiscount, $item->admin_weight_unit,
                    $item->admin_lot, $item->admin_serial, $item->admin_storage,
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
