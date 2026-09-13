<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_entries', function (Blueprint $table) {
            $table->boolean('active')->default(true)->index();
            $table->string('import_batch', 64)->nullable()->index();
            $table->string('content_hash', 64)->nullable();
        });
        Schema::create('catalog_imports', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id', 64)->unique();
            $table->string('file_hash', 64);
            $table->unsignedInteger('entry_count');
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('deactivated_count')->default(0);
            $table->timestamp('imported_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_imports');
        Schema::table('catalog_entries', function (Blueprint $table) {
            $table->dropColumn(['active', 'import_batch', 'content_hash']);
        });
    }
};
