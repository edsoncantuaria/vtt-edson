<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Scene;
use App\Models\SceneMember;
use Illuminate\Http\Request;

/** Checagem de papel (gm|player) repetida por todo controller que mexe no estado de uma Scene. */
trait AuthorizesScene
{
    private function requireMember(Request $request, Scene $scene): SceneMember
    {
        $member = $scene->memberFor($request->user());
        if (! $member) {
            abort(403, 'Você não faz parte desta cena.');
        }

        abort_unless($member->isGm() || $scene->published, 403, 'Esta cena ainda está em preparação.');

        return $member;
    }

    private function requireGm(Request $request, Scene $scene): SceneMember
    {
        $member = $this->requireMember($request, $scene);
        if (! $member->isGm()) {
            abort(403, 'Apenas o GM pode fazer isso.');
        }

        return $member;
    }
}
