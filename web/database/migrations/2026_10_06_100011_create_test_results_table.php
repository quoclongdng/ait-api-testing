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
        Schema::create('test_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_run_id')->constrained('test_runs')->onDelete('cascade');
            $table->foreignId('test_case_id')->constrained('test_cases')->onDelete('restrict');
            $table->string('verdict', 20);
            $table->integer('duration_ms');
            $table->jsonb('request_log');
            $table->jsonb('response_log');
            $table->jsonb('assertion_results');
            $table->text('error_message')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['test_run_id', 'test_case_id'], 'test_results_run_case_unique');
            $table->index(['test_run_id', 'verdict'], 'test_results_run_verdict_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE test_results ADD CONSTRAINT test_results_verdict_check CHECK (verdict IN ('PASS', 'FAIL', 'ERROR', 'SKIPPED'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('test_results');
    }
};
