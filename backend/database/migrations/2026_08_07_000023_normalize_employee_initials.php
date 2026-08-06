<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $used = [];
        foreach (DB::table('employees')->orderBy('id')->get(['id', 'display_name']) as $employee) {
            $parts = collect(preg_split('/\s+/', trim($employee->display_name)) ?: [])->filter()->values();
            $candidate = $parts->count() > 1
                ? mb_strtoupper(mb_substr($parts->first(), 0, 1).mb_substr($parts->last(), 0, 1))
                : mb_strtoupper(mb_substr((string) $parts->first(), 0, 2));
            $candidate = $candidate ?: 'MB';
            $base = $candidate;
            $suffix = 2;
            while (isset($used[$candidate])) $candidate = $base.mb_strval($suffix++);
            $used[$candidate] = true;
            DB::table('employees')->where('id', $employee->id)->update(['initials' => $candidate, 'updated_at' => now()]);
        }
        Schema::table('employees', fn ($table) => $table->unique('initials', 'employees_initials_unique'));
    }

    public function down(): void
    {
        Schema::table('employees', fn ($table) => $table->dropUnique('employees_initials_unique'));
    }
};
