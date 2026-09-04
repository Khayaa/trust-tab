<?php

use App\Enums\TabEntryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tab_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tab_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('description', 120);
            $table->decimal('amount', total: 12, places: 2);
            $table->string('note', 255)->nullable();
            $table->string('status')->default(TabEntryStatus::PendingConfirmation->value);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['tab_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tab_entries');
    }
};
