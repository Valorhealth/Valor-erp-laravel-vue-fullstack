<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('job_title', 255)->nullable();
            $table->text('representative_address')->nullable();
            $table->text('national_id')->nullable();
            $table->date('national_id_issued_at')->nullable();
            $table->date('national_id_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'job_title',
                'representative_address',
                'national_id',
                'national_id_issued_at',
                'national_id_expires_at',
            ]);
        });
    }
};
