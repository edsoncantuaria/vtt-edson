<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed> $data */
class CompendiumSpell extends Model
{
    protected $fillable = ['slug', 'name', 'level', 'school', 'data', 'source'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
