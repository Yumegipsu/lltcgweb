<?php

declare(strict_types=1);

/**
 * Tiny two-head net trained on ranked replay actions.
 * Policy head: would a ranked player take this line.
 * Value head: did that line improve their success race.
 * Weights only — no Discord ids, names, or room ids.
 */
final class CpuActionNet
{
    public const FEATURES = 20;
    public const HIDDEN = 8;

    /** @return array<string,mixed> */
    public static function emptyWeights(): array
    {
        return [
            'version' => 1,
            'features' => self::FEATURES,
            'hidden' => self::HIDDEN,
            'samples' => 0,
            'w1' => array_fill(0, self::HIDDEN, array_fill(0, self::FEATURES, 0.0)),
            'b1' => array_fill(0, self::HIDDEN, 0.0),
            'w_take' => array_fill(0, self::HIDDEN, 0.0),
            'b_take' => 0.0,
            'w_value' => array_fill(0, self::HIDDEN, 0.0),
            'b_value' => 0.0,
        ];
    }

    /**
     * @param list<array<string,mixed>> $payloads
     * @return array<string,mixed>
     */
    public static function train(array $payloads, int $epochs = 36): array
    {
        $rows = [];
        foreach ($payloads as $payload) {
            if (!is_array($payload) || (class_exists('CpuPolicy') && CpuPolicy::payloadIsCpu($payload))) {
                continue;
            }
            foreach (self::examplesFromPayload($payload) as $row) {
                $rows[] = $row;
            }
        }
        $net = self::init(count($rows));
        if ($rows === []) {
            $net['samples'] = 0;
            return $net;
        }
        $n = count($rows);
        $lr = 0.08;
        for ($epoch = 0; $epoch < $epochs; $epoch++) {
            self::shuffle($rows, 1700 + $epoch);
            foreach ($rows as $row) {
                self::sgd($net, $row['x'], (float)$row['take'], (float)$row['value'], $lr);
            }
            $lr *= 0.97;
        }
        $net['samples'] = $n;
        $net['source'] = 'ranked_actions';
        return $net;
    }

    /**
     * @param array<string,mixed> $weights
     * @param list<float> $x
     * @return array{take:float,value:float,score:float}
     */
    public static function judge(array $weights, array $x): array
    {
        $x = self::pad($x);
        $h = self::hidden($weights, $x);
        $take = self::sigmoid(self::dot($weights['w_take'] ?? [], $h) + (float)($weights['b_take'] ?? 0));
        $value = self::tanhClip(self::dot($weights['w_value'] ?? [], $h) + (float)($weights['b_value'] ?? 0));
        return [
            'take' => $take,
            'value' => $value,
            'score' => ($take - 0.5) * 2.0 + $value * 0.6,
        ];
    }

    /**
     * @param array<string,mixed> $sit
     * @return list<float>
     */
    public static function features(array $sit): array
    {
        $kind = (string)($sit['kind'] ?? '');
        $my = (int)($sit['my_success'] ?? 0);
        $opp = (int)($sit['opp_success'] ?? 0);
        $turn = (int)($sit['turn'] ?? 1);
        return [
            1.0,
            min(1.0, max(0.0, $turn / 12)),
            min(1.0, $my / 3),
            min(1.0, $opp / 3),
            max(-1.0, min(1.0, ($my - $opp) / 3)),
            min(1.0, ((int)($sit['empty_slots'] ?? 0)) / 3),
            min(1.0, ((int)($sit['hearts'] ?? 0)) / 6),
            min(1.0, ((int)($sit['opp_hearts'] ?? 0)) / 6),
            min(1.0, ((int)($sit['cost'] ?? 0)) / 15),
            min(1.0, ((int)($sit['score'] ?? 0)) / 4),
            min(1.0, ((int)($sit['blade'] ?? 0)) / 4),
            $kind === 'play_member' ? 1.0 : 0.0,
            $kind === 'activate' ? 1.0 : 0.0,
            $kind === 'end_main' ? 1.0 : 0.0,
            $kind === 'live_set' ? 1.0 : 0.0,
            $kind === 'prompt' ? 1.0 : 0.0,
            !empty($sit['can_clear']) ? 1.0 : 0.0,
            $opp > $my ? 1.0 : 0.0,
            $opp >= 2 ? 1.0 : 0.0,
            min(1.0, ((int)($sit['hand'] ?? 0)) / 8),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array{x:list<float>,take:float,value:float}>
     */
    public static function examplesFromPayload(array $payload): array
    {
        $baseline = is_array($payload['baseline'] ?? null) ? $payload['baseline'] : [];
        $frames = is_array($payload['frames'] ?? null) ? $payload['frames'] : [];
        $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];
        $out = [];
        $board = $baseline;
        foreach ($actions as $i => $action) {
            if (!is_array($action)) {
                continue;
            }
            $type = (string)($action['type'] ?? '');
            if (in_array($type, ['send_stamp', 'ack_coin_flip', 'live_show_ack', 'anti_softlock_skip', 'force_own_timeout', 'request_rematch'], true)) {
                continue;
            }
            $pid = (string)($action['player'] ?? '');
            if ($pid !== 'p1' && $pid !== 'p2') {
                $next = is_array($frames[$i + 1] ?? null) ? $frames[$i + 1] : null;
                if ($next) {
                    $board = $next;
                }
                continue;
            }
            $prior = $i === 0 ? $baseline : (is_array($frames[$i] ?? null) ? $frames[$i] : $board);
            $sit = self::situation($prior, $pid, $type, $action);
            if ($sit !== null) {
                $value = self::valueAfter($actions, $frames, $i, $pid, $prior);
                $x = self::features($sit);
                $out[] = ['x' => $x, 'take' => 1.0, 'value' => $value];
                $neg = $sit;
                $neg['kind'] = $sit['kind'] === 'end_main' ? 'play_member' : 'end_main';
                $neg['can_clear'] = false;
                $out[] = ['x' => self::features($neg), 'take' => 0.0, 'value' => 0.15];
            }
            $next = is_array($frames[$i + 1] ?? null) ? $frames[$i + 1] : null;
            if ($next) {
                $board = $next;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $weights
     * @param list<float> $x
     * @return list<float>
     */
    private static function hidden(array $weights, array $x): array
    {
        $w1 = $weights['w1'] ?? [];
        $b1 = $weights['b1'] ?? [];
        $h = [];
        for ($j = 0; $j < self::HIDDEN; $j++) {
            $row = is_array($w1[$j] ?? null) ? $w1[$j] : [];
            $h[] = self::tanhClip(self::dot($row, $x) + (float)($b1[$j] ?? 0));
        }
        return $h;
    }

    /**
     * @param array<string,mixed> $net
     * @param list<float> $x
     */
    private static function sgd(array &$net, array $x, float $takeY, float $valueY, float $lr): void
    {
        $x = self::pad($x);
        $h = self::hidden($net, $x);
        $takeLogit = self::dot($net['w_take'], $h) + (float)$net['b_take'];
        $valueLogit = self::dot($net['w_value'], $h) + (float)$net['b_value'];
        $take = self::sigmoid($takeLogit);
        $value = self::tanhClip($valueLogit);
        $dTake = ($take - $takeY) * $take * (1 - $take);
        $dValue = ($value - $valueY) * (1 - $value * $value);
        for ($j = 0; $j < self::HIDDEN; $j++) {
            $net['w_take'][$j] -= $lr * $dTake * $h[$j];
            $net['w_value'][$j] -= $lr * $dValue * $h[$j];
        }
        $net['b_take'] -= $lr * $dTake;
        $net['b_value'] -= $lr * $dValue;
        $w1 = $net['w1'];
        for ($j = 0; $j < self::HIDDEN; $j++) {
            $dh = (1 - $h[$j] * $h[$j]) * (
                $dTake * (float)$net['w_take'][$j] + $dValue * (float)$net['w_value'][$j]
            );
            $net['b1'][$j] -= $lr * $dh;
            for ($i = 0; $i < self::FEATURES; $i++) {
                $w1[$j][$i] -= $lr * $dh * $x[$i];
            }
        }
        $net['w1'] = $w1;
    }

    /** @param array<string,mixed> $board
     * @param array<string,mixed> $action
     * @return array<string,mixed>|null
     */
    private static function situation(array $board, string $pid, string $type, array $action): ?array
    {
        $kind = self::kindOf($type, $action);
        if ($kind === null) {
            return null;
        }
        $me = is_array($board['players'][$pid] ?? null) ? $board['players'][$pid] : [];
        $oppId = $pid === 'p1' ? 'p2' : 'p1';
        $opp = is_array($board['players'][$oppId] ?? null) ? $board['players'][$oppId] : [];
        $card = self::actionCard($me, $action);
        $hearts = self::heartCount($me);
        $req = is_array($card) ? self::heartCost($card) : 0;
        return [
            'kind' => $kind,
            'turn' => (int)($board['turn'] ?? $action['turn'] ?? 1),
            'my_success' => self::successCount($me),
            'opp_success' => self::successCount($opp),
            'empty_slots' => self::emptySlots($me),
            'hearts' => $hearts,
            'opp_hearts' => self::heartCount($opp),
            'cost' => (int)($card['cost'] ?? 0),
            'score' => (int)($card['score'] ?? 0),
            'blade' => (int)($card['blade'] ?? 0),
            'can_clear' => $kind === 'live_set' && $req > 0 && $req <= $hearts,
            'hand' => is_array($me['hand'] ?? null) ? count($me['hand']) : (int)($me['hand_count'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $action */
    private static function kindOf(string $type, array $action): ?string
    {
        if ($type === 'play_member') {
            return 'play_member';
        }
        if ($type === 'activate_ability') {
            return 'activate';
        }
        if ($type === 'end_main') {
            return 'end_main';
        }
        if ($type === 'set_live_cards') {
            $ids = $action['data']['card_ids'] ?? [];
            return is_array($ids) && $ids !== [] ? 'live_set' : null;
        }
        if ($type === 'resolve_prompt' || $type === 'live_start_choice') {
            $choice = strtolower((string)($action['data']['choice'] ?? ''));
            if ($choice === 'no' || $choice === 'skip') {
                return null;
            }
            return 'prompt';
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $actions
     * @param list<mixed> $frames
     * @param array<string,mixed> $prior
     */
    private static function valueAfter(array $actions, array $frames, int $index, string $pid, array $prior): float
    {
        $me0 = self::successCount(is_array($prior['players'][$pid] ?? null) ? $prior['players'][$pid] : []);
        $oppId = $pid === 'p1' ? 'p2' : 'p1';
        $opp0 = self::successCount(is_array($prior['players'][$oppId] ?? null) ? $prior['players'][$oppId] : []);
        $limit = min(count($actions), $index + 12);
        for ($j = $index + 1; $j < $limit; $j++) {
            $frame = is_array($frames[$j] ?? null) ? $frames[$j] : null;
            if (!$frame) {
                continue;
            }
            $me = self::successCount(is_array($frame['players'][$pid] ?? null) ? $frame['players'][$pid] : []);
            $opp = self::successCount(is_array($frame['players'][$oppId] ?? null) ? $frame['players'][$oppId] : []);
            if ($me > $me0 && $opp <= $opp0) {
                return 1.0;
            }
            if ($opp > $opp0 && $me <= $me0) {
                return 0.0;
            }
        }
        return 0.45;
    }

    /** @param array<string,mixed> $me
     * @param array<string,mixed> $action
     * @return array<string,mixed>|null
     */
    private static function actionCard(array $me, array $action): ?array
    {
        $id = (string)($action['data']['card_id'] ?? '');
        $ids = $action['data']['card_ids'] ?? [];
        if ($id === '' && is_array($ids) && $ids) {
            $id = (string)$ids[0];
        }
        if ($id === '') {
            return null;
        }
        foreach (['hand', 'stage'] as $zone) {
            $cards = $me[$zone] ?? [];
            if ($zone === 'stage' && is_array($cards)) {
                $cards = array_values($cards);
            }
            if (!is_array($cards)) {
                continue;
            }
            foreach ($cards as $c) {
                if (is_array($c) && (string)($c['instance_id'] ?? '') === $id) {
                    return $c;
                }
            }
        }
        return null;
    }

    /** @param array<string,mixed> $p */
    private static function successCount(array $p): int
    {
        $lives = $p['success_lives'] ?? [];
        return is_array($lives) ? count($lives) : 0;
    }

    /** @param array<string,mixed> $p */
    private static function emptySlots(array $p): int
    {
        $n = 0;
        foreach (['left', 'center', 'right'] as $slot) {
            if (empty($p['stage'][$slot])) {
                $n++;
            }
        }
        return $n;
    }

    /** @param array<string,mixed> $p */
    private static function heartCount(array $p): int
    {
        $n = 0;
        $stage = $p['stage'] ?? [];
        if (!is_array($stage)) {
            return 0;
        }
        foreach ($stage as $m) {
            if (!is_array($m)) {
                continue;
            }
            $hearts = $m['hearts'] ?? [];
            if (!is_array($hearts)) {
                continue;
            }
            foreach ($hearts as $h) {
                if (is_array($h)) {
                    $n += max(1, (int)($h['count'] ?? 1));
                } else {
                    $n++;
                }
            }
        }
        return $n;
    }

    /** @param array<string,mixed> $card */
    private static function heartCost(array $card): int
    {
        $req = $card['required_hearts'] ?? $card['hearts'] ?? [];
        if (!is_array($req)) {
            return 0;
        }
        $n = 0;
        foreach ($req as $h) {
            $n += is_array($h) ? max(1, (int)($h['count'] ?? 1)) : 1;
        }
        return $n;
    }

    /** @return array<string,mixed> */
    private static function init(int $samples): array
    {
        $net = self::emptyWeights();
        $rng = 16601 + $samples;
        $scale = 0.15;
        for ($j = 0; $j < self::HIDDEN; $j++) {
            $net['b1'][$j] = self::next($rng) * 0.02;
            for ($i = 0; $i < self::FEATURES; $i++) {
                $net['w1'][$j][$i] = self::next($rng) * $scale;
            }
            $net['w_take'][$j] = self::next($rng) * $scale;
            $net['w_value'][$j] = self::next($rng) * $scale;
        }
        return $net;
    }

    /** @param list<array{x:list<float>,take:float,value:float}> $rows */
    private static function shuffle(array &$rows, int $seed): void
    {
        $rng = $seed;
        for ($i = count($rows) - 1; $i > 0; $i--) {
            $rng = (1103515245 * $rng + 12345) & 0x7fffffff;
            $j = $rng % ($i + 1);
            $tmp = $rows[$i];
            $rows[$i] = $rows[$j];
            $rows[$j] = $tmp;
        }
    }

    private static function next(int &$rng): float
    {
        $rng = (1103515245 * $rng + 12345) & 0x7fffffff;
        return ($rng / 0x7fffffff) * 2 - 1;
    }

    /** @param list<float>|array<int,float> $w
     * @param list<float> $x
     */
    private static function dot(array $w, array $x): float
    {
        $s = 0.0;
        $n = min(count($w), count($x));
        for ($i = 0; $i < $n; $i++) {
            $s += (float)$w[$i] * (float)$x[$i];
        }
        return $s;
    }

    /** @param list<float> $x
     * @return list<float>
     */
    private static function pad(array $x): array
    {
        $out = [];
        for ($i = 0; $i < self::FEATURES; $i++) {
            $out[] = (float)($x[$i] ?? 0);
        }
        return $out;
    }

    private static function sigmoid(float $z): float
    {
        $z = max(-20.0, min(20.0, $z));
        return 1.0 / (1.0 + exp(-$z));
    }

    private static function tanhClip(float $z): float
    {
        $z = max(-20.0, min(20.0, $z));
        return tanh($z);
    }
}
