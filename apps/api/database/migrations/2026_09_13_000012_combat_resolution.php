<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actors', fn (Blueprint $table) => $table->unsignedInteger('revision')->default(0));
        Schema::create('action_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->uuid('message_id')->unique();
            $table->json('message');
            $table->json('saves')->nullable();
            $table->json('resource_before')->nullable();
            $table->json('resource_after')->nullable();
            $table->boolean('undone')->default(false);
            $table->timestamps();
            $table->unique(['scene_id', 'user_id', 'request_id']);
        });
        Schema::table('damage_applications', function (Blueprint $table) {
            $table->json('resolution')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('damage_applications', fn (Blueprint $table) => $table->dropColumn('resolution'));
        Schema::dropIfExists('action_records');
        Schema::table('actors', fn (Blueprint $table) => $table->dropColumn('revision'));
    }
};
