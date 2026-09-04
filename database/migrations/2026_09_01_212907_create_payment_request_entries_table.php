<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_request_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tab_entry_id')->constrained()->cascadeOnDelete();

            /**
             * Amount at the moment the payment was requested, so a later edit
             * or a newly confirmed entry cannot change what this payment covers.
             */
            $table->decimal('amount', total: 12, places: 2);
            $table->timestamp('created_at')->nullable();

            $table->unique(['payment_request_id', 'tab_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_request_entries');
    }
};
