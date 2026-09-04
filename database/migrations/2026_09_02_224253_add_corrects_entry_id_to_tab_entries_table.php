<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tab_entries', function (Blueprint $table) {
            $table->foreignId('corrects_entry_id')
                ->nullable()
                ->after('checkout_id')
                ->constrained('tab_entries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tab_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corrects_entry_id');
        });
    }
};
