<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homebrew_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('version', 40)->default('1.0.0');
            $table->boolean('enabled')->default(true);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'enabled']);
        });

        Schema::create('homebrew_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('homebrew_package_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('name', 160);
            $table->string('slug', 160);
            $table->string('version', 40)->default('1.0.0');
            $table->json('data');
            $table->timestamps();
            $table->unique(['homebrew_package_id', 'kind', 'slug']);
        });

        Schema::create('encounter_builder_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->json('party')->nullable();
            $table->json('creatures');
            $table->json('difficulty')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'updated_at']);
        });

        Schema::create('roll_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('formula', 40)->default('1d100');
            $table->boolean('enabled')->default(true);
            $table->json('entries');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'enabled']);
        });

        Schema::create('roll_table_rolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roll_table_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('total');
            $table->json('result');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('loot_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('roll_table_roll_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->json('items')->nullable();
            $table->json('currency')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('applied_actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loot_results');
        Schema::dropIfExists('roll_table_rolls');
        Schema::dropIfExists('roll_tables');
        Schema::dropIfExists('encounter_builder_drafts');
        Schema::dropIfExists('homebrew_entries');
        Schema::dropIfExists('homebrew_packages');
    }
};
