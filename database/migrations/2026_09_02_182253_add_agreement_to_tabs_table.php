<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tabs', function (Blueprint $table) {
            $table->decimal('limit_amount', total: 12, places: 2)->nullable()->after('status');
            $table->unsignedTinyInteger('settlement_day')->nullable()->after('limit_amount');
            $table->timestamp('agreement_accepted_at')->nullable()->after('settlement_day');
        });
    }

    public function down(): void
    {
        Schema::table('tabs', function (Blueprint $table) {
            $table->dropColumn(['limit_amount', 'settlement_day', 'agreement_accepted_at']);
        });
    }
};
