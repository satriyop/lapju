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
            $table->string('nrp')->nullable()->unique()->after('email'); // NRP - Army Employee ID
            $table->boolean('is_approved')->default(false)->after('password'); // Registration approval status
            $table->timestamp('approved_at')->nullable()->after('is_approved');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->boolean('is_admin')->default(false)->after('approved_by'); // Admin flag
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['nrp', 'is_approved', 'approved_at', 'approved_by', 'is_admin']);
        });
    }
};
