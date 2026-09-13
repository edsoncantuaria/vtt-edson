<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportCampaignRequest;
use App\Support\CampaignArchiveRestorer;
use Illuminate\Http\JsonResponse;

final class CampaignImportController extends Controller
{
    public function store(ImportCampaignRequest $request, CampaignArchiveRestorer $restorer): JsonResponse
    {
        $data = $request->validated();

        $result = $restorer->restore($data['archive'], $request->user(), $data['name'] ?? null);

        return response()->json($result, 201);
    }
}
