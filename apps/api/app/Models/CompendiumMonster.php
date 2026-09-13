<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed> $data */
class CompendiumMonster extends Model
{
    protected $fillable = ['slug', 'name', 'challenge_rating', 'data', 'source'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
