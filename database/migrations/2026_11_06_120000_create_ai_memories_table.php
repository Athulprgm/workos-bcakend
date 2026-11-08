<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 60)->default('fact'); // fact, preference, habit, project_context, summary
            $table->string('key', 120)->nullable()->index();
            $table->text('content');
            $table->unsignedTinyInteger('importance')->default(3); // 1 to 5
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_memories');
    }
};

