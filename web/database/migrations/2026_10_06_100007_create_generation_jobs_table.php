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
        Schema::create('generation_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('api_spec_id')->constrained('api_specs')->onDelete('restrict');
            $table->foreignId('test_suite_id')->constrained('test_suites')->onDelete('cascade');
            $table->string('strategy', 20);
            $table->string('status', 20)->default('QUEUED');
            $table->jsonb('params');
            $table->jsonb('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('status', 'generation_jobs_status_idx');
            $table->index('test_suite_id', 'generation_jobs_test_suite_id_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE generation_jobs ADD CONSTRAINT generation_jobs_strategy_check CHECK (strategy IN ('RULE', 'LLM', 'HYBRID'))");
            DB::statement("ALTER TABLE generation_jobs ADD CONSTRAINT generation_jobs_status_check CHECK (status IN ('QUEUED', 'RUNNING', 'SUCCEEDED', 'PARTIAL', 'FAILED'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE generation_jobs DROP CONSTRAINT IF EXISTS generation_jobs_strategy_check');
            DB::statement('ALTER TABLE generation_jobs DROP CONSTRAINT IF EXISTS generation_jobs_status_check');
        }

        Schema::dropIfExists('generation_jobs');
    }
};
