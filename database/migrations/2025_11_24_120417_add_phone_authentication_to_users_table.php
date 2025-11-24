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
        Schema::table('users', function (Blueprint $table) {
            // Make email nullable (keep for optional notifications)
            $table->string('email')->nullable()->change();

            // Make phone unique (required for authentication)
            $table->unique('phone');

            // Add phone_verified_at for future verification
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Revert email to not nullable
            $table->string('email')->nullable(false)->change();

            // Drop phone unique constraint
            $table->dropUnique(['phone']);

            // Drop phone_verified_at column
            $table->dropColumn('phone_verified_at');
        });
    }
};
