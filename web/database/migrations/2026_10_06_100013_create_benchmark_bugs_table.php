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
        Schema::create('benchmark_bugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benchmark_id')->constrained('benchmarks')->onDelete('cascade');
            $table->string('code', 50);
            $table->string('method', 10);
            $table->string('path', 500);
            $table->string('category', 50);
            $table->text('description');
            $table->string('toggle_flag', 100);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['benchmark_id', 'code'], 'benchmark_bugs_benchmark_code_unique');
            $table->index('benchmark_id', 'benchmark_bugs_benchmark_id_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE benchmark_bugs ADD CONSTRAINT benchmark_bugs_method_check CHECK (method IN ('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'))");
            DB::statement("ALTER TABLE benchmark_bugs ADD CONSTRAINT benchmark_bugs_category_check CHECK (category IN ('VALIDATION_MISSING', 'STATUS_CODE_WRONG', 'SCHEMA_MISMATCH', 'BOUNDARY_ERROR', 'BUSINESS_RULE_VIOLATION', 'SEQUENCE_ERROR', 'AUTH_ERROR'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE benchmark_bugs DROP CONSTRAINT IF EXISTS benchmark_bugs_method_check');
            DB::statement('ALTER TABLE benchmark_bugs DROP CONSTRAINT IF EXISTS benchmark_bugs_category_check');
        }

        Schema::dropIfExists('benchmark_bugs');
    }
};
