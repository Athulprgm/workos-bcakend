<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active');
            $table->json('tech_stack')->nullable();
            $table->string('repository_url', 2048)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
            $table->index(['user_id', 'status']);
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
