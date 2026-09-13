<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_catalog_shares', function (Blueprint $table) {
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_entry_id')->constrained('catalog_entries')->cascadeOnDelete();
            $table->primary(['campaign_id', 'catalog_entry_id']);
        });
        Schema::table('journals', function (Blueprint $table) {
            $table->foreignId('scene_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journals', fn (Blueprint $table) => $table->dropConstrainedForeignId('scene_id'));
        Schema::dropIfExists('campaign_catalog_shares');
    }
};
