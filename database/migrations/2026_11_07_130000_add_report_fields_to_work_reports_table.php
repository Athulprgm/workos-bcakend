<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_reports', function (Blueprint $table) {
            $table->string('project_name')->nullable()->after('company_name');
            $table->text('work_description')->nullable()->after('project_name');
            $table->string('status', 32)->default('Completed')->after('work_description');
        });

        // Backfill existing records
        $reports = DB::table('work_reports')->get();
        foreach ($reports as $report) {
            $projectsWorked = json_decode($report->projects_worked ?? '[]', true) ?: [];
            $completed = json_decode($report->completed_work ?? '[]', true) ?: [];
            $wip = json_decode($report->work_in_progress ?? '[]', true) ?: [];
            $pending = json_decode($report->pending_tasks ?? '[]', true) ?: [];

            $projectName = $projectsWorked[0]['name'] ?? $completed[0]['project'] ?? $report->company_name ?? 'General';
            $workDesc = $completed[0]['description'] ?? $completed[0]['title'] ?? $wip[0]['title'] ?? $report->title ?? '';

            $status = 'Completed';
            if (!empty($wip) && empty($completed)) {
                $status = 'In Progress';
            } elseif (!empty($pending) && empty($completed) && empty($wip)) {
                $status = 'Pending';
            }

            DB::table('work_reports')
                ->where('id', $report->id)
                ->update([
                    'project_name' => $projectName,
                    'work_description' => $workDesc,
                    'status' => $status,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('work_reports', function (Blueprint $table) {
            $table->dropColumn(['project_name', 'work_description', 'status']);
        });
    }
};

