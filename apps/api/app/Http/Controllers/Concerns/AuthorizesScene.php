<?php

namespace App\Http\Controllers\Concerns;

use App\Models\CampaignResourcePermission;
use App\Models\Scene;
use App\Models\SceneMember;
use Illuminate\Http\Request;

trait AuthorizesScene
{
    private function requireMember(Request $request, Scene $scene): SceneMember
    {
        $member = $scene->memberFor($request->user());
        if (! $member) {
            abort(403, 'Você não faz parte desta cena.');
        }
        $canViewPreparation = $member->isGm()
            || CampaignResourcePermission::permits($scene->campaign, $request->user(), 'scene', $scene->id, 'view');
        abort_unless($scene->published || $canViewPreparation, 403, 'Esta cena ainda está em preparação.');

        return $member;
    }

    private function requireParticipant(Request $request, Scene $scene): SceneMember
    {
        $member = $this->requireMember($request, $scene);
        abort_if($member->role === 'observer', 403, 'Observadores possuem acesso somente de leitura.');

        return $member;
    }

    private function requireSceneEditor(Request $request, Scene $scene): SceneMember
    {
        $member = $this->requireParticipant($request, $scene);
        abort_unless(
            $member->isGm()
            || CampaignResourcePermission::permits($scene->campaign, $request->user(), 'scene', $scene->id, 'edit'),
            403,
            'Você não possui permissão para editar esta cena.',
        );

        return $member;
    }

    private function requireGm(Request $request, Scene $scene): SceneMember
    {
        $member = $this->requireParticipant($request, $scene);
        if (! $member->isGm()) {
            abort(403, 'Apenas o GM pode fazer isso.');
        }

        return $member;
    }
}
