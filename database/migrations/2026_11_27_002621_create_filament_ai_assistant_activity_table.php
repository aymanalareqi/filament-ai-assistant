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
        Schema::create('filament_ai_assistant_activity', function (Blueprint $table) {
            $table->id();
            $table->uuid('review_id');
            $table->char('scope', 64);
            $table->string('actor_id');
            $table->string('actor_name', 120);
            $table->string('resource');
            $table->string('resource_label', 120);
            $table->string('operation', 150);
            $table->string('action_label', 120);
            $table->string('outcome', 20);
            $table->json('record_ids');
            $table->timestamp('created_at');

            $table->index(['scope', 'created_at', 'id'], 'fia_activity_scope_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('filament_ai_assistant_activity');
    }
};
