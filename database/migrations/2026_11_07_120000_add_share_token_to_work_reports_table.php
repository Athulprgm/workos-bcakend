<?php

use App\Models\WorkReport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_reports', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique()->after('company_name');
        });

        // Generate tokens for existing reports
        WorkReport::query()->whereNull('share_token')->get()->each(function (WorkReport $report): void {
            $report->update(['share_token' => Str::random(32)]);
        });
    }

    public function down(): void
    {
        Schema::table('work_reports', function (Blueprint $table) {
            $table->dropColumn('share_token');
        });
    }
};

