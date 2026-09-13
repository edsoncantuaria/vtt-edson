<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $scene_id
 * @property int $sender_user_id
 * @property int $recipient_user_id
 * @property string $kind
 * @property string|null $text
 * @property string|null $formula
 * @property int|null $total
 * @property string|null $detail
 * @property bool $critical
 * @property bool $fumble
 * @property-read User $sender
 * @property-read User $recipient
 */
class PrivateMessage extends Model
{
    protected $fillable = ['scene_id', 'sender_user_id', 'recipient_user_id', 'kind', 'text', 'formula', 'total', 'detail', 'critical', 'fumble'];

    protected function casts(): array
    {
        return ['critical' => 'boolean', 'fumble' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
