<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * All money columns hold integer minor units (piastres). Dates are whole
     * calendar days because revenue is recognised one day at a time.
     *
     * platform_share_bps is snapshotted at purchase so a later rate change
     * never re-prices a term that was already paid for.
     * service_ends_on is ends_on, or the day before a refund took effect.
     * recognized_through is the allocation cursor: the last day already
     * turned into instructor earnings (starts_on - 1 when nothing is yet).
     * payment_reference is the checkout provider's id for the charge; unique,
     * so replaying the same payment can never create a second subscription.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('plan');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('EGP');
            $table->string('payment_reference')->unique();
            $table->unsignedSmallInteger('platform_share_bps');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('term_days');
            $table->date('service_ends_on');
            $table->date('recognized_through');
            $table->string('status')->default('active');
            $table->date('refunded_on')->nullable();
            $table->unsignedBigInteger('refund_amount_minor')->nullable();
            $table->timestamps();

            $table->index('recognized_through');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
