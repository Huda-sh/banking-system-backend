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
            $table->string('source_account_id')->constrained('accounts');
            $table->string('target_account_id')->constrained('accounts');
            $table->decimal('amount', 30, 2);
            $table->string('currency', 3);
            $table->string('type'); // transfer, deposit, withdrawal.
            $table->date('scheduled_date');
            $table->string('status'); // pending, failed, completed
            $table->boolean('is_active')->default(true);
            $table->foreignId('initiated_by')->constrained('users');
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
