<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('test_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_suite_id')->constrained('test_suites')->onDelete('cascade');
            $table->string('target_base_url', 500);
            $table->string('benchmark_variant', 100)->nullable();
            $table->string('status', 20)->default('QUEUED');
            $table->jsonb('totals')->nullable();
            $table->foreignId('triggered_by')->constrained('users')->onDelete('restrict');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('test_suite_id', 'test_runs_suite_id_idx');
            $table->index('status', 'test_runs_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE test_runs ADD CONSTRAINT test_runs_status_check CHECK (status IN ('QUEUED', 'RUNNING', 'COMPLETED', 'FAILED', 'CANCELLED'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE test_runs DROP CONSTRAINT IF EXISTS test_runs_status_check');
        }

        Schema::dropIfExists('test_runs');
    }
};
