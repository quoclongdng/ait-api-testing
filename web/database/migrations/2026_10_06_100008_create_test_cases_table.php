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
        Schema::create('test_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_suite_id')->constrained('test_suites')->onDelete('cascade');
            $table->foreignId('endpoint_id')->constrained('endpoints')->onDelete('restrict');
            $table->foreignId('generation_job_id')->nullable()->constrained('generation_jobs')->onDelete('set null');
            $table->string('source', 20);
            $table->string('technique', 30);
            $table->string('title', 255);
            $table->jsonb('dsl');
            $table->string('dsl_version', 10)->default('1.0');
            $table->string('fingerprint', 64)->nullable();
            $table->string('status', 20)->default('VALID');
            $table->jsonb('validation_errors')->nullable();
            $table->integer('repair_attempts')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['test_suite_id', 'fingerprint'], 'test_cases_suite_fingerprint_idx');
            $table->index(['test_suite_id', 'status'], 'test_cases_suite_status_idx');
            $table->index('endpoint_id', 'test_cases_endpoint_id_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE test_cases ADD CONSTRAINT test_cases_source_check CHECK (source IN ('RULE', 'LLM', 'HYBRID', 'MANUAL'))");
            DB::statement("ALTER TABLE test_cases ADD CONSTRAINT test_cases_technique_check CHECK (technique IN ('HAPPY_PATH', 'BOUNDARY', 'EQUIVALENCE', 'INVALID_INPUT', 'MISSING_REQUIRED', 'TYPE_MISMATCH', 'AUTH', 'BUSINESS_RULE', 'SEQUENCE'))");
            DB::statement("ALTER TABLE test_cases ADD CONSTRAINT test_cases_status_check CHECK (status IN ('VALID', 'INVALID', 'DUPLICATE', 'APPROVED', 'ARCHIVED'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE test_cases DROP CONSTRAINT IF EXISTS test_cases_source_check');
            DB::statement('ALTER TABLE test_cases DROP CONSTRAINT IF EXISTS test_cases_technique_check');
            DB::statement('ALTER TABLE test_cases DROP CONSTRAINT IF EXISTS test_cases_status_check');
        }

        Schema::dropIfExists('test_cases');
    }
};
