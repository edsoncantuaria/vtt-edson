<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('active_effects', function (Blueprint $table) {
            $table->string('visibility', 12)->default('public');
            $table->string('icon_url', 2048)->nullable();
            $table->string('source_label', 160)->nullable();
            $table->foreignId('concentration_actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->string('concentration_id', 80)->nullable();
        });
        Schema::create('active_effect_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('actors')->cascadeOnDelete();
            $table->unsignedBigInteger('effect_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 32);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 240)->nullable();
            $table->timestamps();
            $table->index(['actor_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_effect_events');
        Schema::table('active_effects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('concentration_actor_id');
            $table->dropColumn(['visibility', 'icon_url', 'source_label', 'concentration_id']);
        });
    }
};
