<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 24)->default('player'); // gm|assistant|player|observer
            $table->json('permissions')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'user_id']);
        });

        Schema::create('actor_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('actors')->cascadeOnDelete();
            $table->foreignId('catalog_entry_id')->nullable()->constrained('catalog_entries')->nullOnDelete();
            $table->string('kind', 24); // item|spell|feature
            $table->string('name', 160);
            $table->string('slug', 160)->nullable()->index();
            $table->string('source', 80)->nullable();
            $table->json('data')->default('{}');
            $table->json('overrides')->default('{}');
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('equipped')->default(false);
            $table->boolean('prepared')->default(false);
            $table->boolean('attuned')->default(false);
            $table->json('charges')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['actor_id', 'kind']);
        });

        Schema::create('active_effects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('actors')->cascadeOnDelete();
            $table->foreignId('source_document_id')->nullable()->constrained('actor_documents')->nullOnDelete();
            $table->string('name', 160);
            $table->json('duration')->default('{}');
            $table->json('modifiers')->default('[]');
            $table->json('conditions')->default('[]');
            $table->json('metadata')->default('{}');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['actor_id', 'active']);
        });

        Schema::create('campaign_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('uploader_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 180);
            $table->string('kind', 24); // image|audio|document
            $table->string('path', 500);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->unique(['campaign_id', 'sha256']);
            $table->index(['campaign_id', 'kind']);
        });

        Schema::create('campaign_macros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('icon', 80)->nullable();
            $table->json('commands')->default('[]');
            $table->string('visibility', 24)->default('owner'); // owner|campaign|gm
            $table->unsignedTinyInteger('hotbar_slot')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['campaign_id', 'owner_user_id']);
        });

        Schema::create('campaign_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('module_id', 120);
            $table->string('name', 160);
            $table->string('version', 40);
            $table->json('manifest')->default('{}');
            $table->json('permissions')->default('[]');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['campaign_id', 'module_id']);
        });

        Schema::create('campaign_subsystems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('catalog_entry_id')->nullable()->constrained('catalog_entries')->nullOnDelete();
            $table->string('kind', 32);
            $table->string('name', 160);
            $table->json('state')->default('{}');
            $table->json('metadata')->default('{}');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['campaign_id', 'kind']);
        });

        Schema::create('campaign_resource_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('resource_type', 32);
            $table->unsignedBigInteger('resource_id');
            $table->string('permission', 16); // view|edit|manage
            $table->timestamps();
            $table->unique(['campaign_id', 'user_id', 'resource_type', 'resource_id']);
        });

        // Preserve existing memberships. The owner is always the canonical GM.
        DB::table('campaigns')->orderBy('id')->each(function ($campaign) {
            DB::table('campaign_members')->updateOrInsert(
                ['campaign_id' => $campaign->id, 'user_id' => $campaign->owner_id],
                ['role' => 'gm', 'permissions' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
            );
            $userIds = DB::table('scene_members')
                ->join('scenes', 'scenes.id', '=', 'scene_members.scene_id')
                ->where('scenes.campaign_id', $campaign->id)
                ->where('scene_members.user_id', '!=', $campaign->owner_id)
                ->pluck('scene_members.user_id')->unique();
            foreach ($userIds as $userId) {
                DB::table('campaign_members')->updateOrInsert(
                    ['campaign_id' => $campaign->id, 'user_id' => $userId],
                    ['role' => 'player', 'permissions' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
                );
            }
        });

        // Promote embedded actor content into first-class documents without removing
        // the legacy JSON arrays. The compatibility mirror lets old clients continue
        // to work while all new writes use actor_documents.
        DB::table('actors')->orderBy('id')->each(function ($actor) {
            $system = json_decode($actor->system ?? '{}', true) ?: [];
            foreach ($system['inventory'] ?? [] as $index => $item) {
                if (! is_array($item) || empty($item['name'])) {
                    continue;
                }
                DB::table('actor_documents')->insert([
                    'actor_id' => $actor->id,
                    'kind' => 'item',
                    'name' => mb_substr((string) $item['name'], 0, 160),
                    'slug' => $item['slug'] ?? null,
                    'data' => json_encode($item),
                    'overrides' => json_encode([]),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'equipped' => (bool) ($item['equipped'] ?? false),
                    'prepared' => false,
                    'attuned' => false,
                    'sort' => $index,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ($system['spells']['known'] ?? [] as $index => $spell) {
                if (! is_array($spell) || empty($spell['name'])) {
                    continue;
                }
                DB::table('actor_documents')->insert([
                    'actor_id' => $actor->id,
                    'kind' => 'spell',
                    'name' => mb_substr((string) $spell['name'], 0, 160),
                    'slug' => $spell['slug'] ?? null,
                    'data' => json_encode($spell),
                    'overrides' => json_encode([]),
                    'quantity' => 1,
                    'equipped' => false,
                    'prepared' => (bool) ($spell['prepared'] ?? false),
                    'attuned' => false,
                    'sort' => $index,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ($system['features'] ?? [] as $index => $feature) {
                if (! is_array($feature) || empty($feature['name'])) {
                    continue;
                }
                DB::table('actor_documents')->insert([
                    'actor_id' => $actor->id,
                    'kind' => 'feature',
                    'name' => mb_substr((string) $feature['name'], 0, 160),
                    'slug' => $feature['slug'] ?? null,
                    'source' => $feature['source'] ?? null,
                    'data' => json_encode($feature),
                    'overrides' => json_encode([]),
                    'quantity' => 1,
                    'equipped' => false,
                    'prepared' => false,
                    'attuned' => false,
                    'sort' => $index,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_resource_permissions');
        Schema::dropIfExists('campaign_subsystems');
        Schema::dropIfExists('campaign_modules');
        Schema::dropIfExists('campaign_macros');
        Schema::dropIfExists('campaign_assets');
        Schema::dropIfExists('active_effects');
        Schema::dropIfExists('actor_documents');
        Schema::dropIfExists('campaign_members');
    }
};
