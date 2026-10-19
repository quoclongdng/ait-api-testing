<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('experiment_bug_detections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_id')->constrained('experiments')->onDelete('cascade');
            $table->foreignId('benchmark_bug_id')->constrained('benchmark_bugs')->onDelete('cascade');
            $table->integer('repetition');
            $table->boolean('detected')->default(false);
            $table->foreignId('clean_test_run_id')->constrained('test_runs')->onDelete('cascade');
            $table->foreignId('bug_test_run_id')->constrained('test_runs')->onDelete('cascade');
            $table->jsonb('detecting_test_case_ids')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['experiment_id', 'benchmark_bug_id', 'repetition'], 'exp_bug_det_unique');
            $table->index('experiment_id', 'exp_bug_det_experiment_idx');
            $table->index('clean_test_run_id', 'exp_bug_det_clean_run_idx');
            $table->index('bug_test_run_id', 'exp_bug_det_bug_run_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('experiment_bug_detections');
    }
};
