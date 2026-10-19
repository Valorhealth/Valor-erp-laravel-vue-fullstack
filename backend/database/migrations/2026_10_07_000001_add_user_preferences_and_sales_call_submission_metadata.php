<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->jsonb('preferences')->default(DB::raw("'{}'::jsonb"));
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_preferences_object_check CHECK (JSONB_TYPEOF(preferences) = 'object')");

        Schema::create('sales_call_monthly_submissions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->integer('seller_user_id');
            $table->date('report_month');
            $table->boolean('used_excel')->default(false);
            $table->boolean('used_website')->default(false);
            $table->timestampTz('first_submitted_at');
            $table->timestampTz('last_edited_at');
            $table->timestampsTz();
            $table->unique(['seller_user_id', 'report_month'], 'sales_call_monthly_submission_user_month_unique');
            $table->index(['report_month', 'seller_user_id'], 'sales_call_monthly_submission_month_user_idx');
            $table->foreign('seller_user_id')->references('id')->on('users')->cascadeOnUpdate()->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            WITH monthly_activity AS (
                SELECT
                    activity.seller_user_id,
                    DATE_TRUNC('month', activity.activity_date)::date AS report_month,
                    MIN(activity.created_at) AS first_submitted_at,
                    MAX(activity.updated_at) AS last_edited_at
                FROM sales_call_activities activity
                GROUP BY activity.seller_user_id, DATE_TRUNC('month', activity.activity_date)::date
            )
            INSERT INTO sales_call_monthly_submissions (
                seller_user_id, report_month, used_excel, used_website,
                first_submitted_at, last_edited_at, created_at, updated_at
            )
            SELECT
                monthly.seller_user_id,
                monthly.report_month,
                EXISTS (
                    SELECT 1
                    FROM sales_call_import_batches batch
                    WHERE batch.seller_user_id = monthly.seller_user_id
                      AND batch.report_month = monthly.report_month
                      AND batch.data_deleted_at IS NULL
                ) AS used_excel,
                NOT EXISTS (
                    SELECT 1
                    FROM sales_call_import_batches batch
                    WHERE batch.seller_user_id = monthly.seller_user_id
                      AND batch.report_month = monthly.report_month
                      AND batch.data_deleted_at IS NULL
                ) AS used_website,
                monthly.first_submitted_at,
                monthly.last_edited_at,
                monthly.first_submitted_at,
                monthly.last_edited_at
            FROM monthly_activity monthly
            ON CONFLICT (seller_user_id, report_month) DO NOTHING
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_call_monthly_submissions');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_preferences_object_check');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('preferences');
        });
    }
};
