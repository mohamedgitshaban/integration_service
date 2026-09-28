<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per transfer to an instructor. The idempotency_key is generated
     * once when the row is created and sent on every provider call, so a
     * retry can never become a second transfer.
     * destination_account is snapshotted at reservation, so editing payout
     * details later cannot redirect money already committed to a transfer.
     * withdrawal_request_key is the client's Idempotency-Key for on-demand
     * withdrawals (null for scheduled payouts, which have a payout_run_id).
     */
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_run_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('EGP');
            $table->string('destination_account');
            $table->string('status')->default('pending');
            $table->string('idempotency_key')->unique();
            $table->string('withdrawal_request_key')->nullable();
            $table->string('provider_reference')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
            $table->unique(['instructor_id', 'withdrawal_request_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
