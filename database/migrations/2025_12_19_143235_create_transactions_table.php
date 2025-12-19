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
            $table->foreignId('from_account_id')->nullable()->constrained('accounts');
            $table->foreignId('to_account_id')->constrained('accounts');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->enum('type', ['deposit', 'withdrawal', 'transfer', 'scheduled']);
            $table->enum('status', ['pending', 'completed', 'failed', 'cancelled']);
            $table->decimal('fee', 15, 2)->default(0.00);
            $table->foreignId('initiated_by')->constrained('users');
            $table->foreignId('processed_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('description')->nullable();
            $table->ipAddress('ip_address');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
