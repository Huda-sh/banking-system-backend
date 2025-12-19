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
        Schema::create('scheduled_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions');
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'yearly']);
            $table->dateTime('next_execution');
            $table->integer('execution_count')->default(0);
            $table->integer('max_executions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_transactions');
    }
};
