<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('permission_key', 80);
            $table->boolean('allowed')->default(false);
            $table->timestamps();
            $table->unique(['employee_id', 'permission_key']);
            $table->index(['permission_key', 'allowed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_permissions');
    }
};
