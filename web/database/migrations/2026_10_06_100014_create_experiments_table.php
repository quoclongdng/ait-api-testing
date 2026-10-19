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
        Schema::create('experiments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->foreignId('benchmark_id')->constrained('benchmarks')->onDelete('restrict');
            $table->string('strategy', 20);
            $table->jsonb('config');
            $table->string('status', 20)->default('PLANNED');
            $table->jsonb('metrics')->nullable();
            $table->jsonb('repetitions_data')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('benchmark_id', 'experiments_benchmark_id_idx');
            $table->index('status', 'experiments_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE experiments ADD CONSTRAINT experiments_strategy_check CHECK (strategy IN ('RULE', 'LLM', 'HYBRID'))");
            DB::statement("ALTER TABLE experiments ADD CONSTRAINT experiments_status_check CHECK (status IN ('PLANNED', 'RUNNING', 'COMPLETED', 'FAILED'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE experiments DROP CONSTRAINT IF EXISTS experiments_strategy_check');
            DB::statement('ALTER TABLE experiments DROP CONSTRAINT IF EXISTS experiments_status_check');
        }

        Schema::dropIfExists('experiments');
    }
};
