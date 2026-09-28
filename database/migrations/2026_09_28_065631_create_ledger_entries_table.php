<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only, signed money movements per instructor. Rows are never
     * updated or deleted; corrections are new rows (clawback, payout_reversal).
     * SUM(amount_minor) for an instructor is exactly their outstanding balance.
     * idempotency_key is derived from the business event, so writing the same
     * event twice violates the unique index instead of double-counting.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $table->string('type');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('EGP');
            $table->foreignId('revenue_allocation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payout_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('idempotency_key')->unique();
            $table->date('occurred_on');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['instructor_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
