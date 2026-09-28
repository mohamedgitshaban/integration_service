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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plan_type'); // 'monthly', '3_month', 'annual'
            $table->decimal('amount_paid', 10, 2);
            $table->string('currency', 3)->default('EGP');
            $table->dateTime('starts_at');
            $table->dateTime('expires_at');
            $table->string('status')->default('active'); // active, refunded, expired
            $table->timestamps();
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
