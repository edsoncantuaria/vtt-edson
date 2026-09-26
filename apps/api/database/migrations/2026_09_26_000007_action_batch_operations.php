<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_batch_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->uuid('message_id');
            $table->string('kind', 24);
            $table->char('input_hash', 64);
            $table->json('target_actor_ids');
            $table->timestamps();
            $table->unique(['scene_id', 'user_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_batch_operations');
    }
};
