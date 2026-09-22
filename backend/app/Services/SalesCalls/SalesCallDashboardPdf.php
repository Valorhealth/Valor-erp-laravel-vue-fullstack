<?php

namespace App\Services\SalesCalls;

use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

final class SalesCallDashboardPdf
{
    private const COLORS = ['#22c55e', '#ef4444', '#94a3b8', '#2563eb', '#7c3aed', '#f59e0b', '#06b6d4', '#db2777', '#65a30d', '#ea580c', '#475569', '#c92d3e'];

    /** @param array<string, mixed> $dashboard @param array<string, mixed> $filters */
    public function render(array $dashboard, User $actor, array $filters): string
    {
        $fontPath = resource_path('assets/fonts/THSarabunNew.ttf');
        $fontUrl = File::exists($fontPath) ? $this->fileUrl($fontPath) : '';
        $filterText = $this->filterSummary($filters, $dashboard);
        $metrics = $dashboard['summary'];
        $html = '<html><head><meta charset="utf-8"><style>'.$this->styles($fontUrl).'</style></head><body>'
            .'<div class="footer">VALOR ERP - Call Sale Dashboard - ข้อมูลตามตัวกรองที่เลือก</div>'
            .$this->header('CALL SALE DASHBOARD', $actor, $filterText)
            .$this->metrics($metrics)
            .'<table class="chart-grid"><tr><td><h2>Call Outcome</h2><img class="chart" src="'.$this->outcomeDonut($dashboard).'">'.$this->outcomeLegend($dashboard).'</td><td><h2>Total Call x Success Rate</h2><img class="chart" src="'.$this->sellerBubbles($dashboard).'">'.$this->sellerLegend($dashboard).'<div class="hint">แกนนอน = Total Call, แกนตั้ง = Success Rate</div></td></tr></table>'
            .'<h2 class="section-title">ผลรวมแยกตามแผนกของ Sale</h2>'.$this->sellerDepartmentTable($dashboard)
            .'<div class="page-break"></div>'.$this->header('DAILY CALL TREND', $actor, $filterText)
            .'<h2 class="section-title">แนวโน้ม Total Call รายวัน</h2><img class="chart-trend" src="'.$this->trend($dashboard).'">'
            .'<div class="page-break"></div>'.$this->header('PRODUCT & SPECIALTY DASHBOARD', $actor, $filterText)
            .'<table class="chart-grid"><tr><td><h2>Product Treemap</h2><img class="chart" src="'.$this->productTreemap($dashboard).'">'.$this->productLegend($dashboard).'</td><td><h2>Specialty Distribution</h2><img class="chart" src="'.$this->specialtyDonut($dashboard).'">'.$this->specialtyLegend($dashboard).'</td></tr></table>'
            .'<h2 class="section-title">Call Objective x Outcome</h2><img class="chart-wide" src="'.$this->objectives($dashboard).'">'
            .'<div class="page-break"></div>'.$this->header('SALE TOTAL SUMMARY', $actor, $filterText)
            .'<h2 class="section-title">ผลรวมของ Sale แต่ละคน</h2>'.$this->sellerTable($dashboard)
            .'<h2 class="section-title spaced">Performance Matrix - Sale x Place of Work</h2>'.$this->performanceTable($dashboard)
            .'<div class="page-break"></div>'.$this->header('HOSPITAL PURCHASE RANKING', $actor, $filterText)
            .'<h2 class="section-title">อันดับโรงพยาบาลที่ซื้อสินค้า (อิง Success Call)</h2>'.$this->hospitalPurchaseTable($dashboard)
            .'<h2 class="section-title spaced">PRODUCT x CUSTOMER</h2>'
            .'<div class="hint">แสดงสูงสุด 50 รายการตามตัวกรอง รายละเอียดทั้งหมดอยู่ใน Export Excel</div>'.$this->productCustomerTable($dashboard)
            .'</body></html>';

        $directory = storage_path('app/private/dompdf');
        $fontDirectory = $directory.DIRECTORY_SEPARATOR.'fonts';
        File::ensureDirectoryExists($fontDirectory);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', dirname(base_path()));
        $options->setTempDir($directory);
        $options->setFontDir($fontDirectory);
        $options->setFontCache($fontDirectory);
        $options->setIsFontSubsettingEnabled(false);
        $dompdf = new Dompdf($options);
        if ($fontUrl !== '') {
            $metrics = $dompdf->getFontMetrics();
            $metrics->registerFont(['family' => 'THSarabun', 'weight' => 'normal', 'style' => 'normal'], $fontUrl);
            $metrics->registerFont(['family' => 'THSarabun', 'weight' => 'bold', 'style' => 'normal'], $fontUrl);
        }
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    private function styles(string $fontUrl): string
    {
        $font = $fontUrl === '' ? '' : "@font-face{font-family:THSarabun;src:url('{$fontUrl}') format('truetype');font-weight:normal}@font-face{font-family:THSarabun;src:url('{$fontUrl}') format('truetype');font-weight:bold}";

        return $font.'@page{margin:20px 24px 27px}*{box-sizing:border-box}body{font-family:THSarabun,DejaVu Sans,sans-serif;color:#172033;font-size:15px;margin:0}h1{color:#b82032;margin:0;font-size:27px}h2{font-size:19px;margin:0 0 6px}.meta{color:#667085;font-size:13px}.filter{margin-top:6px;padding:5px 9px;background:#f3f5f8;border-left:4px solid #b82032;color:#475467}.metrics{width:100%;margin:8px 0 10px;border-spacing:5px}.metrics td{background:#fff1f3;border:1px solid #f1c8ce;padding:7px 9px;width:25%;font-size:13px}.metrics b{font-size:21px;color:#9d1d2d}.chart-grid{width:100%;border-spacing:7px;margin:0 -7px}.chart-grid>tbody>tr>td{vertical-align:top;width:50%;border:1px solid #e2e6ec;padding:8px}.chart{width:100%;height:165px;display:block}.chart-wide{width:100%;height:170px;display:block;border:1px solid #e2e6ec;padding:6px}.chart-trend{width:100%;height:310px;display:block;border:1px solid #e2e6ec;padding:9px;margin-top:12px}.section-title{border-left:5px solid #b82032;padding-left:8px;margin:7px 0}.spaced{margin-top:14px}.legend{width:100%;border-collapse:collapse;font-size:11px}.legend td{padding:2px 4px;border-bottom:1px solid #eceff3}.seller-legend,.product-legend{width:100%;border-collapse:collapse;font-size:9px;margin-top:2px}.seller-legend td,.product-legend td{width:50%;padding:2px 4px;border-bottom:1px solid #eef1f5;vertical-align:top}.seller-legend b,.product-legend b{color:#172033}.seller-legend small,.product-legend small{color:#667085}.swatch{display:inline-block;width:9px;height:9px;border-radius:5px;margin-right:5px}.hint{font-size:12px;color:#667085}.page-break{page-break-before:always}.footer{position:fixed;bottom:-19px;left:0;right:0;text-align:center;color:#98a2b3;font-size:10px}.data{width:100%;border-collapse:collapse;font-size:12px;page-break-inside:auto}.data tr{page-break-inside:avoid}.data th{background:#b82032;color:#fff;white-space:nowrap}.data th,.data td{padding:4px 6px;border:1px solid #d9dde3;text-align:left}.data tbody tr:nth-child(even){background:#f8fafc}.good{color:#15803d;font-weight:bold}.bad{color:#b91c1c;font-weight:bold}';
    }

    private function header(string $title, User $actor, string $filterText): string
    {
        return '<h1>VALOR ERP - '.e($title).'</h1><div class="meta">สร้างเมื่อ '.now()->format('Y-m-d H:i').' โดย '.e($actor->username).'</div><div class="filter"><b>ตัวกรอง:</b> '.$filterText.'</div>';
    }

    /** @param array<string, mixed> $summary */
    private function metrics(array $summary): string
    {
        return '<table class="metrics"><tr><td>Total Call<br><b>'.number_format($summary['total_activities']).'</b></td><td>Success Call<br><b>'.number_format($summary['success_calls']).'</b></td><td>Failure Call<br><b>'.number_format($summary['failure_calls']).'</b></td><td>No Call<br><b>'.number_format($summary['no_calls']).'</b></td></tr><tr><td>Success Rate<br><b>'.number_format($summary['conversion_rate'], 2).'%</b></td><td>Working Day<br><b>'.number_format($summary['working_days']).'</b></td><td>Call / Working Day<br><b>'.number_format($summary['calls_per_working_day'], 2).'</b></td><td>Customers Called<br><b>'.number_format($summary['active_customers']).'</b></td></tr></table>';
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $dashboard */
    private function filterSummary(array $filters, array $dashboard): string
    {
        $parts = [];
        if ($filters['date_from'] ?? null) {
            $parts[] = 'วันที่ '.e($filters['date_from']).' ถึง '.e($filters['date_to'] ?? $filters['date_from']);
        }
        if (($filters['seller_ids'] ?? []) !== []) {
            $parts[] = 'Sale: '.e(collect($dashboard['by_seller'])->pluck('seller_username')->implode(', ') ?: 'ไม่พบข้อมูล');
        }
        if (($filters['seller_departments'] ?? []) !== []) {
            $parts[] = 'Sale Department: '.e(implode(', ', $filters['seller_departments']));
        }
        foreach (['products' => 'Product', 'outcomes' => 'Outcome', 'statuses' => 'Status'] as $key => $label) {
            if (($filters[$key] ?? []) !== []) {
                $parts[] = $label.': '.e(implode(', ', $filters[$key]));
            }
        }
        if ($filters['customer'] ?? null) {
            $parts[] = 'Customer: '.e($filters['customer']);
        }

        return $parts === [] ? 'ข้อมูลทั้งหมด' : implode(' | ', $parts);
    }

    /** @param array<string, mixed> $dashboard */
    private function outcomeDonut(array $dashboard): string
    {
        $outcomes = collect($dashboard['by_outcome']);
        $valueFor = fn (string $label): int => (int) ($outcomes->firstWhere('label', $label)?->total ?? 0);
        $values = [
            ['label' => 'Success', 'total' => $valueFor('Success')],
            ['label' => 'Failure + No Call', 'total' => $valueFor('Failure') + $valueFor('No Call')],
        ];

        return $this->donut($values, ['#22c55e', '#ef4444'], 'Contact Records');
    }

    /** @param array<string, mixed> $dashboard */
    private function specialtyDonut(array $dashboard): string
    {
        $values = collect($dashboard['by_specialty'])->take(10)->map(fn ($row): array => ['label' => $row->label, 'total' => (int) $row->total])->all();

        return $this->donut($values, self::COLORS, 'Total Call', (int) $dashboard['summary']['total_activities']);
    }

    /** @param array<int, array{label: string, total: int}> $values @param array<int, string> $colors */
    private function donut(array $values, array $colors, string $label, ?int $displayTotal = null): string
    {
        $sum = array_sum(array_column($values, 'total'));
        if ($sum === 0) {
            return $this->emptyChart('No data');
        }
        $startAngle = -90.0;
        $segments = '';
        foreach ($values as $index => $value) {
            if ($value['total'] <= 0) {
                continue;
            }
            $sweep = 360.0 * $value['total'] / $sum;
            $segments .= $this->donutSegment($startAngle, $sweep, $colors[$index % count($colors)]);
            $startAngle += $sweep;
        }

        return $this->svg('<svg xmlns="http://www.w3.org/2000/svg" width="520" height="165" viewBox="0 0 520 165"><rect width="520" height="165" fill="#fff"/>'.$segments.'<circle cx="90" cy="78" r="35" fill="#fff"/><text x="90" y="75" text-anchor="middle" font-family="Arial" font-size="20" font-weight="700" fill="#172033">'.number_format($displayTotal ?? $sum).'</text><text x="90" y="94" text-anchor="middle" font-family="Arial" font-size="10" fill="#667085">'.e($label).'</text></svg>');
    }

    private function donutSegment(float $startAngle, float $sweep, string $color): string
    {
        $steps = max(2, (int) ceil($sweep / 6));
        $points = [];
        for ($step = 0; $step <= $steps; $step++) {
            $angle = deg2rad($startAngle + ($sweep * $step / $steps));
            $points[] = sprintf('%.2f,%.2f', 90 + 50 * cos($angle), 78 + 50 * sin($angle));
        }
        for ($step = $steps; $step >= 0; $step--) {
            $angle = deg2rad($startAngle + ($sweep * $step / $steps));
            $points[] = sprintf('%.2f,%.2f', 90 + 35 * cos($angle), 78 + 35 * sin($angle));
        }

        return '<polygon points="'.implode(' ', $points).'" fill="'.$color.'" stroke="#ffffff" stroke-width="1"/>';
    }

    /** @param array<string, mixed> $dashboard */
    private function sellerBubbles(array $dashboard): string
    {
        $rows = collect($dashboard['by_seller']);
        if ($rows->isEmpty()) {
            return $this->emptyChart('No seller data');
        }
        $max = max((int) $rows->max('total'), 1);
        $grandTotal = max((int) $dashboard['summary']['total_activities'], 1);
        $grid = '';
        foreach ([0, 25, 50, 75, 100] as $tick) {
            $y = 125 - ($tick / 100 * 105);
            $x = 42 + ($tick / 100 * 463);
            $callTick = (int) round($max * $tick / 100);
            $grid .= sprintf('<line x1="42" y1="%.1f" x2="505" y2="%.1f" stroke="#e5e7eb"/><text x="36" y="%.1f" text-anchor="end" font-family="Arial" font-size="7" fill="#667085">%d%%</text>', $y, $y, $y + 2.5, $tick);
            $grid .= sprintf('<line x1="%.1f" y1="20" x2="%.1f" y2="125" stroke="#eef1f5"/><text x="%.1f" y="139" text-anchor="middle" font-family="Arial" font-size="7" fill="#667085">%d</text>', $x, $x, $x, $callTick);
        }
        $marks = '';
        foreach ($rows as $index => $row) {
            $x = 42 + ((int) $row->total / $max * 463);
            $y = 125 - ((float) $row->success_rate / 100 * 105);
            $radius = 7 + min(sqrt(max((int) $row->active_customers, 1)) * 2, 10);
            $marks .= sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="%s" fill-opacity=".82" stroke="#fff" stroke-width="2"/><text x="%.1f" y="%.1f" text-anchor="middle" font-family="Arial" font-size="7" font-weight="700">%s</text><text x="%.1f" y="%.1f" text-anchor="middle" font-family="Arial" font-size="6">%s / %s%%</text>', $x, $y, $radius, self::COLORS[$index % count(self::COLORS)], $x, $y - 1, e($row->seller_username), $x, $y + 7, number_format($row->total), number_format($row->success_rate, 1));
        }

        return $this->svg('<svg xmlns="http://www.w3.org/2000/svg" width="520" height="165" viewBox="0 0 520 165"><rect width="520" height="165" fill="#fff"/>'.$grid.'<line x1="42" y1="125" x2="505" y2="125" stroke="#94a3b8"/><line x1="42" y1="20" x2="42" y2="125" stroke="#94a3b8"/><text x="273" y="154" text-anchor="middle" font-family="Arial" font-size="8" font-weight="700" fill="#475569">Total Call</text><text x="9" y="75" text-anchor="middle" transform="rotate(-90 9 75)" font-family="Arial" font-size="8" font-weight="700" fill="#475569">Success Rate</text>'.$marks.'</svg>');
    }

    /** @param array<string, mixed> $dashboard */
    private function sellerLegend(array $dashboard): string
    {
        $cells = collect($dashboard['by_seller'])->values()->map(function ($row, int $index): string {
            $name = (string) ($row->seller_name ?? $row->seller_username);

            return '<td><i class="swatch" style="background:'.self::COLORS[$index % count(self::COLORS)].'"></i><b>'.e($name).'</b> <small>('.e($row->seller_username).') · '.number_format($row->total).' Call · '.number_format($row->success_rate, 1).'%</small></td>';
        })->all();
        $rows = collect(array_chunk($cells, 2))->map(function (array $row): string {
            if (count($row) === 1) {
                $row[] = '<td></td>';
            }

            return '<tr>'.implode('', $row).'</tr>';
        })->implode('');

        return '<table class="seller-legend">'.$rows.'</table>';
    }

    /** @param array<string, mixed> $dashboard */
    private function trend(array $dashboard): string
    {
        $rows = collect($dashboard['daily_trend']);
        if ($rows->isEmpty()) {
            return $this->emptyChart('No trend data', 1050, 200);
        }
        $max = max((int) $rows->max('total'), 1);
        $points = [];
        $marks = '';
        foreach ($rows as $index => $row) {
            $x = $rows->count() === 1 ? 520 : 35 + ($index * 980 / ($rows->count() - 1));
            $y = 155 - ((int) $row->total / $max * 120);
            $points[] = sprintf('%.1f,%.1f', $x, $y);
            $marks .= sprintf('<circle cx="%.1f" cy="%.1f" r="5" fill="#fff" stroke="#b82032" stroke-width="3"/><text x="%.1f" y="%.1f" text-anchor="middle" font-family="Arial" font-size="9" font-weight="700">%s</text><text x="%.1f" y="185" text-anchor="middle" font-family="Arial" font-size="8" fill="#667085">%s</text>', $x, $y, $x, $y - 10, number_format($row->total), $x, e(substr((string) $row->activity_date, 5, 5)));
        }
        $line = implode(' ', $points);
        $lastX = $rows->count() === 1 ? 520 : 1015;

        return $this->svg('<svg xmlns="http://www.w3.org/2000/svg" width="1050" height="200"><rect width="1050" height="200" fill="#fff"/><g stroke="#e5e7eb"><line x1="35" y1="35" x2="1015" y2="35"/><line x1="35" y1="85" x2="1015" y2="85"/><line x1="35" y1="130" x2="1015" y2="130"/><line x1="35" y1="155" x2="1015" y2="155"/></g><polygon points="35,155 '.$line.' '.$lastX.',155" fill="#b8203219"/><polyline points="'.$line.'" fill="none" stroke="#b82032" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>'.$marks.'</svg>');
    }

    /** @param array<string, mixed> $dashboard */
    private function productTreemap(array $dashboard): string
    {
        $rows = collect($dashboard['by_product'])
            ->filter(fn ($row): bool => (int) $row->total > 0)
            ->values();
        if ($rows->isEmpty()) {
            return $this->emptyChart('No product data');
        }

        $grandTotal = max((int) $dashboard['summary']['total_activities'], 1);
        $items = $rows->map(fn ($row, int $index): array => [
            'row' => $row,
            'weight' => (int) $row->total,
            'color' => self::COLORS[$index % count(self::COLORS)],
        ])->all();
        $layout = $this->treemapLayout($items, 0, 0, 520, 165);
        $clips = '';
        $rectangles = '';
        foreach ($layout as $index => $rect) {
            $x = $rect['x'] + 1.5;
            $y = $rect['y'] + 1.5;
            $width = max($rect['width'] - 3, 0);
            $height = max($rect['height'] - 3, 0);
            $row = $rect['item']['row'];
            $color = $rect['item']['color'];
            $clipId = 'product-'.$index;
            $clips .= sprintf('<clipPath id="%s"><rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="4"/></clipPath>', $clipId, $x, $y, $width, $height);
            $rectangles .= sprintf('<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="4" fill="%s"/>', $x, $y, $width, $height, $color);

            if ($width >= 34 && $height >= 18) {
                $fontSize = $width < 70 || $height < 35 ? 7 : 9;
                $rectangles .= sprintf('<g clip-path="url(#%s)"><text x="%.2f" y="%.2f" font-family="Arial" font-size="%d" font-weight="700" fill="#fff">%s</text>', $clipId, $x + 5, $y + 14, $fontSize, e($row->label));
                if ($width >= 82 && $height >= 42) {
                    $rectangles .= sprintf('<text x="%.2f" y="%.2f" font-family="Arial" font-size="7" fill="#fff">%s calls / %s%% share</text>', $x + 5, $y + 28, number_format($row->total), number_format((int) $row->total / $grandTotal * 100, 1));
                }
                if ($width >= 105 && $height >= 58) {
                    $successCalls = (int) ($row->success_calls ?? round((int) $row->total * (float) $row->success_rate / 100));
                    $rectangles .= sprintf('<text x="%.2f" y="%.2f" font-family="Arial" font-size="7" fill="#fff">Success %s (%s%%)</text>', $x + 5, $y + 41, number_format($successCalls), number_format($row->success_rate, 1));
                }
                $rectangles .= '</g>';
            }
        }

        return $this->svg('<svg xmlns="http://www.w3.org/2000/svg" width="520" height="165" viewBox="0 0 520 165"><rect width="520" height="165" fill="#fff"/><defs>'.$clips.'</defs>'.$rectangles.'</svg>');
    }

    /**
     * Binary treemap: every rectangle receives an area exactly proportional
     * to its Call total while recursively splitting along the longest side.
     *
     * @param  array<int, array{row: mixed, weight: int, color: string}>  $items
     * @return array<int, array{x: float, y: float, width: float, height: float, item: array{row: mixed, weight: int, color: string}}>
     */
    private function treemapLayout(array $items, float $x, float $y, float $width, float $height): array
    {
        if (count($items) === 1) {
            return [[
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'height' => $height,
                'item' => $items[0],
            ]];
        }

        $total = max(array_sum(array_column($items, 'weight')), 1);
        $target = $total / 2;
        $running = 0;
        $splitAt = 1;
        $smallestDifference = INF;
        for ($index = 1, $count = count($items); $index < $count; $index++) {
            $running += $items[$index - 1]['weight'];
            $difference = abs($target - $running);
            if ($difference < $smallestDifference) {
                $smallestDifference = $difference;
                $splitAt = $index;
            }
        }

        $first = array_slice($items, 0, $splitAt);
        $second = array_slice($items, $splitAt);
        $firstWeight = array_sum(array_column($first, 'weight'));
        $ratio = $firstWeight / $total;

        if ($width >= $height) {
            $firstWidth = $width * $ratio;

            return [
                ...$this->treemapLayout($first, $x, $y, $firstWidth, $height),
                ...$this->treemapLayout($second, $x + $firstWidth, $y, $width - $firstWidth, $height),
            ];
        }

        $firstHeight = $height * $ratio;

        return [
            ...$this->treemapLayout($first, $x, $y, $width, $firstHeight),
            ...$this->treemapLayout($second, $x, $y + $firstHeight, $width, $height - $firstHeight),
        ];
    }

    /** @param array<string, mixed> $dashboard */
    private function objectives(array $dashboard): string
    {
        $rows = collect($dashboard['by_objective'])->take(8)->values();
        if ($rows->isEmpty()) {
            return $this->emptyChart('No objective data', 1050, 200);
        }
        $allObjectiveRows = collect($dashboard['by_objective']);
        $objectiveOutcomeTotal = max((int) $allObjectiveRows->sum(
            fn ($row): int => (int) $row->success_calls + (int) $row->failure_calls + (int) $row->no_calls
        ), 1);
        $marks = '';
        foreach ($rows as $index => $row) {
            $y = 12 + ($index * 23);
            $outcomeTotal = max((int) $row->success_calls + (int) $row->failure_calls + (int) $row->no_calls, 1);
            $success = 650 * (int) $row->success_calls / $objectiveOutcomeTotal;
            $failedOrNoCall = (int) $row->failure_calls + (int) $row->no_calls;
            $failedOrNoCallWidth = 650 * $failedOrNoCall / $objectiveOutcomeTotal;
            $marks .= sprintf('<text x="5" y="%d" font-family="Arial" font-size="10" font-weight="700">%s</text><rect x="165" y="%d" width="650" height="13" rx="6" fill="#eef1f5"/><rect x="165" y="%d" width="%.1f" height="13" fill="#22c55e"/><rect x="%.1f" y="%d" width="%.1f" height="13" fill="#ef4444"/><text x="1035" y="%d" text-anchor="end" font-family="Arial" font-size="9">รวม %s | Success %s (%s%%) | Failure + No Call %s (%s%%)</text>', $y + 10, e($row->label), $y, $y, $success, 165 + $success, $y, $failedOrNoCallWidth, $y + 10, number_format($outcomeTotal), number_format($row->success_calls), number_format((int) $row->success_calls / $outcomeTotal * 100, 1), number_format($failedOrNoCall), number_format($failedOrNoCall / $outcomeTotal * 100, 1));
        }

        return $this->svg('<svg xmlns="http://www.w3.org/2000/svg" width="1050" height="200"><rect width="1050" height="200" fill="#fff"/>'.$marks.'<circle cx="420" cy="193" r="5" fill="#22c55e"/><text x="430" y="197" font-family="Arial" font-size="9">Success</text><circle cx="515" cy="193" r="5" fill="#ef4444"/><text x="525" y="197" font-family="Arial" font-size="9">Failure + No Call</text></svg>');
    }

    /** @param array<string, mixed> $dashboard */
    private function outcomeLegend(array $dashboard): string
    {
        $outcomes = collect($dashboard['by_outcome']);
        $values = [
            ['label' => 'Success', 'color' => '#22c55e', 'total' => (int) ($outcomes->firstWhere('label', 'Success')?->total ?? 0)],
            ['label' => 'Failure + No Call', 'color' => '#ef4444', 'total' => (int) ($outcomes->firstWhere('label', 'Failure')?->total ?? 0) + (int) ($outcomes->firstWhere('label', 'No Call')?->total ?? 0)],
        ];
        $total = max(array_sum(array_column($values, 'total')), 1);

        return '<table class="legend">'.collect($values)->map(function (array $row) use ($total): string {
            return '<tr><td><i class="swatch" style="background:'.$row['color'].'"></i>'.$row['label'].'</td><td>'.number_format($row['total']).' Calls</td><td>'.number_format($row['total'] / $total * 100, 1).'%</td></tr>';
        })->implode('').'</table>';
    }

    /** @param array<string, mixed> $dashboard */
    private function specialtyLegend(array $dashboard): string
    {
        $total = max((int) $dashboard['summary']['total_activities'], 1);

        return '<table class="legend">'.collect($dashboard['by_specialty'])->take(10)->values()->map(fn ($row, int $index): string => '<tr><td><i class="swatch" style="background:'.self::COLORS[$index % count(self::COLORS)].'"></i>'.e($row->label).'</td><td>'.number_format($row->total).' Calls</td><td>'.number_format((int) $row->total / $total * 100, 1).'% share</td><td>'.number_format((float) ($row->success_rate ?? 0), 1).'% success</td></tr>')->implode('').'</table>';
    }

    /** @param array<string, mixed> $dashboard */
    private function productLegend(array $dashboard): string
    {
        $total = max((int) $dashboard['summary']['total_activities'], 1);
        $cells = collect($dashboard['by_product'])->values()->map(function ($row, int $index) use ($total): string {
            return '<td><i class="swatch" style="background:'.self::COLORS[$index % count(self::COLORS)].'"></i><b>'.e($row->label).'</b> <small>· '.number_format($row->total).' Calls · '.number_format((int) $row->total / $total * 100, 1).'% · Success '.number_format((int) ($row->success_calls ?? 0)).' ('.number_format((float) ($row->success_rate ?? 0), 1).'%)</small></td>';
        })->all();
        $rows = collect(array_chunk($cells, 2))->map(function (array $row): string {
            if (count($row) === 1) {
                $row[] = '<td></td>';
            }

            return '<tr>'.implode('', $row).'</tr>';
        })->implode('');

        return '<table class="product-legend">'.$rows.'</table>';
    }

    /** @param array<string, mixed> $dashboard */
    private function sellerTable(array $dashboard): string
    {
        $rows = collect($dashboard['by_seller'])->map(fn ($row): string => '<tr><td><b>'.e($row->seller_username).'</b></td><td>'.number_format($row->total).'</td><td class="good">'.number_format($row->success_calls).'</td><td class="bad">'.number_format($row->failure_calls).'</td><td>'.number_format($row->no_calls).'</td><td><b>'.number_format($row->success_rate, 2).'%</b></td><td>'.number_format($row->working_days).'</td><td>'.number_format($row->calls_per_working_day, 2).'</td></tr>')->implode('');

        return $this->table(['Name Sale', 'Total Call', 'Success', 'Failure', 'No Call', 'Success Rate', 'Working Day', 'Call / Day'], $rows);
    }

    /** @param array<string, mixed> $dashboard */
    private function sellerDepartmentTable(array $dashboard): string
    {
        $rows = collect($dashboard['by_seller_department'] ?? [])->map(fn ($row): string => '<tr><td><b>'.e($row->department).'</b></td><td>'.number_format($row->total).'</td><td class="good">'.number_format($row->success_calls).'</td><td class="bad">'.number_format($row->failure_calls).'</td><td>'.number_format($row->no_calls).'</td><td>'.number_format($row->success_rate, 2).'%</td><td>'.number_format($row->working_days).'</td><td>'.number_format($row->calls_per_working_day, 2).'</td></tr>')->implode('');

        return $this->table(['Sale Department', 'Total Call', 'Success', 'Failure', 'No Call', 'Success Rate', 'Working Day', 'Call / Day'], $rows);
    }

    /** @param array<string, mixed> $dashboard */
    private function performanceTable(array $dashboard): string
    {
        $rows = collect($dashboard['performance_matrix'])->map(fn ($row): string => '<tr><td>'.e($row->seller_username).'</td><td>'.e($row->place_of_work).'</td><td>'.number_format($row->total).'</td><td class="good">'.number_format($row->success_calls).'</td><td class="bad">'.number_format($row->failure_calls).'</td><td>'.number_format($row->no_calls).'</td><td>'.number_format($row->success_rate, 2).'%</td><td>'.number_format($row->working_days).'</td><td>'.number_format($row->calls_per_working_day, 2).'</td></tr>')->implode('');

        return $this->table(['Sale', 'Place of Work', 'Total', 'Success', 'Failure', 'No Call', 'Success Rate', 'Working Day', 'Call / Day'], $rows);
    }

    /** @param array<string, mixed> $dashboard */
    private function productCustomerTable(array $dashboard): string
    {
        $rows = collect($dashboard['product_customer_matrix'])->take(50)->map(fn ($row): string => '<tr><td>'.e($row->product).'</td><td>'.e($row->place_of_work).'</td><td>'.e($row->customer_name).'</td><td>'.number_format($row->total).'</td><td class="good">'.number_format($row->success_calls).'</td><td class="bad">'.number_format($row->failure_calls).'</td><td>'.number_format($row->success_rate, 2).'%</td><td>'.e((string) $row->last_call_date).'</td></tr>')->implode('');

        return $this->table(['Product', 'Place of Work', 'Customer', 'Total', 'Success', 'Failure', 'Success Rate', 'Last Call'], $rows);
    }

    /** @param array<string, mixed> $dashboard */
    private function hospitalPurchaseTable(array $dashboard): string
    {
        $rows = collect($dashboard['hospital_purchase_ranking'] ?? [])->take(20)->values()->map(function ($row, int $index): string {
            return '<tr>'
                .'<td><b>'.number_format($index + 1).'</b></td>'
                .'<td><b>'.e($row->hospital_name).'</b></td>'
                .'<td class="good">'.number_format($row->purchase_calls).'</td>'
                .'<td>'.number_format($row->products_count).'</td>'
                .'<td>'.number_format($row->contacts_count).'</td>'
                // .'<td>'.number_format((float) $row->actual_value, 2).'</td>' // Actual Value (เก็บไว้ใช้ในอนาคต)
                .'<td>'.e((string) $row->last_purchase_date).'</td>'
                .'</tr>';
        })->implode('');

        return $this->table([
            'Rank',
            'Hospital / Place of Work',
            'Success Purchase',
            'Products',
            'Contacts',
            // 'Actual Value', // เก็บไว้ใช้ในอนาคต
            'Last Purchase',
        ], $rows);
    }

    /** @param array<int, string> $headers */
    private function table(array $headers, string $rows): string
    {
        return '<table class="data"><thead><tr>'.collect($headers)->map(fn (string $header): string => '<th>'.e($header).'</th>')->implode('').'</tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    private function emptyChart(string $message, int $width = 520, int $height = 165): string
    {
        return $this->svg('<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'"><rect width="100%" height="100%" fill="#f8fafc"/><text x="50%" y="50%" text-anchor="middle" font-family="Arial" font-size="13" fill="#667085">'.e($message).'</text></svg>');
    }

    private function svg(string $svg): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private function fileUrl(string $path): string
    {
        return 'file://'.str_replace('\\', '/', $path);
    }
}
