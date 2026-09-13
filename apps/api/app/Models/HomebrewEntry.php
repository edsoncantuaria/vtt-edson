<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $homebrew_package_id
 * @property string $kind
 * @property string $name
 * @property string $slug
 * @property string $version
 * @property array<string, mixed> $data
 * @property-read HomebrewPackage $package
 */
class HomebrewEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    /** @return BelongsTo<HomebrewPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(HomebrewPackage::class, 'homebrew_package_id');
    }
}
