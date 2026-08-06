<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_work_rules', function (Blueprint $table) {
            $table->unsignedTinyInteger('cycle_weeks')->default(1)->after('working');
            $table->unsignedTinyInteger('cycle_week')->default(1)->after('cycle_weeks');
        });
    }

    public function down(): void
    {
        Schema::table('employee_work_rules', function (Blueprint $table) {
            $table->dropColumn(['cycle_weeks', 'cycle_week']);
        });
    }
};
