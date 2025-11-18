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
        Schema::create('office_levels', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('level')->unique(); // 1, 2, 3, 4...
            $table->string('name'); // Kodam, Korem, Kodim, Koramil
            $table->string('description')->nullable();
            $table->boolean('is_default_user_level')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('office_levels');
    }
};
