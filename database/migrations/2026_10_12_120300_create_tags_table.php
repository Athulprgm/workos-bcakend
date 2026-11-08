<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->timestamps();

            // Tags are per-user, so two users may each have a "backend" tag.
            $table->unique(['user_id', 'slug']);
        });

        Schema::create('tag_task', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();

            $table->primary(['tag_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_task');
        Schema::dropIfExists('tags');
    }
};
