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
            .'<table class="chart-grid"><tr><td><h2>Call Outcome</h2><img class="chart" src="'.$this->outcomeDonut($dashboard).'">'.$this->outcomeLegend($dashboard).'</td><td><h2>Total Call x Success Rate</h2><img class="chart" src="'.$this->sellerBubbles($dashboard).'"><div class="hint">ขวา = Total Call มาก, สูง = Success Rate สูง</div></td></tr></table>'
            .'<div class="page-break"></div>'.$this->header('DAILY CALL TREND', $actor, $filterText)
            .'<h2 class="section-title">แนวโน้ม Total Call รายวัน</h2><img class="chart-trend" src="'.$this->trend($dashboard).'">'
            .'<div class="page-break"></div>'.$this->header('PRODUCT & SPECIALTY DASHBOARD', $actor, $filterText)
            .'<table class="chart-grid"><tr><td><h2>Product Treemap</h2><img class="chart" src="'.$this->productTreemap($dashboard).'"></td><td><h2>Specialty Distribution</h2><img class="chart" src="'.$this->specialtyDonut($dashboard).'">'.$this->specialtyLegend($dashboard).'</td></tr></table>'
            .'<h2 class="section-title">Call Objective x Outcome</h2><img class="chart-wide" src="'.$this->objectives($dashboard).'">'
            .'<div class="page-break"></div>'.$this->header('SALE TOTAL SUMMARY', $actor, $filterText)
            .'<h2 class="section-title">ผลรวมของ Sale แต่ละคน</h2>'.$this->sellerTable($dashboard)
            .'<h2 class="section-title spaced">Performance Matrix - Sale x Place of Work</h2>'.$this->performanceTable($dashboard)
            .'<div class="page-break"></div>'.$this->header('PRODUCT x CUSTOMER', $actor, $filterText)
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

        return $font.'@page{margin:20px 24px 27px}*{box-sizing:border-box}body{font-family:THSarabun,DejaVu Sans,sans-serif;color:#172033;font-size:15px;margin:0}h1{color:#b82032;margin:0;font-size:27px}h2{font-size:19px;margin:0 0 6px}.meta{color:#667085;font-size:13px}.filter{margin-top:6px;padding:5px 9px;background:#f3f5f8;border-left:4px solid #b82032;color:#475467}.metrics{width:100%;margin:8px 0 10px;border-spacing:5px}.metrics td{background:#fff1f3;border:1px solid #f1c8ce;padding:7px 9px;width:25%;font-size:13px}.metrics b{font-size:21px;color:#9d1d2d}.chart-grid{width:100%;border-spacing:7px;margin:0 -7px}.chart-grid>tbody>tr>td{vertical-align:top;width:50%;border:1px solid #e2e6ec;padding:8px}.chart{width:100%;height:165px;display:block}.chart-wide{width:100%;height:170px;display:block;border:1px solid #e2e6ec;padding:6px}.chart-trend{width:100%;height:310px;display:block;border:1px solid #e2e6ec;padding:9px;margin-top:12px}.section-title{border-left:5px solid #b82032;padding-left:8px;margin:7px 0}.spaced{margin-top:14px}.legend{width:100%;border-collapse:collapse;font-size:11px}.legend td{padding:2px 4px;border-bottom:1px solid #eceff3}.swatch{display:inline-block;width:9px;height:9px;border-radius:5px;margin-right:5px}.hint{font-size:12px;color:#667085}.page-break{page-break-before:always}.footer{position:fixed;bottom:-19px;left:0;right:0;text-align:center;color:#98a2b3;font-size:10px}.data{width:100%;border-collapse:collapse;font-size:12px;page-break-inside:auto}.data tr{page-break-inside:avoid}.data th{background:#b82032;color:#fff;white-space:nowrap}.data th,.data td{padding:4px 6px;border:1px solid #d9dde3;text-align:left}.data tbody tr:nth-child(even){background:#f8fafc}.good{color:#15803d;font-weight:bold}.bad{color:#b91c1c;font-weight:bold}';
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
        $values = collect(['Success', 'Failure', 'No Call'])->map(function (string $label) use ($dashboard): array {
            $row = collect($dashboard['by_outcome'])->firstWhere('label', $label);

            return ['label' => $label, 'total' => (int) ($row->total ?? 0)];
        })->all();

        return $this->donut($values, array_slice(self::COLORS, 0, 3), 'Total Call');
    }

    /** @param array<string, mixed> $dashboard */
    private function specialtyDonut(array $dashboard): string
    {
        $values = collect($dashboard['by_specialty'])->take(10)->map(fn ($row): array => ['label' => $row->label, 'total' => (int) $row->total])->all();

        return $this->donut($values, self::COLORS, 'Top Specialty');
    }

    /** @param array<int, array{label: string, total: int}> $values @param array<int, string> $colors */
    private function donut(array $values, array $colors, string $label): string
    {
        $sum = array_sum(array_column($values, 'total'));
        if ($sum === 0) {
            return $this->emptyChart('No data');
        }
        $circumference = 314.159;
        $offset = 0.0;
        $segments = '';
        foreach ($values as $index => $value) {
            $length = $circumference * $value['total'] / $sum;
            $segments .= sprintf('<circle cx="90" cy="78" r="50" fill="none" stroke="%s" stroke-width="25" stroke-dasharray="%.3f %.3f" stroke-dashoffset="%.3f" transform="rotate(-90 90 78)"/>', $colors[$index % count($colors)], $length, $circumference - $length, -$offset);
            $offset += $length;
        }

        return $this->svg('<svg xmlns="http://www.w3.org/2000/svg" width="520" height="165" viewBox="0 0 520 165"><rect width="520" height="165" fill="#fff"/>'.$segments.'<circle cx="90" cy="78" r="35" fill="#fff"/><text x="90" y="75" text-anchor="middle" font-family="Arial" font-size="20" font-weight="700" fill="#172033">'.number_format($sum).'</text><text x="90" y="94" text-anchor="middle" font-family="Arial" font-size="10" fill="#667085">'.e($label).'</text></svg>');
    }

    /** @param array<string, mixed> $dashboard */
    private function sellerBubbles(array $dashboard): string
    {
        $rows = collect($dashboard['by_seller']);
        if ($rows->isEmpty()) {
            return $this->emptyChart('No seller data');
        }
        $max = max((int) $rows->max('total'), 1);
        $marks = '';
        foreach ($rows as $index => $row) {
            $x = 45 + ((int) $row->total / $max * 430);
            $y = 150 - ((float) $row->success_rate * 1.12);
            $radius = 10 + min(sqrt(max((int) $row->active_customers, 1)) * 3, 15);
            $marks .= sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="%s" fill-opacity=".82" stroke="#fff" stroke-width="2"/><text x="%.1f" y="%.1f" text-anchor="middle" font-family="Arial" font-size="10" font-weight="700">%s</text><text x="%.1f" y="%.1f" text-anchor="middle" font-family="Arial" font-size="8">%s / %s%%</text>', $x, $y, $radius, self::COLORS[$index % count(self::COLORS)], $x, $y - 2, e($row->seller_username), $x, $y + 10, number_format($row->total), number_format($row->success_rate, 1));
        }

        return $this->svg('<svg xmlns="http://www.w3.org/2000/svg" width="520" height="165"><rect width="520" height="165" fill="#fff"/><g stroke="#e5e7eb"><line x1="35" y1="38" x2="490" y2="38"/><line x1="35" y1="88" x2="490" y2="88"/><line x1="35" y1="150" x2="490" y2="150"/></g>'.$marks.'</svg>');
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
            $marks .= sprintf('<circle cx="%.1f" cy="%.1f" r="5" fill="#fff" stroke="#b82032" stroke-width="3"/><text x="%.1f" y="%.1f" text-anchor="middle" font-family="Arial" font-size="10" font-weight="700">%s</text><text x="%.1f" y="185" text-anchor="middle" font-family="Arial" font-size="8" fill="#667085">%s</text>', $x, $y, $x, $y - 10, number_format($row->total), $x, e(substr((string) $row->activity_date, 5, 5)));
        }
        $line = implode(' ', $points);
        $lastX = $rows->count() === 1 ? 520 : 1015;

        return $this->svg('<svg xmlns="http://www.w3.org/2000/svg" width="1050" height="200"><rect width="1050" height="200" fill="#fff"/><g stroke="#e5e7eb"><line x1="35" y1="35" x2="1015" y2="35"/><line x1="35" y1="85" x2="1015" y2="85"/><line x1="35" y1="130" x2="1015" y2="130"/><line x1="35" y1="155" x2="1015" y2="155"/></g><polygon points="35,155 '.$line.' '.$lastX.',155" fill="#b8203219"/><polyline points="'.$line.'" fill="none" stroke="#b82032" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>'.$marks.'</svg>');
    }

    /** @param array<string, mixed> $dashboard */
    private function productTreemap(array $dashboard): string
    {
        $rows = collect($dashboard['by_product'])->take(10)->values();
        if ($rows->isEmpty()) {
            return $this->emptyChart('No product data');
        }
        $split = (int) ceil($rows->count() / 2);
        $groups = [$rows->slice(0, $split)->values(), $rows->slice($split)->values()];
        $rectangles = '';
        foreach ($groups as $groupIndex => $group) {
            $sum = max((int) $group->sum('total'), 1);
            $x = 0.0;
            foreach ($group as $index => $row) {
                $width = 520 * (int) $row->total / $sum;
                $color = self::COLORS[($groupIndex * $split + $index) % count(self::COLORS)];
                $rectangles .= sprintf('<rect x="%.1f" y="%d" width="%.1f" height="81" rx="4" fill="%s" stroke="#fff" stroke-width="3"/><text x="%.1f" y="%d" font-family="Arial" font-size="10" font-weight="700" fill="#fff">%s</text><text x="%.1f" y="%d" font-family="Arial" font-size="8" fill="#fff">%s calls / %s%%</text>', $x, $groupIndex * 83, $width, $color, $x + 6, $groupIndex * 83 + 31, e($row->label), $x + 6, $groupIndex * 83 + 47, number_format($row->total), number_format($row->success_rate, 1));
                $x += $width;
            }
        }

        return $this->svg('<svg xmlns="http://www.w3.org/2000/svg" width="520" height="165">'.$rectangles.'</svg>');
    }

    /** @param array<string, mixed> $dashboard */
    private function objectives(array $dashboard): string
    {
        $rows = collect($dashboard['by_objective'])->take(8)->values();
        if ($rows->isEmpty()) {
            return $this->emptyChart('No objective data', 1050, 200);
        }
        $marks = '';
        foreach ($rows as $index => $row) {
            $y = 12 + ($index * 23);
            $total = max((int) $row->total, 1);
            $success = 820 * (int) $row->success_calls / $total;
            $failure = 820 * (int) $row->failure_calls / $total;
            $noCall = 820 * (int) $row->no_calls / $total;
            $marks .= sprintf('<text x="5" y="%d" font-family="Arial" font-size="10" font-weight="700">%s</text><rect x="165" y="%d" width="%.1f" height="13" fill="#22c55e"/><rect x="%.1f" y="%d" width="%.1f" height="13" fill="#ef4444"/><rect x="%.1f" y="%d" width="%.1f" height="13" fill="#94a3b8"/><text x="1015" y="%d" text-anchor="end" font-family="Arial" font-size="10">%s</text>', $y + 10, e($row->label), $y, $success, 165 + $success, $y, $failure, 165 + $success + $failure, $y, $noCall, $y + 10, number_format($row->total));
        }

        return $this->svg('<svg xmlns="http://www.w3.org/2000/svg" width="1050" height="200"><rect width="1050" height="200" fill="#fff"/>'.$marks.'<circle cx="390" cy="193" r="5" fill="#22c55e"/><text x="400" y="197" font-family="Arial" font-size="9">Success</text><circle cx="485" cy="193" r="5" fill="#ef4444"/><text x="495" y="197" font-family="Arial" font-size="9">Failure</text><circle cx="575" cy="193" r="5" fill="#94a3b8"/><text x="585" y="197" font-family="Arial" font-size="9">No Call</text></svg>');
    }

    /** @param array<string, mixed> $dashboard */
    private function outcomeLegend(array $dashboard): string
    {
        $colors = ['Success' => '#22c55e', 'Failure' => '#ef4444', 'No Call' => '#94a3b8'];

        return '<table class="legend">'.collect($colors)->map(function (string $color, string $label) use ($dashboard): string {
            $row = collect($dashboard['by_outcome'])->firstWhere('label', $label);

            return '<tr><td><i class="swatch" style="background:'.$color.'"></i>'.$label.'</td><td>'.number_format($row->total ?? 0).'</td></tr>';
        })->implode('').'</table>';
    }

    /** @param array<string, mixed> $dashboard */
    private function specialtyLegend(array $dashboard): string
    {
        return '<table class="legend">'.collect($dashboard['by_specialty'])->take(10)->values()->map(fn ($row, int $index): string => '<tr><td><i class="swatch" style="background:'.self::COLORS[$index % count(self::COLORS)].'"></i>'.e($row->label).'</td><td>'.number_format($row->total).'</td></tr>')->implode('').'</table>';
    }

    /** @param array<string, mixed> $dashboard */
    private function sellerTable(array $dashboard): string
    {
        $rows = collect($dashboard['by_seller'])->map(fn ($row): string => '<tr><td><b>'.e($row->seller_username).'</b></td><td>'.number_format($row->total).'</td><td class="good">'.number_format($row->success_calls).'</td><td class="bad">'.number_format($row->failure_calls).'</td><td>'.number_format($row->no_calls).'</td><td><b>'.number_format($row->success_rate, 2).'%</b></td><td>'.number_format($row->working_days).'</td><td>'.number_format($row->calls_per_working_day, 2).'</td></tr>')->implode('');

        return $this->table(['Name Sale', 'Total Call', 'Success', 'Failure', 'No Call', 'Success Rate', 'Working Day', 'Call / Day'], $rows);
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
