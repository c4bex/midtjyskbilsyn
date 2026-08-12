<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capacity_day_locks', function (Blueprint $table) {
            $table->id();
            $table->string('location_slug', 80);
            $table->date('date');
            $table->timestamps();
            $table->unique(['location_slug', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capacity_day_locks');
    }
};
