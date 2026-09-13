<?php

use App\Models\Scene;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, int $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('scene.{sceneId}', function ($user, int $sceneId) {
    $scene = Scene::find($sceneId);
    if (! $scene) {
        return false;
    }

    $member = $scene->memberFor($user);
    if (! $member || (! $member->isGm() && ! $scene->published)) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'role' => $member->role,
    ];
});
