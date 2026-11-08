<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_repository_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('github_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('repository_id', 64);
            $table->string('owner');
            $table->string('name');
            $table->string('full_name');
            $table->string('default_branch')->nullable();
            $table->boolean('sync_issues')->default(true);
            $table->boolean('sync_pull_requests')->default(true);
            $table->boolean('auto_sync')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status', 24)->default('never');
            $table->text('last_sync_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'repository_id']);
            $table->unique(['project_id', 'repository_id']);
            $table->index(['user_id', 'full_name']);
        });

        Schema::create('github_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('github_repository_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('github_type', 24); // issue, pull_request
            $table->unsignedInteger('github_number');
            $table->string('title');
            $table->string('state', 24)->default('open');
            $table->text('html_url')->nullable();
            $table->string('local_type', 16); // task, bug
            $table->unsignedBigInteger('local_id');
            $table->string('last_event', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['github_repository_link_id', 'github_type', 'github_number'], 'github_link_unique');
            $table->index(['local_type', 'local_id']);
            $table->index(['user_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_links');
        Schema::dropIfExists('github_repository_links');
    }
};
