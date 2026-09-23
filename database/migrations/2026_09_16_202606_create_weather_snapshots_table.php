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
        Schema::create('weather_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('location_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->unsignedTinyInteger('schema_version')->default(1);
            $table->json('payload');
            $table->timestamp('fetched_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['location_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weather_snapshots');
    }
};
