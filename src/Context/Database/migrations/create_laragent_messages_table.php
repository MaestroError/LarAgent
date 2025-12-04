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
        Schema::create('laragent_messages', function (Blueprint $table) {
            $table->id();
            
            // Session identification
            $table->string('session_key')->index();
            $table->unsignedInteger('position')->default(0);
            
            // Core message fields
            $table->string('role', 50)->nullable();
            $table->json('content')->nullable();
            $table->string('message_uuid', 50)->nullable()->index();
            $table->string('message_created', 50)->nullable();
            
            // Tool-related fields
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id', 100)->nullable()->index();
            
            // Usage statistics
            $table->json('usage')->nullable();
            
            // Additional data
            $table->json('metadata')->nullable();
            $table->json('extras')->nullable();
            
            $table->timestamps();

            // Composite index for efficient session queries
            $table->index(['session_key', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('laragent_messages');
    }
};
