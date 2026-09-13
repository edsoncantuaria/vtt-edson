<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $playlist_id
 * @property string $title
 * @property string $url
 * @property float $volume
 * @property bool $loop
 * @property int $sort
 * @property-read Playlist $playlist
 */
class PlaylistTrack extends Model
{
    protected $fillable = ['playlist_id', 'title', 'url', 'volume', 'loop', 'sort'];

    protected function casts(): array
    {
        return ['volume' => 'float', 'loop' => 'boolean'];
    }

    /** @return BelongsTo<Playlist, $this> */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }
}
