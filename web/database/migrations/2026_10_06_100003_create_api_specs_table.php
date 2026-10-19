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
        Schema::create('api_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->string('version_label', 50);
            $table->string('source_filename', 255);
            $table->string('format', 10);
            $table->string('openapi_version', 20);
            $table->text('raw_content');
            $table->jsonb('normalized')->nullable();
            $table->string('checksum', 64);
            $table->string('status', 20)->default('UPLOADED');
            $table->jsonb('parse_errors')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['project_id', 'checksum'], 'api_specs_project_checksum_unique');
            $table->index(['project_id', 'status'], 'api_specs_project_id_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE api_specs ADD CONSTRAINT api_specs_format_check CHECK (format IN ('json', 'yaml'))");
            DB::statement("ALTER TABLE api_specs ADD CONSTRAINT api_specs_status_check CHECK (status IN ('UPLOADED', 'PARSED', 'PARSE_FAILED'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE api_specs DROP CONSTRAINT IF EXISTS api_specs_format_check');
            DB::statement('ALTER TABLE api_specs DROP CONSTRAINT IF EXISTS api_specs_status_check');
        }

        Schema::dropIfExists('api_specs');
    }
};
