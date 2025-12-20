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
        Schema::create('recurring_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->foreignId('source_account_id')->constrained('accounts');
            $table->foreignId('target_account_id')->constrained('accounts');
            $table->decimal('amount', 30, 2);
            $table->string('currency', 3);
            $table->string('frequency'); // daily, weekly, monthly, yearly
            $table->string('type'); // transfer, deposit, withdrawal.
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('initiated_by')->constrained('users');
            $table->integer('current_execution')->default(0);
            $table->timestamps();
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_transactions');
    }
};
