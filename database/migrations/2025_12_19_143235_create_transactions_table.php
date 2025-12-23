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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique();
            $table->string('description')->nullable();
            $table->integer('source_account_id')->constrained('accounts')->default(null)->nullable();
            $table->integer('target_account_id')->constrained('accounts')->default(null)->nullable();
            $table->decimal('amount', 30, 2);
            $table->string('currency', 3);
            $table->string('type'); // transfer, deposit, withdrawal.
            $table->string('status'); // pending_approval, approval_not_required, completed, rejected
            $table->string('direction')->default('debit'); // debit, credit
            $table->foreignId('initiated_by')->constrained('users');
            $table->foreignId('processed_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
