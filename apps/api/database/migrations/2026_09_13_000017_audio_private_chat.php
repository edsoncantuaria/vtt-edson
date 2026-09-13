<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();
        });
        Schema::create('playlist_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('url');
            $table->decimal('volume', 4, 3)->default(0.5);
            $table->boolean('loop')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
        Schema::create('private_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 16)->default('text');
            $table->text('text')->nullable();
            $table->string('formula', 120)->nullable();
            $table->integer('total')->nullable();
            $table->text('detail')->nullable();
            $table->boolean('critical')->default(false);
            $table->boolean('fumble')->default(false);
            $table->timestamps();
            $table->index(['scene_id', 'recipient_user_id', 'id']);
            $table->index(['scene_id', 'sender_user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_messages');
        Schema::dropIfExists('playlist_tracks');
        Schema::dropIfExists('playlists');
    }
};
