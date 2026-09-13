<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $slug
 * @property string $kind
 * @property string $name
 * @property string $source
 * @property string $edition
 * @property int|null $level
 * @property array<string, mixed> $data
 * @property bool $active
 */
class CatalogEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'active' => 'boolean'];
    }
}
