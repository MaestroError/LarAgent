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
        Schema::create('laragent_session_identities', function (Blueprint $table) {
            $table->id();
            
            // Storage key for grouping items (used by EloquentStorage)
            $table->string('session_key')->index();
            
            // Position for maintaining order within a session
            $table->unsignedInteger('position')->default(0);
            
            // SessionIdentity fields
            $table->string('key')->nullable();
            $table->string('agent_name')->nullable();
            $table->string('chat_name')->nullable();
            $table->string('user_id')->nullable();
            $table->string('group')->nullable();
            $table->string('scope')->nullable();
            
            $table->timestamps();
            
            // Composite index for efficient lookups
            $table->index(['session_key', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('laragent_session_identities');
    }
};
