<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->string('import_key')->nullable();
            $table->unique(['campaign_id', 'import_key']);
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropUnique(['campaign_id', 'import_key']);
            $table->dropColumn('import_key');
        });
    }
};
