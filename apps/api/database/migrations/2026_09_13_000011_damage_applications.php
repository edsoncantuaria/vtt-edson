<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('damage_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained()->cascadeOnDelete();
            $table->string('message_id', 80);
            $table->json('before');
            $table->json('after');
            $table->boolean('undone')->default(false);
            $table->unique(['scene_id', 'actor_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('damage_applications');
    }
};
