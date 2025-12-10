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
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->string('user_id')->nullable()->index();
            $table->string('group')->nullable()->index();
            $table->string('chat_name')->nullable()->index();
            $table->string('model')->nullable()->index();
            $table->string('provider')->nullable()->index();
            $table->string('agent')->nullable()->index();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            // Composite indexes for common queries
            $table->index(['user_id', 'created_at']);
            $table->index(['agent', 'created_at']);
            $table->index(['model', 'created_at']);
            $table->index(['provider', 'created_at']);
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
