<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_id', 128)->unique();
            $table->string('event', 64)->index();
            $table->string('action', 64)->nullable();
            $table->string('repository', 255)->nullable()->index();
            $table->string('sender', 255)->nullable();
            $table->json('payload')->nullable();
            $table->boolean('processed')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_webhook_events');
    }
};
