<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->timestamp('start_date')->nullable()->after('tech_stack');
            $table->string('priority', 16)->default('medium')->after('status');
            $table->text('notes')->nullable()->after('description');
            $table->json('milestones')->nullable()->after('tech_stack');
            $table->timestamp('archived_at')->nullable()->after('due_at');

            $table->index(['workspace_id', 'status']);
            $table->index('archived_at');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            $table->integer('estimated_duration_minutes')->nullable()->after('priority');
            $table->integer('actual_time_spent_minutes')->default(0)->after('estimated_duration_minutes');
            $table->text('completion_notes')->nullable()->after('description');
            $table->string('recurring', 32)->nullable()->default('none')->after('priority');
            $table->json('activity_history')->nullable()->after('completion_notes');

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropColumn([
                'workspace_id',
                'estimated_duration_minutes',
                'actual_time_spent_minutes',
                'completion_notes',
                'recurring',
                'activity_history',
            ]);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropColumn([
                'workspace_id',
                'start_date',
                'priority',
                'notes',
                'milestones',
                'archived_at',
            ]);
        });
    }
};
