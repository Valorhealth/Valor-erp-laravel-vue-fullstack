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
        if (! Schema::hasTable('fibroscan_bookings')) {
            throw new RuntimeException('Missing fibroscan_bookings table. Run the base FibroScan schema before this migration.');
        }

        DB::statement(<<<'SQL'
            ALTER TABLE fibroscan_bookings
                ADD COLUMN IF NOT EXISTS approval_status VARCHAR(30) NOT NULL DEFAULT 'อนุมัติแล้ว',
                ADD COLUMN IF NOT EXISTS reviewed_by_user_id INTEGER NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS reviewed_by VARCHAR(255) NULL,
                ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMPTZ NULL,
                ADD COLUMN IF NOT EXISTS review_note TEXT NULL
        SQL);

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'fibroscan_bookings_approval_status_check'
                      AND conrelid = 'fibroscan_bookings'::regclass
                ) THEN
                    ALTER TABLE fibroscan_bookings
                        ADD CONSTRAINT fibroscan_bookings_approval_status_check
                        CHECK (approval_status IN ('รออนุมัติ', 'อนุมัติแล้ว', 'ไม่อนุมัติ'));
                END IF;
            END $$
        SQL);

        if (! Schema::hasTable('fibroscan_booking_changes')) {
            Schema::create('fibroscan_booking_changes', function (Blueprint $table): void {
                $table->bigIncrements('change_id');
                $table->unsignedBigInteger('booking_id');
                $table->integer('changed_by_user_id')->nullable();
                $table->string('changed_by', 255);
                $table->string('action', 50);
                $table->jsonb('changes')->default(DB::raw("'{}'::jsonb"));
                $table->timestampTz('created_at')->useCurrent();

                $table->foreign('booking_id')->references('booking_id')->on('fibroscan_bookings')->cascadeOnUpdate()->cascadeOnDelete();
                $table->foreign('changed_by_user_id')->references('id')->on('users')->cascadeOnUpdate()->nullOnDelete();
            });
        }

        DB::statement('CREATE INDEX IF NOT EXISTS fibroscan_bookings_approval_idx ON fibroscan_bookings (approval_status, reserved_from)');
        DB::statement('CREATE INDEX IF NOT EXISTS fibroscan_booking_changes_booking_idx ON fibroscan_booking_changes (booking_id, change_id DESC)');

        DB::statement('ALTER TABLE fibroscan_bookings DROP CONSTRAINT IF EXISTS fibroscan_bookings_no_active_overlap');
        DB::statement(<<<'SQL'
            ALTER TABLE fibroscan_bookings
                ADD CONSTRAINT fibroscan_bookings_no_active_overlap
                EXCLUDE USING gist (
                    machine_id WITH =,
                    tstzrange(reserved_from, reserved_until, '[)') WITH &&
                )
                WHERE (approval_status = 'อนุมัติแล้ว' AND status IN ('จองแล้ว', 'กำลังใช้งาน'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fibroscan_bookings DROP CONSTRAINT IF EXISTS fibroscan_bookings_no_active_overlap');
        DB::statement(<<<'SQL'
            ALTER TABLE fibroscan_bookings
                ADD CONSTRAINT fibroscan_bookings_no_active_overlap
                EXCLUDE USING gist (
                    machine_id WITH =,
                    tstzrange(reserved_from, reserved_until, '[)') WITH &&
                )
                WHERE (status IN ('จองแล้ว', 'กำลังใช้งาน'))
        SQL);

        Schema::dropIfExists('fibroscan_booking_changes');
        DB::statement(<<<'SQL'
            ALTER TABLE fibroscan_bookings
                DROP COLUMN IF EXISTS review_note,
                DROP COLUMN IF EXISTS reviewed_at,
                DROP COLUMN IF EXISTS reviewed_by,
                DROP COLUMN IF EXISTS reviewed_by_user_id,
                DROP COLUMN IF EXISTS approval_status
        SQL);
    }
};
