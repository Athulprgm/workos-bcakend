<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('google_user_id')->nullable()->index();
            $table->string('email');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamps();
        });

        Schema::create('google_sheet_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('google_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('spreadsheet_id', 191);
            $table->text('spreadsheet_url');
            $table->string('sheet_name', 191);
            $table->unsignedTinyInteger('header_row')->default(1);
            $table->boolean('sync_enabled')->default(true);
            $table->boolean('bidirectional')->default(false);
            $table->string('conflict_policy', 16)->default('manual'); // manual, sheet_wins, app_wins
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status', 24)->default('never'); // never, success, partial, error
            $table->text('last_sync_error')->nullable();
            $table->unsignedInteger('last_imported_count')->default(0);
            $table->unsignedInteger('last_exported_count')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'spreadsheet_id', 'sheet_name', 'project_id'], 'sheet_connection_unique');
            $table->index(['user_id', 'sync_enabled']);
        });

        Schema::create('sheet_sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_sheet_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bug_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('row_key', 191);
            $table->json('local_values')->nullable();
            $table->json('remote_values')->nullable();
            $table->json('fields')->nullable();
            $table->string('status', 16)->default('open'); // open, resolved
            $table->string('resolution', 16)->nullable(); // local, remote
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['google_sheet_connection_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_sync_conflicts');
        Schema::dropIfExists('google_sheet_connections');
        Schema::dropIfExists('google_accounts');
    }
};
