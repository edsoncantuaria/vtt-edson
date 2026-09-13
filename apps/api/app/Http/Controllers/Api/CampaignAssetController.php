<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\CampaignResourcePermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class CampaignAssetController extends Controller
{
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->isMember($request->user()), 403);
        $kind = $request->validate(['kind' => ['nullable', 'in:image,audio,document']])['kind'] ?? null;
        $granted = CampaignResourcePermission::query()->where('campaign_id', $campaign->id)
            ->where('user_id', $request->user()->id)->where('resource_type', 'asset')->pluck('resource_id');
        $assets = CampaignAsset::where('campaign_id', $campaign->id)
            ->when(! $campaign->canManage($request->user()) && ! $campaign->can($request->user(), 'assets.manage'),
                fn ($query) => $query->whereIn('id', $granted))
            ->when($kind, fn ($query) => $query->where('kind', $kind))->latest('id')->get()
            ->map(fn (CampaignAsset $asset) => $this->payload($asset));

        return response()->json(['assets' => $assets]);
    }

    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        abort_if($campaign->roleFor($request->user()) === 'observer', 403, 'Observadores possuem acesso somente de leitura.');
        abort_unless($campaign->canManage($request->user()) || $campaign->can($request->user(), 'assets.manage'), 403);
        $request->validate(['file' => ['required', 'file', 'max:30720'], 'name' => ['nullable', 'string', 'max:180']]);
        $file = $request->file('file');
        $mime = (string) $file->getMimeType();
        $kind = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'audio/') ? 'audio' : 'document');
        $allowed = $kind === 'image'
            ? ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
            : ($kind === 'audio' ? ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/mp4'] : ['application/pdf', 'text/plain']);
        abort_unless(in_array($mime, $allowed, true), 422, 'Formato de asset não suportado.');
        $bytes = file_get_contents($file->getRealPath());
        abort_unless(is_string($bytes), 422, 'Não foi possível ler o arquivo.');
        $sha = hash('sha256', $bytes);
        $existing = CampaignAsset::where(['campaign_id' => $campaign->id, 'sha256' => $sha])->first();
        if ($existing) {
            return response()->json(['asset' => $this->payload($existing), 'deduplicated' => true]);
        }
        $path = $file->store('campaign-assets/'.$campaign->id, 'public');
        $asset = CampaignAsset::create([
            'campaign_id' => $campaign->id, 'uploader_user_id' => $request->user()->id,
            'name' => $request->input('name') ?: $file->getClientOriginalName(), 'kind' => $kind,
            'path' => $path, 'mime' => $mime, 'size_bytes' => $file->getSize(), 'sha256' => $sha, 'metadata' => [],
        ]);

        return response()->json(['asset' => $this->payload($asset), 'deduplicated' => false], 201);
    }

    public function destroy(Request $request, CampaignAsset $campaignAsset): JsonResponse
    {
        abort_if($campaignAsset->campaign->roleFor($request->user()) === 'observer', 403, 'Observadores possuem acesso somente de leitura.');
        abort_unless($campaignAsset->campaign->canManage($request->user()) || $campaignAsset->campaign->can($request->user(), 'assets.manage')
            || CampaignResourcePermission::permits($campaignAsset->campaign, $request->user(), 'asset', $campaignAsset->id, 'manage'), 403);
        Storage::disk('public')->delete($campaignAsset->path);
        $campaignAsset->delete();

        return response()->json(['ok' => true]);
    }

    private function payload(CampaignAsset $asset): array
    {
        return [...$asset->toArray(), 'url' => url('storage/'.$asset->path)];
    }
}
