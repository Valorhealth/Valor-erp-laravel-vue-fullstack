<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // These indexes mirror the filters used by dashboards and workflow lists.
        // Partial Call Sale indexes stay compact by excluding non-call activity rows.
        DB::statement("CREATE INDEX IF NOT EXISTS sales_call_activities_date_product_idx ON sales_call_activities (activity_date DESC, product_1) WHERE specialty IS NOT NULL AND BTRIM(specialty) <> ''");
        DB::statement("CREATE INDEX IF NOT EXISTS sales_call_activities_date_outcome_idx ON sales_call_activities (activity_date DESC, outcome) WHERE specialty IS NOT NULL AND BTRIM(specialty) <> ''");
        DB::statement('CREATE INDEX IF NOT EXISTS sales_call_activities_status_date_idx ON sales_call_activities (status, activity_date DESC)');
        DB::statement("CREATE INDEX IF NOT EXISTS users_department_idx ON users (department) WHERE department IS NOT NULL AND BTRIM(department) <> ''");
        DB::statement('CREATE INDEX IF NOT EXISTS billing_records_status_date_idx ON billing_records (status, bill_date DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS quotations_status_date_idx ON quotations (status, qt_date DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS credit_requests_status_created_idx ON credit_requests (status, created_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS credit_requests_status_created_idx');
        DB::statement('DROP INDEX IF EXISTS quotations_status_date_idx');
        DB::statement('DROP INDEX IF EXISTS billing_records_status_date_idx');
        DB::statement('DROP INDEX IF EXISTS users_department_idx');
        DB::statement('DROP INDEX IF EXISTS sales_call_activities_status_date_idx');
        DB::statement('DROP INDEX IF EXISTS sales_call_activities_date_outcome_idx');
        DB::statement('DROP INDEX IF EXISTS sales_call_activities_date_product_idx');
    }
};
