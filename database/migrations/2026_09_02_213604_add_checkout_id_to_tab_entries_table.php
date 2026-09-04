<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tab_entries', function (Blueprint $table) {
            $table->foreignId('checkout_id')
                ->nullable()
                ->after('tab_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tab_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checkout_id');
        });
    }
};
