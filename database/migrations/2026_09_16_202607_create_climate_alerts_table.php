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
        Schema::create('climate_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('location_id')->constrained()->cascadeOnDelete();
            $table->string('metric');
            $table->string('operator');
            $table->decimal('threshold', total: 10, places: 2);
            $table->boolean('enabled')->default(true);
            $table->string('last_state')->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();

            $table->index(['location_id', 'enabled']);
            $table->index('metric');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('climate_alerts');
    }
};
