<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A cached projection of ledger_entries, updated in the same transaction
     * as every ledger write so reads stay O(1) per instructor. It can always
     * be rebuilt from the ledger. outstanding = earned - reserved - paid.
     */
    public function up(): void
    {
        Schema::create('instructor_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->unique()->constrained('users')->restrictOnDelete();
            $table->bigInteger('earned_minor')->default(0);
            $table->bigInteger('reserved_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instructor_balances');
    }
};
