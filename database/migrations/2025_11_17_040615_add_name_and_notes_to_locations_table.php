<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('name')->default('')->after('id');
            $table->text('notes')->nullable()->after('province_name');
        });

        // Update existing locations to use city_name as default name
        DB::table('locations')
            ->whereRaw("name = '' OR name IS NULL")
            ->update(['name' => DB::raw('city_name')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['name', 'notes']);
        });
    }
};
