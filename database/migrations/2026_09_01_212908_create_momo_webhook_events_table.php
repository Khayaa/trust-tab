<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('momo_webhook_events', function (Blueprint $table) {
            $table->id();

            /**
             * Unique so a replayed callback cannot be processed twice. MoMo
             * delivers a callback once with no retry, so this is an audit trail
             * as much as an idempotency guard.
             */
            $table->uuid('reference_id')->unique();

            $table->string('event_type')->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('momo_webhook_events');
    }
};
