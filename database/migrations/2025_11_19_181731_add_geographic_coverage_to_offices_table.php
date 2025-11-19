<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->string('coverage_province')->nullable()->after('notes');
            $table->string('coverage_city')->nullable()->after('coverage_province');
            $table->string('coverage_district')->nullable()->after('coverage_city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn(['coverage_province', 'coverage_city', 'coverage_district']);
        });
    }
};
