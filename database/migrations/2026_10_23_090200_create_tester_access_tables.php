<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tester_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tester_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('key_hash');
            $table->string('key_hint', 16);
            $table->string('status', 16)->default('pending'); // pending, active, revoked
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_id', 'email']);
            $table->index(['email', 'status']);
        });

        Schema::create('tester_project_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tester_invitation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tester_invitation_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tester_project_access');
        Schema::dropIfExists('tester_invitations');
    }
};
