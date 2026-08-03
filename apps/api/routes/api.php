<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\SceneController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/rooms', [RoomController::class, 'create']);
    Route::post('/rooms/join', [RoomController::class, 'join']);

    Route::get('/scenes/{scene}', [SceneController::class, 'show']);
    Route::post('/scenes/{scene}/background', [SceneController::class, 'uploadBackground']);
    Route::post('/scenes/{scene}/tokens', [SceneController::class, 'upsertToken']);
    Route::delete('/scenes/{scene}/tokens/{tokenId}', [SceneController::class, 'deleteToken']);
    Route::post('/scenes/{scene}/walls', [SceneController::class, 'upsertWall']);
    Route::post('/scenes/{scene}/doors', [SceneController::class, 'upsertDoor']);
    Route::post('/scenes/{scene}/doors/{doorId}/toggle', [SceneController::class, 'toggleDoor']);
    Route::post('/scenes/{scene}/lights', [SceneController::class, 'upsertLight']);
    Route::post('/scenes/{scene}/fog', [SceneController::class, 'paintFog']);
    Route::patch('/scenes/{scene}/grid', [SceneController::class, 'updateGrid']);
    Route::post('/scenes/{scene}/chat', [SceneController::class, 'chat']);
});
