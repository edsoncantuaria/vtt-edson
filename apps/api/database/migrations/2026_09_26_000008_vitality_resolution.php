<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_healing_applications', function (Blueprint $table) {
            $table->json('resolution')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('action_healing_applications', fn (Blueprint $table) => $table->dropColumn('resolution'));
    }
};
