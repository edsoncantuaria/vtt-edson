<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 16); // character|npc|monster
            $table->string('name');
            $table->string('img_path')->nullable();
            $table->json('system');
            $table->timestamps();
        });

        Schema::create('compendium_spells', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedTinyInteger('level')->default(0);
            $table->string('school', 32)->nullable();
            $table->json('data');
            $table->string('source', 32)->default('open5e-srd');
            $table->timestamps();
        });

        Schema::create('compendium_items', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type', 32)->nullable(); // weapon|armor|gear|magic-item
            $table->json('data');
            $table->string('source', 32)->default('open5e-srd');
            $table->timestamps();
        });

        Schema::create('compendium_monsters', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('challenge_rating', 8)->nullable();
            $table->json('data');
            $table->string('source', 32)->default('open5e-srd');
            $table->timestamps();
        });

        Schema::create('combats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained('scenes')->cascadeOnDelete();
            $table->unsignedInteger('round')->default(1);
            $table->unsignedInteger('turn')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('combat_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combat_id')->constrained('combats')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->string('token_id', 64)->nullable();
            $table->string('name');
            $table->string('img_path')->nullable();
            $table->integer('initiative')->nullable();
            $table->boolean('hidden')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combat_participants');
        Schema::dropIfExists('combats');
        Schema::dropIfExists('compendium_monsters');
        Schema::dropIfExists('compendium_items');
        Schema::dropIfExists('compendium_spells');
        Schema::dropIfExists('actors');
    }
};
