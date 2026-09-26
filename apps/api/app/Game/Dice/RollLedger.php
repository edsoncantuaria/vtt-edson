<?php

namespace App\Game\Dice;

use App\Models\Scene;
use App\Models\User;
use App\Support\Dnd\HouseRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Immutable, server-evaluated roll record; caller must hold the scene write transaction. */
final class RollLedger
{
    public function __construct(private readonly DiceRoller $dice) {}

    /**
     * @param array{requestId:string,step?:string,context:string,formula:string,actorId?:int|null,recipientUserId?:int|null,
     *   visibility?:string,mode?:string,modifier?:int,extraDice?:string,label?:string|null,houseRules?:array} $input
     * @return array<string,mixed>
     */
    public function roll(Scene $scene, User $user, array $input): array
    {
        $step = $input['step'] ?? 'roll';
        $mode = $input['mode'] ?? 'normal';
        $visibility = $input['visibility'] ?? 'public';
        $modifier = $input['modifier'] ?? 0;
        $extra = trim($input['extraDice'] ?? '');
        if (! in_array($mode, ['normal', 'advantage', 'disadvantage'], true)
            || ! in_array($visibility, ['public', 'gm', 'private'], true)
            || ! in_array($input['context'], ['custom', 'ability', 'skill', 'save', 'death-save', 'initiative', 'attack', 'damage', 'heal', 'concentration'], true)
            || ! is_int($modifier) || abs($modifier) > 100
            || ! preg_match('/^[\w:-]{1,80}$/', $step)) {
            throw new InvalidArgumentException('Opções de rolagem inválidas.');
        }
        if ($visibility === 'private' && ! ($input['recipientUserId'] ?? null)) {
            throw new InvalidArgumentException('Escolha um destinatário para a rolagem privada.');
        }
        if ($extra !== '' && ! preg_match('/^\d*d(?:4|6|8|10|12|20|100)$/i', $extra)) {
            throw new InvalidArgumentException('Dado extra inválido.');
        }
        $original = trim($input['formula']);
        $label = isset($input['label']) ? Str::limit((string) $input['label'], 80, '') : null;
        if (strlen($original) > 120) {
            throw new InvalidArgumentException('Fórmula de dados longa demais.');
        }
        $formula = $original;
        if ($mode !== 'normal' && ! ($input['modePrepared'] ?? false)) {
            if (! preg_match('/^(?:1)?d20([+-]\d+)?$/i', $formula, $match)) {
                throw new InvalidArgumentException('Vantagem/desvantagem exige uma fórmula d20±K.');
            }
            $formula = ($mode === 'advantage' ? '2d20kh1' : '2d20kl1').($match[1] ?? '');
        }
        if ($modifier !== 0) {
            $formula .= ($modifier > 0 ? '+' : '').$modifier;
        }
        if ($extra !== '') {
            $formula .= '+'.$extra;
        }
        $explicitRules = $input['houseRules'] ?? null;
        $request = [
            'context' => $input['context'], 'actorId' => $input['actorId'] ?? null,
            'recipientUserId' => $input['recipientUserId'] ?? null,
            'visibility' => $visibility, 'mode' => $mode, 'formula' => $original,
            'modifier' => $modifier, 'extraDice' => $extra, 'label' => $label,
            'houseRules' => $explicitRules, 'modePrepared' => $input['modePrepared'] ?? false,
        ];
        $hash = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
        $existing = DB::table('roll_records')->where([
            'scene_id' => $scene->id, 'user_id' => $user->id,
            'request_id' => $input['requestId'], 'step' => $step,
        ])->first();
        if ($existing) {
            abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Essa chave já identifica outra rolagem.');

            return [...$this->payload($existing), 'replayed' => true];
        }

        if ($explicitRules === null) {
            $adjusted = HouseRules::apply($formula, $input['label'] ?? '', $scene->campaign->house_rules ?? []);
            $formula = $adjusted['formula'];
            $rules = $adjusted['rules'];
        } else {
            $rules = $explicitRules;
        }
        if (strlen($formula) > 240) {
            throw new InvalidArgumentException('A fórmula efetiva excede o tamanho permitido.');
        }
        $result = $this->dice->roll($formula);
        $id = (string) Str::uuid();
        $record = [
            'id' => $id, 'scene_id' => $scene->id, 'user_id' => $user->id,
            'actor_id' => $input['actorId'] ?? null, 'recipient_user_id' => $input['recipientUserId'] ?? null,
            'request_id' => $input['requestId'], 'step' => $step, 'context' => $input['context'],
            'edition' => $scene->campaign->ruleset ?? '5e-2014',
            'visibility' => $visibility, 'mode' => $mode,
            'formula' => $original, 'effective_formula' => $result['formula'],
            'modifier' => $modifier, 'extra_dice' => $extra ?: null,
            'label' => $label, 'house_rules' => json_encode($rules, JSON_THROW_ON_ERROR),
            'result' => json_encode($result, JSON_THROW_ON_ERROR), 'request_hash' => $hash,
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('roll_records')->insert($record);

        return [...$this->payload((object) $record), 'replayed' => false];
    }

    /** @param array<string,mixed> $roll
     * @return array<string,mixed>
     */
    public function chatMessage(array $roll, User $user, ?string $text = null): array
    {
        return [
            'id' => $roll['id'], 'rollId' => $roll['id'], 'userId' => $user->id,
            'userName' => $user->name, 'type' => 'roll',
            ...($roll['actorId'] !== null ? ['sourceActorId' => $roll['actorId']] : []),
            'context' => $roll['context'], 'mode' => $roll['mode'],
            'houseRules' => $roll['houseRules'], 'formula' => $roll['formula'],
            'total' => $roll['total'], 'detail' => $roll['detail'],
            'critical' => $roll['critical'], 'fumble' => $roll['fumble'],
            'label' => $roll['label'], 'text' => $text ?? $roll['detail'],
            'createdAt' => $roll['createdAt'],
        ];
    }

    /** @return array<string,mixed> */
    public function payload(object $record): array
    {
        return [
            'id' => $record->id, 'sceneId' => (int) $record->scene_id,
            'actorId' => $record->actor_id === null ? null : (int) $record->actor_id,
            'userId' => (int) $record->user_id, 'recipientUserId' => $record->recipient_user_id === null ? null : (int) $record->recipient_user_id,
            'context' => $record->context, 'step' => $record->step, 'mode' => $record->mode,
            'edition' => $record->edition,
            'visibility' => $record->visibility, 'label' => $record->label,
            'inputFormula' => $record->formula, 'modifier' => (int) $record->modifier,
            'extraDice' => $record->extra_dice, 'houseRules' => json_decode($record->house_rules, true),
            ...json_decode($record->result, true), 'createdAt' => Carbon::parse($record->created_at)->toIso8601String(),
        ];
    }
}
