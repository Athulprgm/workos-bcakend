<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Assignee. Nullable so a task can sit in the backlog unassigned.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Self reference for subtasks.
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('backlog');
            $table->string('priority', 16)->default('medium');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // The composite indexes double as the foreign key indexes for
            // project_id, so no separate single-column index is needed.
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'priority']);
            $table->index(['project_id', 'due_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
