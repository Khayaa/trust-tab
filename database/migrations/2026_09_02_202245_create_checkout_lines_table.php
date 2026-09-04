<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->decimal('unit_price', total: 12, places: 2);
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['checkout_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_lines');
    }
};
