<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA S13-03: generation_jobs and test_runs stored started_at/finished_at as timestamptz(0), so a
 * 106 ms run showed "Thời lượng: 0 ms" and the §11 metric "Thời gian sinh trung bình" of RULE jobs
 * was quantised to 0 or 1000 ms. db-schema.md declares these columns `timestamptz`, whose PostgreSQL
 * default precision is microseconds; the models write them with microseconds ($dateFormat).
 */
return new class extends Migration
{
    private const TABLES = ['generation_jobs', 'test_runs'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->timestampTz('started_at', 6)->nullable()->change();
                $blueprint->timestampTz('finished_at', 6)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->timestampTz('started_at', 0)->nullable()->change();
                $blueprint->timestampTz('finished_at', 0)->nullable()->change();
            });
        }
    }
};
