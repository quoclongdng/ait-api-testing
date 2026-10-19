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
        Schema::create('endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_spec_id')->constrained('api_specs')->onDelete('cascade');
            $table->string('method', 10);
            $table->string('path', 500);
            $table->string('operation_id', 255)->nullable();
            $table->text('summary')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('parameters')->nullable();
            $table->jsonb('request_body')->nullable();
            $table->jsonb('responses');
            $table->jsonb('security')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['api_spec_id', 'method', 'path'], 'endpoints_spec_method_path_unique');
            $table->index('api_spec_id', 'endpoints_api_spec_id_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE endpoints ADD CONSTRAINT endpoints_method_check CHECK (method IN ('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE endpoints DROP CONSTRAINT IF EXISTS endpoints_method_check');
        }

        Schema::dropIfExists('endpoints');
    }
};
