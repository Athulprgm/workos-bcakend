<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32); // daily, weekly, monthly
            $table->date('period_start');
            $table->date('period_end');
            $table->string('title');
            $table->string('company_name');
            $table->json('projects_worked')->nullable();
            $table->json('completed_work')->nullable();
            $table->json('work_in_progress')->nullable();
            $table->json('pending_tasks')->nullable();
            $table->json('issues_blockers')->nullable();
            $table->integer('time_spent_minutes')->default(0);
            $table->json('next_steps')->nullable();
            $table->text('raw_content')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'type']);
            $table->index(['workspace_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_reports');
    }
};
