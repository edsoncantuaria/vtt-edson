<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roll_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_id');
            $table->string('step', 80)->default('roll');
            $table->string('context', 40);
            $table->string('edition', 16);
            $table->string('visibility', 16)->default('public');
            $table->string('mode', 16)->default('normal');
            $table->string('formula', 240);
            $table->string('effective_formula', 240);
            $table->integer('modifier')->default(0);
            $table->string('extra_dice', 120)->nullable();
            $table->string('label', 80)->nullable();
            $table->json('house_rules');
            $table->json('result');
            $table->char('request_hash', 64);
            $table->timestamps();
            $table->unique(['scene_id', 'user_id', 'request_id', 'step'], 'roll_request_step_unique');
            $table->index(['scene_id', 'visibility', 'id']);
        });

        Schema::table('private_messages', function (Blueprint $table) {
            $table->uuid('roll_id')->nullable()->unique();
        });
        Schema::table('roll_table_rolls', function (Blueprint $table) {
            $table->uuid('roll_id')->nullable()->unique();
        });
        Schema::table('combat_participants', function (Blueprint $table) {
            $table->uuid('initiative_roll_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('combat_participants', fn (Blueprint $table) => $table->dropColumn('initiative_roll_id'));
        Schema::table('roll_table_rolls', fn (Blueprint $table) => $table->dropUnique(['roll_id']));
        Schema::table('roll_table_rolls', fn (Blueprint $table) => $table->dropColumn('roll_id'));
        Schema::table('private_messages', fn (Blueprint $table) => $table->dropUnique(['roll_id']));
        Schema::table('private_messages', fn (Blueprint $table) => $table->dropColumn('roll_id'));
        Schema::dropIfExists('roll_records');
    }
};
