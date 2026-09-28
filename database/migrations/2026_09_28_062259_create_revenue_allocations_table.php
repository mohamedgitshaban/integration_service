<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per recognition run of a subscription, covering the days
     * [period_start, period_end]. gross = platform + instructor pool, always.
     * The unique key makes re-running allocation for the same period a no-op.
     */
    public function up(): void
    {
        Schema::create('revenue_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('platform_minor');
            $table->unsignedBigInteger('instructor_pool_minor');
            $table->timestamps();

            $table->unique(['subscription_id', 'period_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('revenue_allocations');
    }
};
