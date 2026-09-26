<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actor_resource_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('actors')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_id')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->string('event', 24);
            $table->string('resource_id', 100)->nullable();
            $table->json('before');
            $table->json('after');
            $table->string('reason', 240)->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'request_id']);
            $table->index(['actor_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actor_resource_events');
    }
};
