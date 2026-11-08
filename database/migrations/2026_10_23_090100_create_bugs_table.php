<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->text('details')->nullable();
            $table->string('priority', 16)->default('medium');
            $table->string('status', 24)->default('open');
            $table->foreignId('tester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tester_name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('testing_notes')->nullable();
            $table->boolean('validated')->default(false);
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->string('source', 24)->default('manual');
            $table->string('external_key', 191)->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->string('sync_hash', 64)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number']);
            $table->unique(['project_id', 'source', 'external_key']);
            $table->index(['workspace_id', 'status']);
            $table->index(['project_id', 'status']);
            $table->index(['tester_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bugs');
    }
};
