<?php

namespace App\Console\Commands;

use App\Models\Scene;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Reversible V1-to-V2 marker migration; never resolves conflicting token HP by guessing. */
final class UpgradeSceneStateCommand extends Command
{
    protected $signature = 'scene-state:upgrade
        {--apply : Apply the migration, keeping each original state for rollback}
        {--restore : Restore a migrated scene only if it has not changed since migration}
        {--scene= : Operate on one scene id only}';

    protected $description = 'Preview, upgrade or safely restore legacy scene states.';

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('restore')) {
            $this->error('Choose --apply or --restore, not both.');

            return self::FAILURE;
        }
        $sceneId = $this->option('scene');
        if ($sceneId !== null && (! ctype_digit((string) $sceneId) || (int) $sceneId < 1)) {
            $this->error('Use a positive integer for --scene.');

            return self::FAILURE;
        }

        $counts = ['upgraded' => 0, 'restored' => 0, 'eligible' => 0, 'conflict' => 0, 'skipped' => 0];
        Scene::query()->when($sceneId, fn ($query) => $query->whereKey($sceneId))->orderBy('id')->chunkById(100, function ($scenes) use (&$counts) {
            foreach ($scenes as $scene) {
                DB::transaction(function () use ($scene, &$counts) {
                    $scene = Scene::query()->lockForUpdate()->findOrFail($scene->id);
                    $state = $scene->state;
                    $backup = DB::table('scene_state_upgrade_backups')->where('scene_id', $scene->id)->first();
                    if ($this->option('restore')) {
                        if (! $backup) {
                            $counts['skipped']++;

                            return;
                        }
                        if ($this->hash($state) !== $backup->upgraded_hash) {
                            $counts['conflict']++;
                            $this->warn("Scene {$scene->id}: updated after migration; restore skipped.");

                            return;
                        }
                        $scene->state = json_decode($backup->previous_state, true, flags: JSON_THROW_ON_ERROR);
                        $scene->save();
                        DB::table('scene_state_upgrade_backups')->where('scene_id', $scene->id)->delete();
                        $counts['restored']++;

                        return;
                    }
                    if ($backup || ($state['schemaVersion'] ?? 1) !== 1) {
                        $counts['skipped']++;

                        return;
                    }
                    // Uncommitted/local versions may contain token.combat HP while actor HP
                    // has changed separately. Require manual reconciliation, never discard it.
                    if (collect($state['tokens'] ?? [])->contains(fn ($token) => array_key_exists('combat', $token))) {
                        $counts['conflict']++;
                        $this->warn("Scene {$scene->id}: token.combat requires manual reconciliation.");

                        return;
                    }
                    $counts['eligible']++;
                    if (! $this->option('apply')) {
                        return;
                    }
                    $upgraded = [...$state, 'schemaVersion' => 2];
                    DB::table('scene_state_upgrade_backups')->insert([
                        'scene_id' => $scene->id,
                        'previous_state' => json_encode($state, JSON_THROW_ON_ERROR),
                        'upgraded_hash' => $this->hash($upgraded),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $scene->state = $upgraded;
                    $scene->save();
                    $counts['upgraded']++;
                });
            }
        });
        $this->table(['Eligible', 'Upgraded', 'Restored', 'Conflicts', 'Skipped'], [[
            $counts['eligible'], $counts['upgraded'], $counts['restored'], $counts['conflict'], $counts['skipped'],
        ]]);

        return $counts['conflict'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function hash(array $state): string
    {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
