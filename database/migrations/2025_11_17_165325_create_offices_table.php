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
        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->foreignId('level_id')->constrained('office_levels');
            $table->string('name'); // e.g., "Kodim 0735/Surakarta"
            $table->string('code')->nullable(); // e.g., "0735"
            $table->text('notes')->nullable();
            $table->unsignedInteger('_lft')->default(0);
            $table->unsignedInteger('_rgt')->default(0);
            $table->timestamps();

            $table->index(['_lft', '_rgt']);
            $table->index('level_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offices');
    }
};
