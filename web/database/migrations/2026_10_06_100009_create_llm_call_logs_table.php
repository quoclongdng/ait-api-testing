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
        Schema::create('llm_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_job_id')->constrained('generation_jobs')->onDelete('cascade');
            $table->foreignId('test_case_id')->nullable()->constrained('test_cases')->onDelete('set null');
            $table->string('provider', 50);
            $table->string('model', 100);
            $table->string('purpose', 20);
            $table->jsonb('prompt');
            $table->text('response_raw');
            $table->double('temperature');
            $table->bigInteger('seed')->nullable();
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->integer('latency_ms');
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('generation_job_id', 'llm_call_logs_job_id_idx');
            $table->index('test_case_id', 'llm_call_logs_test_case_id_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE llm_call_logs ADD CONSTRAINT llm_call_logs_purpose_check CHECK (purpose IN ('GENERATE', 'REPAIR'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('llm_call_logs');
    }
};
