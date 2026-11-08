<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_commands', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('update_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('telegram_user_id', 64)->nullable();
            $table->string('chat_id', 64);
            $table->string('command', 64)->nullable();
            $table->text('arguments')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('received');
            $table->text('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['telegram_user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_commands');
    }
};
