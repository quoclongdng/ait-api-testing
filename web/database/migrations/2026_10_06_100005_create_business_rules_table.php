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
        Schema::create('business_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('endpoint_id')->nullable()->constrained('endpoints')->onDelete('set null');
            $table->string('code', 50);
            $table->text('description');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['project_id', 'code'], 'business_rules_project_code_unique');
            $table->index('project_id', 'business_rules_project_id_idx');
            $table->index('endpoint_id', 'business_rules_endpoint_id_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_rules');
    }
};
