<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->foreignId('catalog_entry_id')->nullable()->constrained('catalog_entries')->nullOnDelete();
            $table->string('folder', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->json('attachments')->nullable();
            $table->json('shared_user_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_entry_id');
            $table->dropColumn(['folder', 'metadata', 'attachments', 'shared_user_ids']);
        });
    }
};
