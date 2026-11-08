<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_reports', function (Blueprint $table) {
            $table->string('visibility', 16)->default('private')->after('company_name')->index();
            $table->boolean('shared_with_gm')->default(false)->after('visibility');
            $table->foreignId('gm_user_id')->nullable()->after('shared_with_gm')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('shared_at')->nullable()->after('gm_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_reports', function (Blueprint $table) {
            $table->dropForeign(['gm_user_id']);
            $table->dropColumn(['visibility', 'shared_with_gm', 'gm_user_id', 'shared_at']);
        });
    }
};
