<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed> $data */
class CompendiumItem extends Model
{
    protected $fillable = ['slug', 'name', 'type', 'data', 'source'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
