<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Journal;
use App\Models\SceneMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JournalController extends Controller
{
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        $role = $this->role($request, $campaign);
        $entries = $campaign->journals()->latest('updated_at')->get();
        if ($role !== 'gm') {
            $userId = (int) $request->user()->id;
            $entries = $entries->filter(fn (Journal $journal) => $journal->visibility === 'all'
                || ($journal->visibility === 'selected' && in_array($userId, array_map('intval', $journal->shared_user_ids ?? []), true))
            )->values();
        }

        $shareTargets = [];
        if ($role === 'gm') {
            $shareTargets = SceneMember::query()
                ->whereIn('scene_id', $campaign->scenes()->pluck('id'))
                ->where('role', 'player')
                ->with('user:id,name')
                ->get()
                ->unique('user_id')
                ->values()
                ->map(fn (SceneMember $member) => ['id' => $member->user_id, 'name' => $member->user->name])
                ->all();
        }

        return response()->json(['entries' => $entries, 'shareTargets' => $shareTargets]);
    }

    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $this->gm($request, $campaign);
        $data = $this->validateEntry($request, $campaign, false);

        return response()->json(['entry' => $campaign->journals()->create([...$data, 'body' => $data['body'] ?? ''])], 201);
    }

    public function update(Request $request, Journal $journal): JsonResponse
    {
        $this->gm($request, $journal->campaign);
        $data = $this->validateEntry($request, $journal->campaign, true);
        $journal = DB::transaction(function () use ($journal, $data) {
            $current = Journal::whereKey($journal->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->updated_at->toJSON() === $data['updated_at'], 409, 'Esta nota foi alterada em outra aba. Copie suas alterações e atualize o diário.');
            unset($data['updated_at']);
            if (array_key_exists('body', $data)) {
                $data['body'] = $data['body'] ?? '';
            }
            $current->fill($data);
            $current->updated_at = now()->max($current->updated_at->copy()->addSecond());
            $current->save();

            return $current;
        });

        return response()->json(['entry' => $journal]);
    }

    private function validateEntry(Request $request, Campaign $campaign, bool $update): array
    {
        $required = $update ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => [$required, 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:50000'],
            'visibility' => [$required, 'in:all,gm,selected'],
            'scene_id' => ['nullable', 'integer', Rule::exists('scenes', 'id')->where('campaign_id', $campaign->id)],
            'catalog_entry_id' => ['nullable', 'integer', Rule::exists('catalog_entries', 'id')],
            'folder' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*.type' => ['required_with:attachments', 'in:ref,url'],
            'attachments.*.label' => ['nullable', 'string', 'max:160'],
            'attachments.*.ref' => ['nullable', 'string', 'max:240', 'regex:/^catalog:\d+:(?:map:\d+|art|token)$/'],
            'attachments.*.url' => ['nullable', 'string', 'max:2000'],
            'shared_user_ids' => ['nullable', 'array', 'max:100'],
            'shared_user_ids.*' => ['integer', 'distinct'],
            ...($update ? ['updated_at' => ['required', 'string']] : []),
        ]);
        if (array_key_exists('folder', $data)) {
            $data['folder'] = isset($data['folder']) && trim($data['folder']) !== '' ? trim($data['folder']) : null;
        }
        if (! $update || array_key_exists('metadata', $data)) {
            $data['metadata'] = $data['metadata'] ?? [];
        }
        if (! $update || array_key_exists('attachments', $data)) {
            $data['attachments'] = $this->validateAttachments($data['attachments'] ?? []);
        }
        if (! $update || array_key_exists('shared_user_ids', $data)) {
            $data['shared_user_ids'] = array_values(array_map('intval', $data['shared_user_ids'] ?? []));
        }

        $memberIds = SceneMember::query()->whereIn('scene_id', $campaign->scenes()->pluck('id'))->where('role', 'player')->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->all();
        if (array_key_exists('shared_user_ids', $data)) {
            abort_if(array_diff($data['shared_user_ids'], $memberIds) !== [], 422, 'Compartilhe apenas com jogadores desta campanha.');
        }
        if (($data['visibility'] ?? null) === 'selected' && array_key_exists('shared_user_ids', $data)) {
            abort_unless(count($data['shared_user_ids']) > 0, 422, 'Escolha ao menos um jogador para o compartilhamento seletivo.');
        }
        if (array_key_exists('visibility', $data) && $data['visibility'] !== 'selected') {
            $data['shared_user_ids'] = [];
        }

        return $data;
    }

    private function validateAttachments(array $attachments): array
    {
        foreach ($attachments as &$attachment) {
            $type = $attachment['type'] ?? null;
            abort_unless(($type === 'ref' && ! empty($attachment['ref'])) || ($type === 'url' && ! empty($attachment['url'])), 422, 'Revise os anexos da nota.');
            if ($type === 'url') {
                $parts = parse_url($attachment['url']);
                abort_unless($parts && ($parts['scheme'] ?? null) === 'https' && ! isset($parts['user']) && ! isset($parts['pass']), 422, 'Anexos externos devem usar URL HTTPS segura.');
            }
            $attachment = array_filter([
                'type' => $type,
                'label' => isset($attachment['label']) ? mb_substr((string) $attachment['label'], 0, 160) : null,
                'ref' => $type === 'ref' ? $attachment['ref'] : null,
                'url' => $type === 'url' ? $attachment['url'] : null,
            ], fn ($value) => $value !== null && $value !== '');
        }
        unset($attachment);

        return $attachments;
    }

    public function destroy(Request $request, Journal $journal): JsonResponse
    {
        $this->gm($request, $journal->campaign);
        $journal->delete();

        return response()->json([], 204);
    }

    private function role(Request $request, Campaign $campaign): string
    {
        return $campaign->roleFor($request->user()) ?? abort(403, 'Você não faz parte desta campanha.');
    }

    private function gm(Request $request, Campaign $campaign): void
    {
        if ($this->role($request, $campaign) !== 'gm') {
            abort(403, 'Apenas o GM pode editar o diário.');
        }
    }
}
