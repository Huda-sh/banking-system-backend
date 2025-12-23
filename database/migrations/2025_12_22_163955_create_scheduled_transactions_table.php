<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */

    public function up()
    {
        Schema::create('scheduled_transactions', function (Blueprint $table) {
            $table->id();
            $table->integer('account_id')->constrained('accounts')->default(null);
            $table->integer('target_account_id')->constrained('accounts')->default(null);
            $table->enum('type', ['transfer', 'deposit', 'withdrawal']);
            $table->decimal('amount', 15, 2);
            $table->timestamp('scheduled_at');
            $table->enum('status', ['scheduled', 'executed', 'failed'])->default('scheduled');
            $table->foreignId('created_by')->constrained('users');
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
