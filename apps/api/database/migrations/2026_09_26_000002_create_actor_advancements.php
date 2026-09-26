<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actor_advancements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('actors')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->unsignedTinyInteger('from_level');
            $table->unsignedTinyInteger('to_level');
            $table->json('before_system');
            $table->json('after_system');
            $table->string('exception_reason', 1000)->nullable();
            $table->boolean('undone')->default(false);
            $table->timestamps();
            $table->unique(['actor_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actor_advancements');
    }
};
