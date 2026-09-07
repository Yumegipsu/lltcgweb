<?php

declare(strict_types=1);

/**
 * Aggregated ranked-play policy for the CPU. No Discord ids, names, or room ids.
 * Empty buckets fall back to the heuristic prior so a thin corpus still plays.
 */
final class CpuPolicy
{
    public const VERSION = 1;
    public const RANK_FLOOR = 1100;
    public const RANK_MIN_GAMES = 8;
    public const RANKED_AUTOSAVE_KEEP = 40;

    /** @return array<string,mixed> */
    public static function prior(): array
    {
        return [
            'version' => self::VERSION,
            'source' => 'prior',
            'corpus' => [
                'replays' => 0,
                'held_out' => 0,
                'live_set_agreement' => null,
                'clearable_commit_agreement' => null,
            ],
            'mulligan' => [
                'return_live' => 0.22,
                'return_low_cost_member' => 0.18,
                'return_high_cost_member' => 0.62,
                'keep_score2_live' => 0.84,
            ],
            'main' => [
                'play_vs_hold' => [
                    'early_low' => 0.72,
                    'early_high' => 0.28,
                    'behind_any' => 0.66,
                    'ahead_hold' => 0.41,
                ],
                'activate' => [
                    'draw' => ['yes' => 0.78, 'need_live' => 0.88, 'behind' => 0.84, 'opp2' => 0.7],
                    'surveil' => ['yes' => 0.7, 'need_live' => 0.86, 'behind' => 0.8, 'opp2' => 0.62],
                    'blade' => ['yes' => 0.74, 'need_live' => 0.55, 'behind' => 0.6, 'opp2' => 0.5],
                    'heart' => ['yes' => 0.8, 'need_live' => 0.9, 'behind' => 0.86, 'opp2' => 0.72],
                    'wait' => ['yes' => 0.46, 'need_live' => 0.3, 'behind' => 0.58, 'opp2' => 0.82],
                    'live_score' => ['yes' => 0.68, 'need_live' => 0.4, 'behind' => 0.5, 'opp2' => 0.55],
                    'energy' => ['yes' => 0.6, 'need_live' => 0.5, 'behind' => 0.64, 'opp2' => 0.48],
                ],
            ],
            'live' => [
                'clearable_commit' => 0.9,
                'yell_only_commit' => 0.32,
                'bluff_when_no_clear' => 0.14,
                'bluff_when_opp2' => 0.22,
                'prefer_high_score_when_both_clear' => 0.92,
                'take_low_score_at_two_successes' => 0.96,
                'score_band' => ['low' => 0.22, 'mid' => 0.48, 'high' => 0.3],
                'count_when_clearable' => [1 => 0.34, 2 => 0.46, 3 => 0.2],
            ],
            'prompts' => [
                'yes' => [
                    'optional_live_start' => 0.62,
                    'optional_discard_prompt' => 0.48,
                    'optional_pay_energy_on_enter' => 0.55,
                    'default' => 0.5,
                ],
                'keep_live_on_discard' => 0.84,
                'discard_member_first' => 0.78,
            ],
            'cards' => [
                'members' => [],
                'lives' => [],
            ],
        ];
    }

    /** @param array<string,mixed> $policy */
    public static function normalize(array $policy): array
    {
        $base = self::prior();
        $out = array_replace_recursive($base, $policy);
        $out['version'] = self::VERSION;
        $out['cards']['members'] = is_array($out['cards']['members'] ?? null) ? $out['cards']['members'] : [];
        $out['cards']['lives'] = is_array($out['cards']['lives'] ?? null) ? $out['cards']['lives'] : [];
        return $out;
    }

    /** @return array<string,mixed> */
    public static function loadFile(?string $path = null): array
    {
        $path = $path ?: self::defaultJsonPath();
        if (!is_file($path)) {
            return self::prior();
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return self::prior();
        }
        $data = json_decode($raw, true);
        return is_array($data) ? self::normalize($data) : self::prior();
    }

    public static function defaultJsonPath(): string
    {
        return dirname(__DIR__, 2) . '/client/js/cpu_policy.json';
    }

    public static function cardRate(array $policy, string $kind, string $cardNo): float
    {
        $no = self::stripCardNo($cardNo);
        $row = $policy['cards'][$kind][$no] ?? $policy['cards'][$kind][$cardNo] ?? null;
        if (!is_array($row)) {
            return 0.0;
        }
        return max(0.0, min(1.0, (float)($row['rate'] ?? 0)));
    }

    public static function memberBonus(array $policy, string $cardNo, string $tier): int
    {
        $rate = self::cardRate($policy, 'members', $cardNo);
        if ($rate <= 0) {
            return 0;
        }
        $mul = $tier === 'hard' || $tier === 'expert' ? 14 : ($tier === 'normal' ? 8 : 2);
        return (int)round($rate * $mul);
    }

    public static function liveBonus(array $policy, string $cardNo, string $tier): int
    {
        $rate = self::cardRate($policy, 'lives', $cardNo);
        if ($rate <= 0) {
            return 0;
        }
        $mul = $tier === 'hard' || $tier === 'expert' ? 16 : ($tier === 'normal' ? 9 : 2);
        return (int)round($rate * $mul);
    }

    /**
     * @param list<array<string,mixed>> $payloads replay export payloads
     * @return array{policy: array<string,mixed>, report: array<string,mixed>}
     */
    public static function aggregatePayloads(array $payloads, float $holdOutFrac = 0.2): array
    {
        $usable = [];
        foreach ($payloads as $payload) {
            if (!is_array($payload) || self::payloadIsCpu($payload)) {
                continue;
            }
            $usable[] = $payload;
        }
        $n = count($usable);
        $hold = $n >= 5 ? max(1, (int)floor($n * $holdOutFrac)) : 0;
        $train = $hold > 0 ? array_slice($usable, 0, $n - $hold) : $usable;
        $held = $hold > 0 ? array_slice($usable, $n - $hold) : [];

        $acc = self::emptyAccum();
        foreach ($train as $payload) {
            self::absorbPayload($acc, $payload);
        }
        $policy = self::finishAccum($acc, count($train));
        $report = self::heldOutReport($held, $policy);
        $policy['corpus']['replays'] = count($train);
        $policy['corpus']['held_out'] = count($held);
        $policy['corpus']['live_set_agreement'] = $report['live_set_agreement'];
        $policy['corpus']['clearable_commit_agreement'] = $report['clearable_commit_agreement'];
        $policy['source'] = count($train) > 0 ? 'ranked_replays' : 'prior';
        return ['policy' => $policy, 'report' => $report];
    }

    public static function payloadIsCpu(array $payload): bool
    {
        $mode = strtolower((string)($payload['meta']['mode'] ?? ''));
        if ($mode === 'cpu' || str_contains($mode, 'cpu')) {
            return true;
        }
        if (!empty($payload['meta']['cpu_difficulty'])) {
            return true;
        }
        foreach (['p1', 'p2'] as $pid) {
            $p = $payload['baseline']['players'][$pid] ?? null;
            if (!is_array($p)) {
                continue;
            }
            if (!empty($p['is_cpu'])) {
                return true;
            }
            $name = (string)($p['name'] ?? '');
            if (str_contains($name, 'CPU') || str_contains($name, '🤖') || str_starts_with($name, 'COM')) {
                return true;
            }
        }
        return false;
    }

    public static function payloadIsRanked(array $payload): bool
    {
        $mode = strtolower((string)($payload['meta']['mode'] ?? $payload['baseline']['mode'] ?? ''));
        if ($mode === 'ranked') {
            return true;
        }
        $gm = strtolower((string)($payload['baseline']['game_mode'] ?? ''));
        return $gm === 'standard' && $mode === 'ranked';
    }

    public static function stripCardNo(string $cardNo): string
    {
        $no = preg_replace('/[＋+].*$/u', '', $cardNo) ?? $cardNo;
        $no = preg_replace('/-(SEC|SECL|SRL|RM|SD2|SD|PR|P|R|N|L|C)\d*$/i', '', $no) ?? $no;
        return $no;
    }

    /** @return array<string,mixed> */
    private static function emptyAccum(): array
    {
        return [
            'member_n' => [],
            'live_n' => [],
            'decks' => 0,
            'mulligan' => [
                'live' => [0, 0],
                'low' => [0, 0],
                'high' => [0, 0],
                'keep2' => [0, 0],
            ],
            'activate' => [],
            'live_commit' => ['clear' => [0, 0], 'yell' => [0, 0], 'bluff' => [0, 0]],
            'score_band' => ['low' => 0, 'mid' => 0, 'high' => 0],
            'live_count' => [1 => 0, 2 => 0, 3 => 0],
            'prompts_yes' => [],
            'prompts_n' => [],
            'discard_live' => [0, 0],
        ];
    }

    /** @param array<string,mixed> $acc */
    private static function absorbPayload(array &$acc, array $payload): void
    {
        $baseline = is_array($payload['baseline'] ?? null) ? $payload['baseline'] : [];
        $frames = is_array($payload['frames'] ?? null) ? $payload['frames'] : [];
        $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];
        self::absorbDecks($acc, $baseline);
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
            $prior = $i === 0 ? $baseline : (is_array($frames[$i] ?? null) ? $frames[$i] : $board);
            if ($type === 'mulligan') {
                self::absorbMulligan($acc, $prior, $pid, $action);
            } elseif ($type === 'set_live_cards') {
                self::absorbLiveSet($acc, $prior, $pid, $action);
            } elseif ($type === 'resolve_prompt' || $type === 'live_start_choice') {
                self::absorbPrompt($acc, $prior, $pid, $action);
            } elseif ($type === 'activate_ability') {
                self::absorbActivate($acc, $prior, $pid, $action);
            }
            $next = is_array($frames[$i + 1] ?? null) ? $frames[$i + 1] : null;
            if ($next) {
                $board = $next;
            }
        }
    }

    /** @param array<string,mixed> $acc */
    private static function absorbDecks(array &$acc, array $baseline): void
    {
        $acc['decks']++;
        foreach (['p1', 'p2'] as $pid) {
            $snap = $baseline['players'][$pid]['deck_snapshot'] ?? null;
            $main = is_array($snap) ? ($snap['main_deck'] ?? $snap['main'] ?? null) : null;
            if (!is_array($main)) {
                continue;
            }
            $seen = [];
            foreach ($main as $no) {
                if (!is_string($no) || $no === '') {
                    continue;
                }
                $key = self::stripCardNo($no);
                $seen[$key] = true;
            }
            foreach (array_keys($seen) as $key) {
                // Kind unknown without catalog — counted later if we see them as Live/Member in zones.
                $acc['member_n'][$key] = ($acc['member_n'][$key] ?? 0) + 1;
            }
        }
    }

    /** @param array<string,mixed> $acc */
    private static function absorbMulligan(array &$acc, array $board, string $pid, array $action): void
    {
        $hand = $board['players'][$pid]['hand'] ?? [];
        if (!is_array($hand)) {
            return;
        }
        $ids = $action['data']['card_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $idSet = array_fill_keys(array_map('strval', $ids), true);
        foreach ($hand as $c) {
            if (!is_array($c)) {
                continue;
            }
            $iid = (string)($c['instance_id'] ?? '');
            $returned = $iid !== '' && isset($idSet[$iid]);
            $isLive = self::isLive($c);
            $cost = (int)($c['cost'] ?? 0);
            $score = (int)($c['score'] ?? 0);
            if ($isLive) {
                $acc['mulligan']['live'][$returned ? 0 : 1]++;
                if ($score >= 2) {
                    $acc['mulligan']['keep2'][$returned ? 1 : 0]++;
                }
            } elseif ($cost <= 2) {
                $acc['mulligan']['low'][$returned ? 0 : 1]++;
            } elseif ($cost >= 6) {
                $acc['mulligan']['high'][$returned ? 0 : 1]++;
            }
        }
    }

    /** @param array<string,mixed> $acc */
    private static function absorbLiveSet(array &$acc, array $board, string $pid, array $action): void
    {
        $ids = $action['data']['card_ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return;
        }
        $hand = $board['players'][$pid]['hand'] ?? [];
        $byId = [];
        if (is_array($hand)) {
            foreach ($hand as $c) {
                if (is_array($c) && !empty($c['instance_id'])) {
                    $byId[(string)$c['instance_id']] = $c;
                }
            }
        }
        $me = $board['players'][$pid] ?? [];
        $hearts = self::stageHeartCount(is_array($me) ? $me : []);
        $setLives = [];
        foreach ($ids as $id) {
            $c = $byId[(string)$id] ?? null;
            if (!is_array($c) || !self::isLive($c)) {
                continue;
            }
            $setLives[] = $c;
            $band = self::scoreBand((int)($c['score'] ?? 0));
            $acc['score_band'][$band]++;
            $key = self::stripCardNo((string)($c['card_no'] ?? ''));
            if ($key !== '') {
                $acc['live_n'][$key] = ($acc['live_n'][$key] ?? 0) + 1;
            }
        }
        if ($setLives === []) {
            $acc['live_commit']['bluff'][1]++;
            return;
        }
        $n = min(3, count($setLives));
        $acc['live_count'][$n] = ($acc['live_count'][$n] ?? 0) + 1;
        $clearable = false;
        foreach ($setLives as $c) {
            if (self::heartCost($c) <= $hearts) {
                $clearable = true;
                break;
            }
        }
        if ($clearable) {
            $acc['live_commit']['clear'][0]++;
        } elseif ($hearts > 0) {
            $acc['live_commit']['yell'][0]++;
        } else {
            $acc['live_commit']['clear'][1]++;
        }
    }

    /** @param array<string,mixed> $acc */
    private static function absorbPrompt(array &$acc, array $board, string $pid, array $action): void
    {
        $pr = $board['pending_prompt'] ?? null;
        if (!is_array($pr)) {
            return;
        }
        $ptype = (string)($pr['type'] ?? 'default');
        $data = is_array($action['data'] ?? null) ? $action['data'] : [];
        $choice = strtolower((string)($data['choice'] ?? ''));
        $skip = !empty($data['skip']) || $choice === 'no' || $choice === 'skip';
        $acc['prompts_n'][$ptype] = ($acc['prompts_n'][$ptype] ?? 0) + 1;
        if (!$skip && $choice !== 'no') {
            $acc['prompts_yes'][$ptype] = ($acc['prompts_yes'][$ptype] ?? 0) + 1;
        }
        $discard = $data['discard_ids'] ?? [];
        if (is_array($discard) && $discard !== []) {
            $hand = $board['players'][$pid]['hand'] ?? [];
            $byId = [];
            if (is_array($hand)) {
                foreach ($hand as $c) {
                    if (is_array($c) && !empty($c['instance_id'])) {
                        $byId[(string)$c['instance_id']] = $c;
                    }
                }
            }
            foreach ($discard as $id) {
                $c = $byId[(string)$id] ?? null;
                if (!is_array($c)) {
                    continue;
                }
                $acc['discard_live'][self::isLive($c) ? 0 : 1]++;
            }
        }
    }

    /** @param array<string,mixed> $acc */
    private static function absorbActivate(array &$acc, array $board, string $pid, array $action): void
    {
        $data = is_array($action['data'] ?? null) ? $action['data'] : [];
        $iid = (string)($data['card_id'] ?? '');
        $idx = (int)($data['ability_index'] ?? 0);
        $card = null;
        $stage = $board['players'][$pid]['stage'] ?? [];
        if (is_array($stage)) {
            foreach ($stage as $m) {
                if (is_array($m) && (string)($m['instance_id'] ?? '') === $iid) {
                    $card = $m;
                    break;
                }
            }
        }
        $ab = is_array($card) ? ($card['abilities'][$idx] ?? null) : null;
        $kind = 'other';
        if (is_array($ab)) {
            $t = strtolower((string)($ab['type'] ?? ''));
            foreach (['draw', 'surveil', 'blade', 'heart', 'wait', 'live_score', 'energy'] as $k) {
                if (str_contains($t, $k)) {
                    $kind = $k;
                    break;
                }
            }
        }
        if (!isset($acc['activate'][$kind])) {
            $acc['activate'][$kind] = ['yes' => 0, 'seen' => 0];
        }
        $acc['activate'][$kind]['yes']++;
        $acc['activate'][$kind]['seen']++;
    }

    /** @param array<string,mixed> $acc */
    private static function finishAccum(array $acc, int $replays): array
    {
        $policy = self::prior();
        $policy['source'] = $replays > 0 ? 'ranked_replays' : 'prior';
        $decks = max(1, (int)$acc['decks']);
        foreach ($acc['member_n'] as $no => $n) {
            if ($no === '' || $n < 2) {
                continue;
            }
            $policy['cards']['members'][$no] = ['rate' => round($n / $decks, 4), 'n' => $n];
        }
        $liveTotal = max(1, array_sum($acc['live_n']));
        foreach ($acc['live_n'] as $no => $n) {
            $policy['cards']['lives'][$no] = ['rate' => round($n / $liveTotal, 4), 'n' => $n];
        }
        $policy['mulligan']['return_live'] = self::rate($acc['mulligan']['live'], $policy['mulligan']['return_live']);
        $policy['mulligan']['return_low_cost_member'] = self::rate($acc['mulligan']['low'], $policy['mulligan']['return_low_cost_member']);
        $policy['mulligan']['return_high_cost_member'] = self::rate($acc['mulligan']['high'], $policy['mulligan']['return_high_cost_member']);
        $policy['mulligan']['keep_score2_live'] = self::rate($acc['mulligan']['keep2'], $policy['mulligan']['keep_score2_live']);

        $clearN = $acc['live_commit']['clear'][0] + $acc['live_commit']['clear'][1];
        if ($clearN > 0) {
            $policy['live']['clearable_commit'] = round($acc['live_commit']['clear'][0] / $clearN, 4);
        }
        $bandSum = array_sum($acc['score_band']);
        if ($bandSum > 0) {
            foreach (['low', 'mid', 'high'] as $b) {
                $policy['live']['score_band'][$b] = round($acc['score_band'][$b] / $bandSum, 4);
            }
        }
        $countSum = array_sum($acc['live_count']);
        if ($countSum > 0) {
            foreach ([1, 2, 3] as $k) {
                $policy['live']['count_when_clearable'][$k] = round(($acc['live_count'][$k] ?? 0) / $countSum, 4);
            }
        }
        foreach ($acc['prompts_n'] as $ptype => $n) {
            if ($n < 1) {
                continue;
            }
            $policy['prompts']['yes'][$ptype] = round(($acc['prompts_yes'][$ptype] ?? 0) / $n, 4);
        }
        $disc = $acc['discard_live'][0] + $acc['discard_live'][1];
        if ($disc > 0) {
            $policy['prompts']['discard_member_first'] = round($acc['discard_live'][1] / $disc, 4);
            $policy['prompts']['keep_live_on_discard'] = round(1 - ($acc['discard_live'][0] / $disc), 4);
        }
        foreach ($acc['activate'] as $kind => $row) {
            if (($row['seen'] ?? 0) < 1 || !isset($policy['main']['activate'][$kind])) {
                continue;
            }
            $policy['main']['activate'][$kind]['yes'] = round($row['yes'] / max(1, $row['seen']), 4);
        }
        return self::normalize($policy);
    }

    /**
     * @param list<array<string,mixed>> $held
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    public static function heldOutReport(array $held, array $policy): array
    {
        $countHits = 0;
        $countN = 0;
        $clearHits = 0;
        $clearN = 0;
        foreach ($held as $payload) {
            $frames = is_array($payload['frames'] ?? null) ? $payload['frames'] : [];
            $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];
            $baseline = is_array($payload['baseline'] ?? null) ? $payload['baseline'] : [];
            foreach ($actions as $i => $action) {
                if (!is_array($action) || ($action['type'] ?? '') !== 'set_live_cards') {
                    continue;
                }
                $ids = $action['data']['card_ids'] ?? [];
                if (!is_array($ids) || $ids === []) {
                    continue;
                }
                $prior = $i === 0 ? $baseline : (is_array($frames[$i] ?? null) ? $frames[$i] : []);
                $pid = (string)($action['player'] ?? '');
                $hand = $prior['players'][$pid]['hand'] ?? [];
                $byId = [];
                if (is_array($hand)) {
                    foreach ($hand as $c) {
                        if (is_array($c) && !empty($c['instance_id'])) {
                            $byId[(string)$c['instance_id']] = $c;
                        }
                    }
                }
                $lives = 0;
                $clearable = false;
                $me = $prior['players'][$pid] ?? [];
                $hearts = self::stageHeartCount(is_array($me) ? $me : []);
                foreach ($ids as $id) {
                    $c = $byId[(string)$id] ?? null;
                    if (!is_array($c) || !self::isLive($c)) {
                        continue;
                    }
                    $lives++;
                    if (self::heartCost($c) <= $hearts) {
                        $clearable = true;
                    }
                }
                if ($lives < 1) {
                    continue;
                }
                $countN++;
                $pred = 2;
                $bands = $policy['live']['count_when_clearable'] ?? [];
                $best = -1.0;
                foreach ([1, 2, 3] as $k) {
                    $r = (float)($bands[$k] ?? $bands[(string)$k] ?? 0);
                    if ($r > $best) {
                        $best = $r;
                        $pred = $k;
                    }
                }
                if ($pred === min(3, $lives)) {
                    $countHits++;
                }
                $clearN++;
                $wantClear = ((float)($policy['live']['clearable_commit'] ?? 0.9)) >= 0.5;
                if ($wantClear === $clearable || $hearts <= 0) {
                    $clearHits++;
                }
            }
        }
        return [
            'live_set_agreement' => $countN > 0 ? round($countHits / $countN, 4) : null,
            'clearable_commit_agreement' => $clearN > 0 ? round($clearHits / $clearN, 4) : null,
            'live_set_n' => $countN,
            'clearable_n' => $clearN,
        ];
    }

    /** @param array{0:int,1:int} $pair */
    private static function rate(array $pair, float $fallback): float
    {
        $n = $pair[0] + $pair[1];
        if ($n < 3) {
            return $fallback;
        }
        return round($pair[0] / $n, 4);
    }

    private static function isLive(array $c): bool
    {
        return ($c['card_type'] ?? '') === 'ライブ' || ($c['card_type_en'] ?? '') === 'Live';
    }

    private static function scoreBand(int $score): string
    {
        if ($score >= 3) {
            return 'high';
        }
        if ($score >= 2) {
            return 'mid';
        }
        return 'low';
    }

    private static function heartCost(array $c): int
    {
        $req = $c['required_hearts'] ?? $c['hearts'] ?? [];
        if (!is_array($req)) {
            return 99;
        }
        $n = 0;
        foreach ($req as $h) {
            if (is_array($h)) {
                $n += (int)($h['count'] ?? 1);
            }
        }
        return $n;
    }

    private static function stageHeartCount(array $player): int
    {
        $n = 0;
        $stage = $player['stage'] ?? [];
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
                    $n += (int)($h['count'] ?? 1);
                }
            }
        }
        return $n;
    }
}
