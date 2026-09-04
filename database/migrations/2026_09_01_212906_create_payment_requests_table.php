<?php

use App\Enums\PaymentRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tab_id')->constrained()->cascadeOnDelete();

            /**
             * Sent to MoMo as X-Reference-Id. Must be UUID v4 and must stay
             * stable across retries: reusing it makes MoMo answer 409 instead
             * of creating a second debit.
             */
            $table->uuid('reference_id')->unique();
            $table->string('external_reference')->nullable()->unique();

            $table->decimal('amount', total: 12, places: 2);
            $table->string('currency', 3);
            $table->string('payer_msisdn');
            $table->string('status')->default(PaymentRequestStatus::Created->value);
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->index(['tab_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};
