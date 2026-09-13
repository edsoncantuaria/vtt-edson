<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaylistController extends Controller
{
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->roleFor($request->user()) !== null, 403);

        return response()->json(['playlists' => Playlist::where('campaign_id', $campaign->id)->with('tracks')->orderBy('name')->get()]);
    }

    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $this->gm($request, $campaign);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $playlist = Playlist::create(['campaign_id' => $campaign->id, 'name' => $data['name']]);

        return response()->json(['playlist' => $playlist->load('tracks')], 201);
    }

    public function update(Request $request, Playlist $playlist): JsonResponse
    {
        $this->gm($request, $playlist->campaign);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $playlist->update($data);

        return response()->json(['playlist' => $playlist->load('tracks')]);
    }

    public function destroy(Request $request, Playlist $playlist): JsonResponse
    {
        $this->gm($request, $playlist->campaign);
        $playlist->delete();

        return response()->json(['ok' => true]);
    }

    public function addTrack(Request $request, Playlist $playlist): JsonResponse
    {
        $this->gm($request, $playlist->campaign);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'url' => ['required', 'url', 'max:4000', 'regex:/^https:\/\//i'],
            'volume' => ['sometimes', 'numeric', 'between:0,1'],
            'loop' => ['sometimes', 'boolean'],
        ]);
        $track = PlaylistTrack::create([
            'playlist_id' => $playlist->id,
            ...$data,
            'sort' => ((int) $playlist->tracks()->max('sort')) + 1,
        ]);

        return response()->json(['track' => $track], 201);
    }

    public function deleteTrack(Request $request, PlaylistTrack $track): JsonResponse
    {
        $this->gm($request, $track->playlist->campaign);
        $track->delete();

        return response()->json(['ok' => true]);
    }

    private function gm(Request $request, Campaign $campaign): void
    {
        abort_unless($campaign->roleFor($request->user()) === 'gm', 403);
    }
}
