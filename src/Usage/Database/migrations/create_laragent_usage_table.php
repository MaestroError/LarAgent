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
        Schema::create('laragent_usage', function (Blueprint $table) {
            $table->id();

            // Session/identity identification
            $table->string('session_key')->index();
            $table->unsignedInteger('position')->default(0);

            // Unique record identifier
            $table->string('record_id', 50)->nullable()->index();

            // Token usage
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);

            // Identity metadata
            $table->string('agent_name')->nullable()->index();
            $table->string('user_id')->nullable()->index();
            $table->string('group')->nullable()->index();
            $table->string('chat_name')->nullable();

            // Provider metadata
            $table->string('model_name')->nullable()->index();
            $table->string('provider_name')->nullable()->index();

            // Timestamp when usage was recorded
            $table->timestamp('recorded_at')->nullable()->index();

            // Composite indexes for efficient filtering
            $table->index(['session_key', 'position']);
            $table->index(['agent_name', 'user_id']);
            $table->index(['provider_name', 'model_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('laragent_usage');
    }
};
