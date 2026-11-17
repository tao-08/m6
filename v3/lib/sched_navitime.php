<?php
declare(strict_types=1);

/**
 * =====================================================================
 *  sched_navitime.php — NAVITIME Route(totalnavi)（RapidAPI）で、電車の所要時間と運賃を調べる
 * =====================================================================
 *  キーは config.php の navitime.api_key（RapidAPI の X-RapidAPI-Key。Basic 無料プランは月500回まで）
 *  Google は日本の電車の経路を API で返さないので、こちらを使う。電車は時刻表ではなく平均所要時間で計算される（バスは検索対象外）
 *
 *  定期外の運賃の考え方
 *    1. 出発地 → スタジオ の経路を取る（駅の並び・区間ごとの運賃つき）
 *    2. 定期の区間（自宅最寄り駅 → キャンパスの最寄り駅）の経路を取り、通る駅の ID を「定期の範囲」とする
 *    3. 経路の各乗車区間のうち、両端の駅が定期の範囲に入るものは無料。入らない区間が連続している所（stretch）だけ運賃を数える
 *       区間が経路の全部なら経路の運賃、一部なら、その stretch の両端で運賃を再検索する（通しの運賃・乗継割引を反映するため）
 *  運賃は IC（unit_48）。無ければ切符（unit_0）
 * =====================================================================
 */

const SCHED_NAV_HOST = 'navitime-route-totalnavi.p.rapidapi.com';

function sched_nav_key(): string
{
    return (string)(config('navitime')['api_key'] ?? '');
}

/**
 * NAVITIME の返事（route_transit）の1つ目の経路を、使う形にそろえる。API を呼ばない部分（テストできる）
 * @return ?array{min:int, sum:int, transfers:int, fare:?int, lines:string[], moves:array<int,array{from:array,to:array}>}
 *   lines … 乗る路線の名前の順（経路の候補を人が見分けるための題。例 ['ＪＲ中央線', 'ＪＲ総武線']）
 *   moves … 乗車区間（徒歩は除く）の順。from / to = ['id' => 駅ID, 'name' => 駅名, 'lat' => float, 'lon' => float]
 */
function sched_nav_parse(array $json, int $idx = 0): ?array
{
    $item = $json['items'][$idx] ?? null;
    if (!is_array($item) || !isset($item['summary']['move']['time'])) {
        return null;
    }
    $fareArr = $item['summary']['move']['fare'] ?? [];
    $fare = isset($fareArr['unit_48']) ? (int)round((float)$fareArr['unit_48']) : (isset($fareArr['unit_0']) ? (int)round((float)$fareArr['unit_0']) : null);
    $sections = (array)($item['sections'] ?? []);
    $moves = [];
    $lines = [];
    foreach ($sections as $i => $s) {
        if (($s['type'] ?? '') !== 'move' || !isset($s['transport']['links'])) {
            continue; // 徒歩・点
        }
        $links = $s['transport']['links'];
        $lineName = (string)($s['transport']['name'] ?? '');
        if ($lineName !== '' && end($lines) !== $lineName) {
            $lines[] = $lineName;
        }
        $point = static function (int $idx) use ($sections, $links): ?array {
            // 乗車区間の前後の点（駅）。名前と座標はその点から、ID はリンクから
            $p = $sections[$idx] ?? null;
            return is_array($p) && ($p['type'] ?? '') === 'point' && isset($p['coord']['lat'], $p['coord']['lon']) ? $p : null;
        };
        $from = $point($i - 1);
        $to = $point($i + 1);
        if ($from === null || $to === null) {
            continue;
        }
        $moves[] = [
            'line' => count($links) === 1 ? (string)($links[0]['id'] ?? '') : '', // 路線（リンク）の ID。複数リンクをまたぐ区間は '' = 途中駅の判定に使わない
            'dir'  => (string)($links[0]['direction'] ?? ''),
            'dist' => (int)($s['distance'] ?? 0),                                  // 線路の距離（m）
            'from' => ['id' => (string)($links[0]['from']['id'] ?? ''), 'name' => (string)($from['name'] ?? ''), 'lat' => (float)$from['coord']['lat'], 'lon' => (float)$from['coord']['lon']],
            'to'   => ['id' => (string)($links[count($links) - 1]['to']['id'] ?? ''), 'name' => (string)($to['name'] ?? ''), 'lat' => (float)$to['coord']['lat'], 'lon' => (float)$to['coord']['lon']],
        ];
    }
    $sum = 0;
    foreach ($sections as $s) {
        if (($s['type'] ?? '') === 'move') {
            $sum += (int)($s['time'] ?? 0);
        }
    }
    return [
        'min' => (int)$item['summary']['move']['time'], // NAVITIME の合計（乗換・待ちの平均を含む）
        'sum' => $sum,                                   // 徒歩・乗車の時間の合計（乗換・待ちを含まない）
        'transfers' => (int)($item['summary']['move']['transit_count'] ?? 0),
        'fare' => $fare, 'lines' => $lines, 'moves' => $moves,
    ];
}

/** 返事の経路の候補ぜんぶ（先頭から $max 件。経路が読めないものは除く） */
function sched_nav_parse_all(array $json, int $max = 3): array
{
    $out = [];
    foreach (array_keys((array)($json['items'] ?? [])) as $i) {
        $r = sched_nav_parse($json, (int)$i);
        if ($r !== null) {
            $out[] = $r;
        }
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}

/** 経路が通る駅の ID の一覧（定期の範囲を作る） */
function sched_nav_station_ids(array $route): array
{
    $ids = [];
    foreach ($route['moves'] as $m) {
        $ids[$m['from']['id']] = true;
        $ids[$m['to']['id']] = true;
    }
    return array_keys($ids);
}

/**
 * 定期の範囲（登録した経路から作る）。['ids' => 通る駅の ID, 'moves' => 乗車区間（路線・向き・距離つき）]
 *   ids は乗換駅と端の駅だけ（途中駅は経路に出ない）。途中駅は moves の距離から sched_nav_covered で判定する
 */
function sched_nav_pass_spec(array $route): array
{
    return [
        'ids' => sched_nav_station_ids($route),
        'moves' => array_map(static fn($m) => ['from' => $m['from']['id'], 'to' => $m['to']['id'], 'line' => $m['line'] ?? '', 'dir' => $m['dir'] ?? '', 'dist' => $m['dist'] ?? 0], $route['moves']),
    ];
}

/** 距離の誤差（m）。距離は100m単位に丸められているので、少し余裕を見る */
const SCHED_NAV_DIST_TOL = 300;

/**
 * 乗車区間 $moves のうち、定期で乗れるもの（添字の一覧）
 *   ① 両端の駅が定期の範囲の駅（乗換駅・端の駅）→ 乗れる
 *   ② 定期と同じ路線・同じ線路の上で、定期の区間の中に収まる → 乗れる（途中駅で降りる場合）
 *      定期の区間の端 A を 0 として、定期の区間の長さを dP とする。練習の区間が A または B から出ているなら、反対側の端の位置は
 *      ±距離で分かる（向きが同じなら +、逆なら −）。位置が 0〜dP に収まれば区間の中。収まった駅は次の区間の判定にも使う
 *   路線・向き・距離が分からない区間は、②の判定をしない（乗れない = 運賃を数える方に倒す）
 */
function sched_nav_covered(array $moves, array $pass): array
{
    $in = array_flip($pass['ids']);
    $pos = []; // [路線ID => [駅ID => 位置(m)]]
    $len = []; // [路線ID => 定期の区間の長さ]
    $dir = []; // [路線ID => 定期の向き]
    foreach ($pass['moves'] as $p) {
        if ($p['line'] !== '' && $p['dist'] > 0 && !isset($pos[$p['line']])) {
            $pos[$p['line']] = [$p['from'] => 0, $p['to'] => $p['dist']];
            $len[$p['line']] = $p['dist'];
            $dir[$p['line']] = $p['dir'];
        }
    }
    $covered = [];
    foreach ($moves as $i => $m) {
        $f = $m['from']['id'];
        $t = $m['to']['id'];
        if (isset($in[$f], $in[$t])) {
            $covered[] = $i;
            continue;
        }
        $line = $m['line'] ?? ''; // 古いキャッシュには無い
        if ($line === '' || !isset($pos[$line]) || ($m['dist'] ?? 0) <= 0 || $dir[$line] === '' || ($m['dir'] ?? '') === '') {
            continue;
        }
        $sign = $m['dir'] === $dir[$line] ? 1 : -1;
        if (isset($pos[$line][$f])) {
            $pf = $pos[$line][$f];
            $pt = $pf + $sign * $m['dist'];
        } elseif (isset($pos[$line][$t])) {
            $pt = $pos[$line][$t];
            $pf = $pt - $sign * $m['dist'];
        } else {
            continue; // どちらの駅の位置も分からない
        }
        $tol = SCHED_NAV_DIST_TOL;
        if ($pf >= -$tol && $pf <= $len[$line] + $tol && $pt >= -$tol && $pt <= $len[$line] + $tol) {
            $pos[$line][$f] = $pf;
            $pos[$line][$t] = $pt;
            $covered[] = $i;
        }
    }
    return $covered;
}

/**
 * 定期で乗れない区間の「連続したかたまり」を返す
 * @param array $moves  sched_nav_parse の moves
 * @param array $pass   sched_nav_pass_spec の結果（定期なしなら ids も moves も空）
 * @return array<int, array{from: array, to: array, whole: bool}>  whole = 経路の乗車区間が全部（= 定期がまったく効かない）
 */
function sched_nav_stretches(array $moves, array $pass): array
{
    $ok = array_flip(sched_nav_covered($moves, $pass));
    $out = [];
    $cur = null;
    foreach ($moves as $i => $m) {
        if (isset($ok[$i])) {
            if ($cur !== null) {
                $out[] = $cur;
                $cur = null;
            }
            continue;
        }
        $cur = $cur === null ? ['from' => $m['from'], 'to' => $m['to'], 'count' => 1] : ['from' => $cur['from'], 'to' => $m['to'], 'count' => $cur['count'] + 1];
    }
    if ($cur !== null) {
        $out[] = $cur;
    }
    return array_map(static fn($c) => ['from' => $c['from'], 'to' => $c['to'], 'whole' => $c['count'] === count($moves)], $out);
}

/**
 * route_transit を呼ぶ。座標は [緯度, 経度]、$time は 'Y-m-d\TH:i:s'（日本時間）

 * @return array 経路の候補（sched_nav_parse の形の配列。最大3件。無ければ []）
 * @throws RuntimeException キー未設定・通信・API のエラー（画面に出してよい日本語）
 */
function sched_nav_routes(array $from, array $to, string $time): array
{
    $key = sched_nav_key();
    if ($key === '') {
        throw new RuntimeException('config.php に NAVITIME の API キー（navitime.api_key）が設定されていません');
    }
    $q = http_build_query(['start' => $from[0] . ',' . $from[1], 'goal' => $to[0] . ',' . $to[1], 'start_time' => $time]);
    $ch = curl_init('https://' . SCHED_NAV_HOST . '/route_transit?' . $q);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['x-rapidapi-key: ' . $key, 'x-rapidapi-host: ' . SCHED_NAV_HOST],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($raw === false) {
        throw new RuntimeException('NAVITIME に接続できませんでした。時間をおいて試してください');
    }
    if ($status === 401 || $status === 403) {
        throw new RuntimeException('NAVITIME の API キーが使えません（RapidAPI で Basic プランの登録を確認してください）');
    }
    if ($status === 429) {
        throw new RuntimeException('NAVITIME の今月の利用上限（500回）に達しました');
    }
    if ($status >= 400) {
        throw new RuntimeException('NAVITIME の API エラー（' . $status . '）');
    }
    $json = json_decode((string)$raw, true);
    return is_array($json) ? sched_nav_parse_all($json) : [];
}

/**
 * 移動時間（分）= 徒歩・乗車の合計 + 乗換1回あたり $transferMin 分。NAVITIME の合計（min）は乗換・待ちの平均が長めなので使わない
 *   （時刻表で「12時に一番近い電車」を出すには有料の契約が要る。Basic は平均所要時間）
 */
function sched_nav_door_minutes(array $route, int $transferMin): int
{
    return ($route['sum'] ?? $route['min']) + ($route['transfers'] ?? 0) * $transferMin; // 古いキャッシュには sum / transfers が無い
}

/** 経路の1つ目だけ（移動時間の調査用） */
function sched_nav_route(array $from, array $to, string $time): ?array
{
    return sched_nav_routes($from, $to, $time)[0] ?? null;
}

/** 次の平日 12:00（日本時間）。深夜は電車が無く結果がぶれるので昼で聞く */
function sched_nav_time(?DateTimeImmutable $now = null): string
{
    $d = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->setTimezone(new DateTimeZone('Asia/Tokyo'))->setTime(12, 0);
    do {
        $d = $d->modify('+1 day');
    } while ((int)$d->format('N') >= 6);
    return $d->format('Y-m-d\TH:i:s');
}
