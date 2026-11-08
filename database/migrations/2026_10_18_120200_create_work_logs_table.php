<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->text('work_description');
            $table->date('date');
            $table->integer('time_spent_minutes')->default(0);
            $table->text('problems_encountered')->nullable();
            $table->text('next_steps')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'date']);
            $table->index(['user_id', 'date']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_logs');
    }
};
