<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('initials', 8)->nullable()->after('display_name');
            $table->string('employee_number', 40)->nullable()->unique()->after('initials');
            $table->string('job_title', 160)->nullable()->after('role');
            $table->string('status', 24)->default('ACTIVE')->after('active');
            $table->date('start_date')->nullable()->after('status');
            $table->date('end_date')->nullable()->after('start_date');
            $table->boolean('archived')->default(false)->after('booking_capacity');
            $table->text('internal_note')->nullable()->after('archived');
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'department_id']);
        });

        Schema::table('employee_work_rules', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('employee_id')->constrained()->nullOnDelete();
            $table->date('anchor_monday_date')->nullable()->after('cycle_week');
            $table->date('valid_from')->nullable()->after('anchor_monday_date');
            $table->date('valid_to')->nullable()->after('valid_from');
            $table->boolean('active')->default(true)->after('valid_to');
        });

        Schema::table('employee_absences', function (Blueprint $table) {
            $table->dateTime('start_at')->nullable()->after('date_to');
            $table->dateTime('end_at')->nullable()->after('start_at');
            $table->boolean('all_day')->default(true)->after('end_at');
            $table->foreignId('created_by_user_id')->nullable()->after('all_day')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();
        });

        DB::table('employees')->whereNull('initials')->get()->each(function ($employee) {
            $initials = collect(preg_split('/\s+/', trim($employee->display_name)) ?: [])
                ->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
            DB::table('employees')->where('id', $employee->id)->update([
                'initials' => $initials ?: 'MB',
                'job_title' => $employee->role,
                'status' => $employee->active ? 'ACTIVE' : 'INACTIVE',
            ]);
        });

        foreach (['Ikast', 'Bording'] as $name) {
            DB::table('departments')->updateOrInsert(['name' => $name], ['active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('employee_absences', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropForeign(['updated_by_user_id']);
            $table->dropColumn(['start_at', 'end_at', 'all_day', 'created_by_user_id', 'updated_by_user_id']);
        });
        Schema::table('employee_work_rules', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn(['department_id', 'anchor_monday_date', 'valid_from', 'valid_to', 'active']);
        });
        Schema::dropIfExists('employee_departments');
        Schema::dropIfExists('departments');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['initials', 'employee_number', 'job_title', 'status', 'start_date', 'end_date', 'archived', 'internal_note']);
        });
    }
};
