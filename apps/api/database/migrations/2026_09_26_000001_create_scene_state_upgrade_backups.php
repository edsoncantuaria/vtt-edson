<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scene_state_upgrade_backups', function (Blueprint $table) {
            $table->foreignId('scene_id')->primary()->constrained('scenes')->cascadeOnDelete();
            $table->json('previous_state');
            $table->char('upgraded_hash', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // A schema rollback does not overwrite ongoing gameplay. Restore scenes
        // explicitly with `scene-state:upgrade --restore` before dropping backups.
        Schema::dropIfExists('scene_state_upgrade_backups');
    }
};
