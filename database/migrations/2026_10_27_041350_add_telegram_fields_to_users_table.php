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
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id', 64)->nullable()->after('role');
            $table->boolean('telegram_notifications_enabled')->default(true)->after('telegram_chat_id');
            $table->json('telegram_events')->nullable()->after('telegram_notifications_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'telegram_chat_id',
                'telegram_notifications_enabled',
                'telegram_events',
            ]);
        });
    }
};
