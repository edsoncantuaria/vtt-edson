<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_records', function (Blueprint $table) {
            $table->json('action_snapshot')->nullable();
            $table->unsignedInteger('actor_revision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('action_records', fn (Blueprint $table) => $table->dropColumn(['action_snapshot', 'actor_revision']));
    }
};
