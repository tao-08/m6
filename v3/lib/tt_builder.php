<?php
/**
 * =====================================================================
 *  tt_builder.php — タイムテーブル作成ツールの部品（tt_builder.php / tt_builder_api.php から使う）
 * =====================================================================
 *  下書き（timetable_draft）の state は JSON 1個。形はこう:
 *    {
 *      "title": "9月ライブ", "dayCount": 2,
 *      "bands":  {"b0": {"name", "members": [{"n": 表示名, "k": 照合用キー, "p": パート}], "songs", "cnt", "avail": [1,2], "pin": false, "ref": "ri:bi"}},
 *      "grades": {"照合用キー": 3},            … 部員DBに無い人の学年（ツールで入力）
 *      "dur": null|15,                          … プレビューの表記上の持ち時間（null = 1枠の時間）。bands の "dur" はバンドごとの上書き、"key" は鍵盤のメモ
 *      "days":   [{"call": "12:30", "date": "2026-10-30", "venue": "会場", "rows": [{"id": "b0", "min": 20}, {"id": "x1a2", "t": "break"|"text", "x": "休憩", "min": 10}]} ×3]
 *    }
 *  days は常に3日分持つ（dayCount で見せる日数を決める）。バンドの行の id はバンドの id と同じ。
 *
 *  同時編集: 画面からは「操作」（tt_apply_op の $op）を送ってもらい、サーバーで state に当てる。
 *  state をまるごと上書きしないので、2人が別々のバンドを動かしても両方残る。
 *  同じ処理を assets/tt_builder.js の applyOp にも書いてある（直すときは両方直すこと）。
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/import/planner.php';

const TT_MAX_DAYS = 3;
const TT_MAX_ROWS = 80;          // 1日の行数の上限
const TT_MAX_STATE_BYTES = 1_000_000;
const TT_ROW_ID = '/^[a-z][a-z0-9]{0,23}$/';
const TT_PRESENCE_SEC = 15;      // この秒数以内に見に来た人を「開いている人」とする

/** 名簿（build_import_plan の rosters）から最初の state を作る。名簿が複数ファイルなら 1ファイル目 = 1日目… と仮に置く */
function tt_new_state(string $title, array $rosters): array
{
    $dayCount = max(1, min(TT_MAX_DAYS, count($rosters) >= 2 ? count($rosters) : 2));
    $state = ['title' => $title, 'dayCount' => $dayCount, 'bands' => [], 'grades' => [], 'mix' => 50, 'gap' => true,
        'days' => array_fill(0, TT_MAX_DAYS, ['call' => '', 'rows' => []])];
    // 各日程の一番上に、持ち時間30分のテキスト行「集合」を入れておく（id は x で始まる決まり）
    for ($d = 0; $d < $dayCount; $d++) {
        $state['days'][$d]['rows'][] = ['id' => 'xc' . ($d + 1), 't' => 'text', 'x' => '集合', 'min' => 30];
    }
    $n = 0;
    foreach ($rosters as $ri => $roster) {
        foreach ($roster['bands'] as $bi => $b) {
            $id = 'b' . $n++;
            $members = [];
            foreach ($b['members'] as $m) {
                $k = member_key($m['name']);
                if ($k !== '' && !isset($members[$k])) { // ギタボなど同じ人が2つの欄にいても1人
                    $members[$k] = ['n' => member_display($m['name']), 'k' => $k, 'p' => $m['part']];
                }
            }
            $state['bands'][$id] = ['name' => $b['band_name'], 'members' => array_values($members),
                'songs' => $b['song_count'], 'cnt' => $b['member_count'], 'avail' => range(1, $dayCount),
                'pin' => false, 'ref' => "$ri:$bi"];
            $state['days'][min($ri, $dayCount - 1)]['rows'][] = ['id' => $id, 'min' => null];
        }
    }
    return $state;
}

/** 下書きを読む。$lock = true なら行ロック（トランザクションの中で使う） */
function tt_load(PDO $pdo, int $id, bool $lock = false): ?array
{
    $st = $pdo->prepare('SELECT * FROM timetable_draft WHERE draft_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $row['state'] = json_decode($row['state'], true) ?: [];
    return $row;
}

/**
 * 名簿の人の 学年 と 後ろの出順の経験 を部員DBから調べる。
 *   g … 学年（DBに居ない・入学年度が無ければ無し）
 *   t … トリの回数 / n … 出演回数（バンド数）
 *   r … 出演した日ごとの「いちばん遅かったバンドの逆順」の平均 / q … 逆順 ÷ その日のバンド数 の平均（履歴なしは null）
 * @param string[] $keys member_key の配列
 * @return array<string,array> キー => ['g' =>, 't' =>, 'b' =>, 'n' =>]
 */
function tt_member_info(PDO $pdo, array $keys): array
{
    $want = array_flip($keys);
    $idOf = [];
    $info = [];
    foreach ($pdo->query('SELECT member_id, name, entry_year FROM member') as $m) {
        $k = member_key($m['name']);
        if (isset($want[$k]) && !isset($idOf[$k])) {
            $idOf[$k] = (int)$m['member_id'];
            $g = grade_of($m['entry_year'] !== null ? (int)$m['entry_year'] : null);
            $info[$k] = ['g' => $g, 't' => 0, 'n' => 0, 'r' => null, 'q' => null];
        }
    }
    if (!$idOf) {
        return $info;
    }
    $keyOf = array_flip($idOf);
    $in = implode(',', array_fill(0, count($idOf), '?'));
    // 日程ごとのバンド数で「後ろ1/4」を決める。トリは HEADLINER_SQL（総バンド数まで登録済みの日程だけ）
    $st = $pdo->prepare('SELECT bm.member_id, b.live_day_id, b.play_order, cnt.n, (last.max_order IS NOT NULL) AS is_last
        FROM (' . MEMBERSHIP_SQL . ') bm
        JOIN band b ON b.band_id = bm.band_id
        JOIN (SELECT live_day_id, COUNT(*) AS n FROM band GROUP BY live_day_id) cnt ON cnt.live_day_id = b.live_day_id
        LEFT JOIN (' . HEADLINER_SQL . ') last ON last.live_day_id = b.live_day_id AND last.max_order = b.play_order
        WHERE bm.member_id IN (' . $in . ')');
    $st->execute(array_values($idOf));
    $latest = []; // キー => [live_day_id => その日いちばん遅かったバンドの [逆順, その日のバンド数]]
    foreach ($st as $r) {
        $k = $keyOf[(int)$r['member_id']];
        $info[$k]['n']++;
        if ((int)$r['is_last']) {
            $info[$k]['t']++;
        }
        // 逆順 = バンド数 - 出順 + 1（トリ = 1、13バンドの日の最初 = 13）。同じ日に何バンドか出ていたら、いちばん遅い（逆順が小さい）方
        $rev = (int)$r['n'] - (int)$r['play_order'] + 1;
        $day = (int)$r['live_day_id'];
        if (!isset($latest[$k][$day]) || $rev < $latest[$k][$day][0]) {
            $latest[$k][$day] = [$rev, (int)$r['n']];
        }
    }
    // 後ろの出順の経験の少なさ: 出演した日ごとの逆順の平均（r。大きいほど前の方ばかり）と、バンド数に対する割合の平均（q。0.5以上で前寄り）
    foreach ($latest as $k => $days) {
        $info[$k]['r'] = round(array_sum(array_column($days, 0)) / count($days), 1);
        $info[$k]['q'] = round(array_sum(array_map(static fn($d) => $d[0] / max(1, $d[1]), $days)) / count($days), 3);
    }
    return $info;
}

/**
 * 部員DBにいない名簿の人ごとの「もしかして」の候補。取り込み画面の名前欄と同じ考え方
 *   似た名前（書き間違い）の登録済みの人を優先し、いなければ先頭の1文字が同じ / 名前の一部が重なる人を似ている順に（最大3人）
 * @param array $info tt_member_info の結果（ここに無いキー = DBにいない人）
 * @return array<string,array> キー => [['n' => 表示名, 'k' => 照合用キー], ...]
 */
function tt_member_suggest(PDO $pdo, array $state, array $info): array
{
    $index = null;
    $out = [];
    foreach ($state['bands'] as $b) {
        foreach ($b['members'] as $m) {
            $key = $m['k'];
            if (isset($info[$key]) || isset($out[$key]) || $key === '') {
                continue;
            }
            $index ??= load_member_index($pdo);
            $similar = [];
            $near = [];
            foreach ($index as $eKey => $e) {
                if ($eKey === '') {
                    continue;
                }
                if (names_look_similar($key, $eKey)) {
                    $similar[$eKey] = $e['name'];
                } elseif (mb_substr($key, 0, 1) === mb_substr($eKey, 0, 1) || mb_strpos($eKey, $key) !== false || mb_strpos($key, $eKey) !== false) {
                    similar_text($key, $eKey, $pct);
                    $near[$eKey] = $pct;
                }
            }
            arsort($near);
            $list = [];
            foreach ([...array_keys($similar), ...array_keys($near)] as $eKey) {
                $list[] = ['n' => $index[$eKey]['name'], 'k' => (string)$eKey];
                if (count($list) >= 3) {
                    break;
                }
            }
            $out[$key] = $list;
        }
    }
    return array_filter($out);
}

/** state の中の全員の照合用キー */
function tt_member_keys(array $state): array
{
    $keys = [];
    foreach ($state['bands'] as $b) {
        foreach ($b['members'] as $m) {
            $keys[$m['k']] = true;
        }
    }
    return array_keys($keys);
}

/** 行 id → [日(0始まり), 位置]。無ければ null */
function tt_find_row(array $state, string $id): ?array
{
    foreach ($state['days'] as $d => $day) {
        foreach ($day['rows'] as $i => $r) {
            if ($r['id'] === $id) {
                return [$d, $i];
            }
        }
    }
    return null;
}

/**
 * 操作を1つ state に当てる。おかしな操作（他の人の変更で行が消えた・出演できない日へ動かした など）は false を返して何もしない。
 * フォームの値と同じく書き換えられる可能性があるので、型と範囲を全部確かめる。
 */
function tt_apply_op(array &$state, mixed $op): bool
{
    if (!is_array($op) || !is_string($op['op'] ?? null)) {
        return false;
    }
    $int = static fn($v, int $min, int $max) => is_int($v) && $v >= $min && $v <= $max;
    $str = static fn($v, int $max) => is_string($v) && mb_strlen($v) <= $max;
    $dc = $state['dayCount'];
    switch ($op['op']) {
        case 'title':
            if (!$str($op['x'] ?? null, 100) || trim($op['x']) === '') {
                return false;
            }
            $state['title'] = trim($op['x']);
            return true;

        case 'dayCount': // 減らしたら、消えた日の行は残った最後の日の後ろへ。日程希望も詰める
            if (!$int($op['n'] ?? null, 1, TT_MAX_DAYS)) {
                return false;
            }
            $n = $op['n'];
            for ($d = $n; $d < TT_MAX_DAYS; $d++) {
                array_push($state['days'][$n - 1]['rows'], ...$state['days'][$d]['rows']);
                $state['days'][$d]['rows'] = [];
            }
            foreach ($state['bands'] as &$b) {
                $b['avail'] = array_values(array_filter($b['avail'], static fn($x) => $x <= $n));
                if (!$b['avail']) {
                    $b['avail'] = range(1, $n);
                }
                if ($n > $dc && count($b['avail']) === $dc) {
                    $b['avail'] = range(1, $n); // 全日OKだったバンドは、増えた日もOKにしておく
                }
            }
            unset($b);
            $state['dayCount'] = $n;
            return true;

        case 'avail': // 日程希望。今いる日がダメになったら、出られる最初の日の最後へ動かす
            $b = $op['b'] ?? null;
            $days = $op['days'] ?? null;
            if (!is_string($b) || !isset($state['bands'][$b]) || !is_array($days) || !$days) {
                return false;
            }
            $days = array_values(array_unique($days));
            foreach ($days as $x) {
                if (!$int($x, 1, $dc)) {
                    return false;
                }
            }
            sort($days);
            $state['bands'][$b]['avail'] = $days;
            $at = tt_find_row($state, $b);
            if ($at !== null && !in_array($at[0] + 1, $days, true)) {
                [$row] = array_splice($state['days'][$at[0]]['rows'], $at[1], 1);
                $state['days'][$days[0] - 1]['rows'][] = $row;
            }
            return true;

        case 'move': // 行を {day(1始まり), index} へ。バンドは出演できる日だけ
            $id = $op['id'] ?? null;
            $day = $op['day'] ?? null;
            $index = $op['index'] ?? null;
            if (!is_string($id) || !$int($day, 1, $dc) || !$int($index, 0, TT_MAX_ROWS)) {
                return false;
            }
            $at = tt_find_row($state, $id);
            if ($at === null || (isset($state['bands'][$id]) && !in_array($day, $state['bands'][$id]['avail'], true))) {
                return false;
            }
            if ($at[0] !== $day - 1 && count($state['days'][$day - 1]['rows']) >= TT_MAX_ROWS) {
                return false;
            }
            [$row] = array_splice($state['days'][$at[0]]['rows'], $at[1], 1);
            $rows = &$state['days'][$day - 1]['rows'];
            array_splice($rows, min($index, count($rows)), 0, [$row]);
            return true;

        case 'arrange': // 自動配置の結果。全部の行を、日ごとの id の並びで置きかえる（行の過不足・出演できない日があれば受け付けない）
            $order = $op['days'] ?? null;
            if (!is_array($order) || count($order) !== $dc) {
                return false;
            }
            $byId = [];
            foreach ($state['days'] as $day) {
                foreach ($day['rows'] as $r) {
                    $byId[$r['id']] = $r;
                }
            }
            $new = array_fill(0, TT_MAX_DAYS, null);
            $seen = [];
            foreach (array_values($order) as $d => $ids) {
                if (!is_array($ids) || count($ids) > TT_MAX_ROWS) {
                    return false;
                }
                $new[$d] = [];
                foreach ($ids as $id) {
                    if (!is_string($id) || !isset($byId[$id]) || isset($seen[$id])
                        || (isset($state['bands'][$id]) && !in_array($d + 1, $state['bands'][$id]['avail'], true))) {
                        return false;
                    }
                    $seen[$id] = true;
                    $new[$d][] = $byId[$id];
                }
            }
            if (count($seen) !== count($byId)) {
                return false;
            }
            foreach ($new as $d => $rows) {
                $state['days'][$d]['rows'] = $rows ?? [];
            }
            return true;

        case 'member': // 名簿の人を、「もしかして」で選んだ登録済みの人の名前に置きかえる（パートはそのまま）
            $b = $op['b'] ?? null;
            $k = $op['k'] ?? null;
            $n = $op['n'] ?? null;
            if (!is_string($b) || !isset($state['bands'][$b]) || !is_string($k) || !$str($n, 60) || trim($n) === '') {
                return false;
            }
            $nk = member_key($n);
            $keys = array_column($state['bands'][$b]['members'], 'k');
            $at = array_search($k, $keys, true);
            if ($nk === '' || $at === false || ($nk !== $k && in_array($nk, $keys, true))) {
                return false;
            }
            $state['bands'][$b]['members'][$at] = ['n' => member_display($n), 'k' => $nk, 'p' => $state['bands'][$b]['members'][$at]['p'] ?? ''];
            return true;

        case 'restore': // 元に戻す / やり直す: 全日程の集合時間と行（バンド + 休憩・テキスト）をまるごと置きかえる。バンドの過不足・出演できない日は受け付けない
            $days = $op['days'] ?? null;
            if (!is_array($days) || count($days) !== TT_MAX_DAYS) {
                return false;
            }
            $seen = [];
            $next = [];
            foreach (array_values($days) as $d => $day) {
                $call = is_array($day) ? ($day['call'] ?? null) : null;
                $rows = is_array($day) ? ($day['rows'] ?? null) : null;
                if (!is_array($rows) || count($rows) > TT_MAX_ROWS || !is_string($call)
                    || ($call !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $call))) {
                    return false;
                }
                $out = [];
                foreach ($rows as $r) {
                    $id = is_array($r) ? ($r['id'] ?? null) : null;
                    $min = is_array($r) ? ($r['min'] ?? null) : null;
                    if (!is_string($id) || isset($seen[$id]) || !($min === null || $int($min, 0, 600))) {
                        return false;
                    }
                    if (!empty($state['bands'][$id]['off'])) {
                        continue; // 非表示のバンドの行は無かったことにする（元に戻す の古い記録に残っていることがある）
                    }
                    $seen[$id] = true;
                    if (isset($state['bands'][$id])) {
                        if (!in_array($d + 1, $state['bands'][$id]['avail'], true)) {
                            return false;
                        }
                        $out[] = ['id' => $id, 'min' => $min];
                    } else {
                        if (!preg_match(TT_ROW_ID, $id) || $id[0] !== 'x' || !in_array($r['t'] ?? null, ['break', 'text'], true) || !$str($r['x'] ?? null, 50)) {
                            return false;
                        }
                        $out[] = ['id' => $id, 't' => $r['t'], 'x' => trim($r['x']), 'min' => $min];
                    }
                }
                $next[$d] = ['call' => $call, 'rows' => $out];
            }
            $shown = array_filter($state['bands'], static fn($b) => empty($b['off']));
            if (count(array_intersect_key($shown, $seen)) !== count($shown)) {
                return false;
            }
            foreach ($next as $d => $day) {
                if ($d >= $dc && $day['rows']) {
                    return false;
                }
                $state['days'][$d] = array_merge($state['days'][$d], $day);
            }
            return true;

        case 'hide': // 名簿のバンドを出演順・自動配置・CSV・公開から外す / 戻す（戻すときは出られる最初の日の最後へ）
            $b = $op['b'] ?? null;
            if (!is_string($b) || !isset($state['bands'][$b]) || !is_bool($op['on'] ?? null)) {
                return false;
            }
            $at = tt_find_row($state, $b);
            if ($op['on']) {
                if ($at !== null) {
                    array_splice($state['days'][$at[0]]['rows'], $at[1], 1);
                }
                $state['bands'][$b]['off'] = true;
            } else {
                $day = min($state['bands'][$b]['avail'] ?: [1]);
                if ($at === null) {
                    if ($day > $dc || count($state['days'][$day - 1]['rows']) >= TT_MAX_ROWS) {
                        return false;
                    }
                    $state['days'][$day - 1]['rows'][] = ['id' => $b, 'min' => null];
                }
                unset($state['bands'][$b]['off']);
            }
            return true;

        case 'pin':
            $b = $op['b'] ?? null;
            if (!is_string($b) || !isset($state['bands'][$b]) || !is_bool($op['on'] ?? null)) {
                return false;
            }
            $state['bands'][$b]['pin'] = $op['on'];
            return true;

        case 'grade': // 部員DBに無い人の学年。null で消す
            $k = $op['k'] ?? null;
            $g = $op['g'] ?? null;
            if (!$str($k, 60) || $k === '' || !($g === null || $int($g, 1, 9))) {
                return false;
            }
            if ($g === null) {
                unset($state['grades'][$k]);
            } else {
                $state['grades'][$k] = $g;
            }
            return true;

        case 'mix': // おすすめ度の配分（0 = 学年重視 … 100 = トリ・後ろの経験が少ない人重視）
            if (!$int($op['v'] ?? null, 0, 100)) {
                return false;
            }
            $state['mix'] = $op['v'];
            return true;

        case 'gap': // 自動配置で 1バンド挟みも避ける（false なら隣だけ避ける）
            if (!is_bool($op['on'] ?? null)) {
                return false;
            }
            $state['gap'] = $op['on'];
            return true;

        case 'date': // プレビュー: 日ごとの開催日（YYYY-MM-DD）。'' で空欄
            $day = $op['day'] ?? null;
            $x = $op['x'] ?? null;
            if (!$int($day, 1, TT_MAX_DAYS) || !is_string($x) || ($x !== '' && !tt_valid_date($x))) {
                return false;
            }
            $state['days'][$day - 1]['date'] = $x;
            return true;

        case 'venue': // プレビュー: 日ごとの会場
            $day = $op['day'] ?? null;
            if (!$int($day, 1, TT_MAX_DAYS) || !$str($op['x'] ?? null, 100)) {
                return false;
            }
            $state['days'][$day - 1]['venue'] = trim($op['x']);
            return true;

        case 'name': // プレビュー: バンド名
        case 'key':  // プレビュー: 鍵盤のメモ（Key 列）
            $b = $op['b'] ?? null;
            $max = $op['op'] === 'name' ? 100 : 50;
            if (!is_string($b) || !isset($state['bands'][$b]) || !$str($op['x'] ?? null, $max) || ($op['op'] === 'name' && trim($op['x']) === '')) {
                return false;
            }
            $state['bands'][$b][$op['op']] = trim($op['x']);
            return true;

        case 'songs': // プレビュー: 曲数・人数（null で空欄）
        case 'cnt':
            $b = $op['b'] ?? null;
            $n = $op['v'] ?? null;
            if (!is_string($b) || !isset($state['bands'][$b]) || !($n === null || $int($n, 0, 99))) {
                return false;
            }
            $state['bands'][$b][$op['op']] = $n;
            return true;

        case 'dur': // プレビュー: 表記上の持ち時間をバンドに一括で（null = 1枠の時間をそのまま持ち時間にする）
            $m = $op['v'] ?? null;
            if (!($m === null || $int($m, 0, 600))) {
                return false;
            }
            $state['dur'] = $m;
            return true;

        case 'bdur': // プレビュー: バンドごとの表記上の持ち時間（null = 一括の値）
            $b = $op['b'] ?? null;
            $m = $op['v'] ?? null;
            if (!is_string($b) || !isset($state['bands'][$b]) || !($m === null || $int($m, 0, 600))) {
                return false;
            }
            $state['bands'][$b]['dur'] = $m;
            return true;

        case 'min': // 持ち時間（分）。null で空欄
            $id = $op['id'] ?? null;
            $m = $op['min'] ?? null;
            if (!is_string($id) || !($m === null || $int($m, 0, 600)) || ($at = tt_find_row($state, $id)) === null) {
                return false;
            }
            $state['days'][$at[0]]['rows'][$at[1]]['min'] = $m;
            return true;

        case 'call': // 集合時間
            $day = $op['day'] ?? null;
            $t = $op['t'] ?? null;
            if (!$int($day, 1, TT_MAX_DAYS) || !is_string($t) || ($t !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t))) {
                return false;
            }
            $state['days'][$day - 1]['call'] = $t;
            return true;

        case 'add': // 休憩・テキストの行を足す
            $id = $op['id'] ?? null;
            $day = $op['day'] ?? null;
            $t = $op['t'] ?? null;
            if (!is_string($id) || !preg_match(TT_ROW_ID, $id) || $id[0] !== 'x' || tt_find_row($state, $id) !== null
                || !$int($day, 1, $dc) || !$int($op['index'] ?? null, 0, TT_MAX_ROWS) || !in_array($t, ['break', 'text'], true)
                || !$str($op['x'] ?? null, 50) || count($state['days'][$day - 1]['rows']) >= TT_MAX_ROWS
                || !(($op['min'] ?? null) === null || $int($op['min'], 0, 600))) {
                return false;
            }
            $rows = &$state['days'][$day - 1]['rows'];
            array_splice($rows, min($op['index'], count($rows)), 0, [['id' => $id, 't' => $t, 'x' => trim($op['x']), 'min' => $op['min'] ?? null]]);
            return true;

        case 'text': // 休憩・テキストの行の文字
            $id = $op['id'] ?? null;
            if (!is_string($id) || !$str($op['x'] ?? null, 50) || ($at = tt_find_row($state, $id)) === null
                || !isset($state['days'][$at[0]]['rows'][$at[1]]['t'])) {
                return false;
            }
            $state['days'][$at[0]]['rows'][$at[1]]['x'] = trim($op['x']);
            return true;

        case 'del': // 休憩・テキストの行を消す（バンドの行は消せない）
            $id = $op['id'] ?? null;
            if (!is_string($id) || ($at = tt_find_row($state, $id)) === null || !isset($state['days'][$at[0]]['rows'][$at[1]]['t'])) {
                return false;
            }
            array_splice($state['days'][$at[0]]['rows'], $at[1], 1);
            return true;
    }
    return false;
}

/** "12:30" + 分 → "12:50"（24時を超えたら回す） */
function tt_add_min(string $hm, int $min): string
{
    [$h, $m] = array_map('intval', explode(':', $hm));
    $t = ($h * 60 + $m + $min) % 1440;
    return sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
}

/** 1日分の行に開始・終了時刻を付ける（集合時間から上から順に足していく。集合時間が無ければ時刻なし） */
function tt_day_rows(array $state, int $d): array
{
    $day = $state['days'][$d];
    $cur = $day['call'] !== '' ? $day['call'] : null;
    $out = [];
    foreach ($day['rows'] as $r) {
        $band = isset($r['t']) ? null : ($state['bands'][$r['id']] ?? null);
        $start = $cur;
        $end = $cur !== null && $r['min'] !== null ? tt_add_min($cur, $r['min']) : null;
        if ($end !== null) {
            $cur = $end;
        }
        $out[] = ['row' => $r, 'band' => $band, 'start' => $start, 'end' => $r['min'] ? $end : null];
    }
    return $out;
}

/** 'YYYY-MM-DD' の実在する日付か */
function tt_valid_date(string $x): bool
{
    return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $x, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** 行の持ち時間の表記。表記の一括（state.dur）があればバンドはその値（バンドごとの dur が優先）、それ以外は1枠の時間 */
function tt_row_dur(array $state, array $x): ?int
{
    if ($x['band'] !== null && ($state['dur'] ?? null) !== null) {
        return $x['band']['dur'] ?? $state['dur'];
    }
    return $x['row']['min'];
}

/**
 * 書き出し用の CSV（取り込みのタイムテーブルの形。見出しは取り込みが読む「時間,,持ち時間,バンド名,曲数,人数,key」）。1日分
 *   1行目: 日付（時間の上）・ライブ名+◯日目（バンド名の上）・会場（曲数の上）と会場名
 *   休憩・テキストの行は、先頭と末尾だけ開始時刻のみ（完成形の表と同じ）。途中の休憩などは終了時刻も出す
 */
function tt_csv(array $state, int $d): string
{
    $day = $state['days'][$d];
    $date = tt_valid_date((string)($day['date'] ?? '')) ? date('Y/n/d', strtotime($day['date'])) : '';
    $venue = (string)($day['venue'] ?? '');
    $lines = [[$date, '', '', $state['title'] . DAY_LABELS[$d], $venue !== '' ? '会場' : '', $venue, ''],
        ['時間', '', '持ち時間', 'バンド名', '曲数', '人数', 'key']];
    $all = tt_day_rows($state, $d);
    foreach ($all as $i => $x) {
        $b = $x['band'];
        $dur = tt_row_dur($state, $x);
        $edge = $i === 0 || $i === count($all) - 1; // 先頭（集合など）と末尾（撤収など）の休憩・テキストは開始時刻だけ
        $lines[] = [$x['start'] ?? '', $b !== null || !$edge ? ($x['end'] ?? '') : '', $dur !== null ? (string)$dur : '', $b['name'] ?? $x['row']['x'],
            $b !== null ? (string)($b['songs'] ?? '') : '', $b !== null ? (string)$b['cnt'] : '', (string)($b['key'] ?? '')];
    }
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, "\xEF\xBB\xBF"); // Excel で文字化けしないよう BOM を付ける
    foreach ($lines as $l) {
        // 「=」などで始まるセルは Excel が数式として実行するので、先頭に ' を付けて文字にする（CSV インジェクション対策）
        fputcsv($fp, array_map(static fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v, $l), ',', '"', '');
    }
    rewind($fp);
    return (string)stream_get_contents($fp);
}

/**
 * 公開用: import.php の取り込みの計画（plan）を作る。build_manual_timetables と同じ形のタイムテーブル + 名簿。
 * 名簿のバンドと枠の対応は、バンド名で探さず下書きの ref（'ri:bi'）をそのまま使う。
 */
function tt_import_plan(array $state, array $rosters): array
{
    $plan = ['timetables' => [], 'rosters' => $rosters, 'errors' => [], 'notes' => []];
    $refs = [];
    for ($d = 0; $d < $state['dayCount']; $d++) {
        $slots = [];
        foreach (tt_day_rows($state, $d) as $x) {
            $b = $x['band'];
            if ($b !== null) {
                $refs[count($plan['timetables']) . ':' . count($slots)] = $b['ref'];
            } elseif (in_array(trim((string)$x['row']['x']), ['', '集合'], true)) {
                continue; // 名前の無い行・集合は登録しない（ファイルの取り込みでも集合は読み飛ばしている）
            }
            $slots[] = ['is_band' => $b !== null, 'band_name' => $b['name'] ?? $x['row']['x'],
                'start_time' => $x['start'], 'end_time' => $x['end'],
                'song_count' => $b['songs'] ?? null, 'member_count' => $b['cnt'] ?? null, 'key_note' => (string)($b['key'] ?? '')];
        }
        if (!array_filter($slots, static fn($s) => $s['is_band'])) {
            continue;
        }
        $date = (string)($state['days'][$d]['date'] ?? '');
        $ymd = tt_valid_date($date) ? array_map('intval', explode('-', $date)) : null; // プレビューで入れた開催日
        $plan['timetables'][] = ['title' => $state['title'] . DAY_LABELS[$d], 'live_name' => $state['title'],
            'label' => DAY_LABELS[$d], 'month' => $ymd[1] ?? null, 'day' => $ymd[2] ?? null, 'hint_year' => $ymd[0] ?? null, 'hint_fiscal' => false,
            'venue' => (string)($state['days'][$d]['venue'] ?? ''), 'slots' => $slots,
            'file' => 'タイムテーブル作成（' . DAY_LABELS[$d] . '）'];
    }
    $plan = finish_import_plan($plan);
    foreach ($plan['rosters'] as $ri => $r) {
        foreach (array_keys($r['bands']) as $bi) {
            $plan['rosters'][$ri]['bands'][$bi]['slot'] = '';
        }
    }
    foreach ($refs as $slotKey => $ref) {
        [$ri, $bi] = array_map('intval', explode(':', $ref));
        if (isset($plan['rosters'][$ri]['bands'][$bi])) {
            $plan['rosters'][$ri]['bands'][$bi]['slot'] = $slotKey;
        }
    }
    return $plan;
}

/** 今この下書きを開いている人（自分も含む）。focus = 操作中の行 */
function tt_presence(PDO $pdo, int $draftId, int $me): array
{
    $st = $pdo->prepare('SELECT p.user_id, p.focus, u.name FROM timetable_draft_presence p
        JOIN user_account u ON u.user_id = p.user_id
        WHERE p.draft_id = ? AND p.last_seen >= NOW() - INTERVAL ' . TT_PRESENCE_SEC . ' SECOND ORDER BY p.user_id');
    $st->execute([$draftId]);
    return array_map(static fn($r) => ['id' => (int)$r['user_id'], 'name' => $r['name'], 'focus' => $r['focus'],
        'me' => (int)$r['user_id'] === $me], $st->fetchAll());
}

/** <script type="application/json"> に埋める JSON（</script> などで閉じられないように） */
function tt_json(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
