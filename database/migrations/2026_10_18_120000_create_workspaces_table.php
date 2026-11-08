<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('company_type', 64)->default('company'); // external, my_company, personal, client
            $table->string('color', 32)->nullable()->default('#6366F1');
            $table->json('settings')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
            $table->index(['user_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
