<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_records', fn (Blueprint $table) => $table->char('input_hash', 64)->nullable());
        Schema::create('action_healing_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained()->cascadeOnDelete();
            $table->uuid('message_id');
            $table->foreignId('confirmed_by')->constrained('users');
            $table->json('before');
            $table->json('after');
            $table->unsignedInteger('amount');
            $table->boolean('undone')->default(false);
            $table->timestamps();
            $table->unique(['scene_id', 'actor_id', 'message_id'], 'healing_once_per_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_healing_applications');
        Schema::table('action_records', fn (Blueprint $table) => $table->dropColumn('input_hash'));
    }
};
