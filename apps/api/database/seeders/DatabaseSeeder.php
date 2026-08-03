<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'gm@vtt.local'],
            [
                'name' => 'Mestre',
                'password' => 'password',
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'player@vtt.local'],
            [
                'name' => 'Jogador',
                'password' => 'password',
            ]
        );
    }
}
