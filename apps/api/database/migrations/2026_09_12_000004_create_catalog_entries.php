<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_entries', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('kind', 24);
            $table->string('name');
            $table->string('source', 80);
            $table->string('edition', 12);
            $table->unsignedTinyInteger('level')->nullable();
            $table->json('data');
            $table->index(['kind', 'edition', 'name']);
            $table->index(['kind', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_entries');
    }
};
