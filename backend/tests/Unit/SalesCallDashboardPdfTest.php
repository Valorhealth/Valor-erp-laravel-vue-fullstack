<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\SalesCalls\SalesCallDashboardPdf;
use Tests\TestCase;

class SalesCallDashboardPdfTest extends TestCase
{
    public function test_it_renders_filtered_dashboard_charts_and_tables_as_a_pdf(): void
    {
        $record = fn (array $values): object => (object) $values;
        $dashboard = [
            'summary' => ['total_activities' => 5, 'success_calls' => 3, 'failure_calls' => 1, 'no_calls' => 1, 'conversion_rate' => 60, 'working_days' => 4, 'calls_per_working_day' => 1.25, 'active_customers' => 5],
            'by_outcome' => [$record(['label' => 'Success', 'total' => 3]), $record(['label' => 'Failure', 'total' => 1]), $record(['label' => 'No Call', 'total' => 1])],
            'by_seller' => [$record(['seller_username' => 'sale-one', 'total' => 5, 'success_calls' => 3, 'failure_calls' => 1, 'no_calls' => 1, 'success_rate' => 60, 'working_days' => 4, 'calls_per_working_day' => 1.25, 'active_customers' => 5])],
            'daily_trend' => [$record(['activity_date' => '2026-08-01', 'total' => 2]), $record(['activity_date' => '2026-08-02', 'total' => 3])],
            'by_product' => [$record(['label' => 'AVANOS-DH', 'total' => 5, 'success_rate' => 60])],
            'by_specialty' => [$record(['label' => 'Surgery', 'total' => 5])],
            'by_objective' => [$record(['label' => 'Awareness', 'total' => 5, 'success_calls' => 3, 'failure_calls' => 1, 'no_calls' => 1])],
            'performance_matrix' => [$record(['seller_username' => 'sale-one', 'place_of_work' => 'Hospital', 'total' => 5, 'success_calls' => 3, 'failure_calls' => 1, 'no_calls' => 1, 'success_rate' => 60, 'working_days' => 4, 'calls_per_working_day' => 1.25])],
            'product_customer_matrix' => [$record(['product' => 'AVANOS-DH', 'place_of_work' => 'Hospital', 'customer_name' => 'Customer', 'total' => 5, 'success_calls' => 3, 'failure_calls' => 1, 'success_rate' => 60, 'last_call_date' => '2026-08-02'])],
            'hospital_purchase_ranking' => [$record(['hospital_name' => 'Test Hospital', 'purchase_calls' => 3, 'products_count' => 2, 'contacts_count' => 2, 'actual_value' => 150000, 'last_purchase_date' => '2026-08-02'])],
        ];
        $actor = new User(['username' => 'approve']);

        $pdf = app(SalesCallDashboardPdf::class)->render($dashboard, $actor, ['date_from' => '2026-08-01', 'date_to' => '2026-08-31']);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(10000, strlen($pdf));
    }

    public function test_donut_uses_independent_filled_segments_supported_by_pdf_renderer(): void
    {
        $method = new \ReflectionMethod(SalesCallDashboardPdf::class, 'donut');
        $uri = $method->invoke(app(SalesCallDashboardPdf::class), [
            ['label' => 'Success', 'total' => 3],
            ['label' => 'Failure', 'total' => 1],
            ['label' => 'No Call', 'total' => 1],
        ], ['#22c55e', '#ef4444', '#94a3b8'], 'Total Call');
        $svg = base64_decode(substr($uri, strpos($uri, ',') + 1), true);

        $this->assertIsString($svg);
        $this->assertSame(3, substr_count($svg, '<polygon '));
        $this->assertStringContainsString('fill="#22c55e"', $svg);
        $this->assertStringContainsString('fill="#ef4444"', $svg);
        $this->assertStringContainsString('fill="#94a3b8"', $svg);
        $this->assertStringNotContainsString('stroke-dasharray', $svg);
    }
}
