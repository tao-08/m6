<?php
declare(strict_types=1);

/**
 * =====================================================================
 *  sched_fare.php — メンバーごとの「定期外の交通費」（NAVITIME の経路 + Google の駅の座標。結果は DB に覚える）
 * =====================================================================
 *  ・画面を開いたときは、覚えてある結果だけ使う（API は呼ばない。NAVITIME は Basic プランで月500回まで）
 *  ・「交通費を調べる」ボタンを押したときだけ API を呼び、結果を sched_nav_cache に180日覚える
 *  ・出発地は、練習の前後（設定。初期 4時間）以内に授業があれば学校、なければ自宅（行きと帰りを別々に判定。運賃は片道ずつ足す）
 * =====================================================================
 */
require_once __DIR__ . '/sched_navitime.php';

/** キャッシュに無い（API を呼ぶ許可が無いとき）。運賃は「不明」として扱う */
class SchedNavMiss extends RuntimeException
{
}

/** 経路・座標の結果を覚えておく日数（運賃改定は年に1回あるかないか） */
const SCHED_NAV_CACHE_DAYS = 180;
/** 1回の「交通費を調べる」で API を呼ぶ上限 */
const SCHED_NAV_MAX_CALLS = 60;

function sched_nav_cache_get(PDO $pdo, string $key): ?array
{
    $st = $pdo->prepare('SELECT payload FROM sched_nav_cache WHERE cache_key = ? AND fetched_at > NOW() - INTERVAL ' . SCHED_NAV_CACHE_DAYS . ' DAY');
    $st->execute([$key]);
    $raw = $st->fetchColumn();
    $v = $raw === false ? null : json_decode((string)$raw, true);
    return is_array($v) ? $v : null;
}

function sched_nav_cache_put(PDO $pdo, string $key, array $value): void
{
    $pdo->prepare('REPLACE INTO sched_nav_cache (cache_key, payload) VALUES (?, ?)')->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

/** API を呼んだ回数（1回のリクエストの中で数える） */
function sched_nav_calls(int $add = 0): int
{
    static $n = 0;
    $n += $add;
    return $n;
}

/** Google の Geocoding API で、駅名 → [緯度, 経度]。見つからなければ null */
function sched_nav_geocode(PDO $pdo, string $station, bool $fetch): ?array
{
    $name = preg_match('/駅$/u', $station) ? $station : $station . '駅';
    $key = 'g|' . $name;
    $hit = sched_nav_cache_get($pdo, $key);
    if ($hit !== null) {
        return isset($hit['lat']) ? [(float)$hit['lat'], (float)$hit['lon']] : null;
    }
    if (!$fetch) {
        throw new SchedNavMiss($key);
    }
    $gkey = (string)((config('drive')['api_key'] ?? '') ?: (config('youtube')['api_key'] ?? ''));
    if ($gkey === '') {
        throw new RuntimeException('config.php に Google の API キー（youtube.api_key）が設定されていません');
    }
    if (sched_nav_calls(1) > SCHED_NAV_MAX_CALLS) {
        throw new RuntimeException('一度に調べられる上限に達しました。もう一度押してください（調べ済みの分は保存されています）');
    }
    // 制限（APIの制限）を変えた直後は、Google 側で REQUEST_DENIED が混ざることがある → 数回やり直す
    $json = null;
    for ($try = 0; $try < 3; $try++) {
        $raw = @file_get_contents('https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query(['address' => $name, 'language' => 'ja', 'region' => 'jp', 'key' => $gkey]));
        $json = $raw === false ? null : json_decode($raw, true);
        if (is_array($json) && ($json['status'] ?? '') !== 'REQUEST_DENIED') {
            break;
        }
        usleep(500000);
    }
    if (!is_array($json) || !in_array($json['status'] ?? '', ['OK', 'ZERO_RESULTS'], true)) {
        throw new RuntimeException('Google で駅の場所を調べられませんでした（Geocoding API の有効化とキーの制限を確認してください）');
    }
    $loc = $json['results'][0]['geometry']['location'] ?? null;
    sched_nav_cache_put($pdo, $key, $loc ? ['lat' => $loc['lat'], 'lon' => $loc['lng']] : ['none' => 1]);
    return $loc ? [(float)$loc['lat'], (float)$loc['lng']] : null;
}

/**
 * NAVITIME の経路の候補（最大3件。キャッシュつき）。無ければ []。キャッシュに無く $fetch = false なら SchedNavMiss
 *   1回の API 呼び出しで候補が全部返るので、候補を増やしても回数は増えない
 */
function sched_nav_routes_cached(PDO $pdo, array $from, array $to, bool $fetch): array
{
    $key = 'r|' . round($from[0], 4) . ',' . round($from[1], 4) . '|' . round($to[0], 4) . ',' . round($to[1], 4);
    $hit = sched_nav_cache_get($pdo, $key);
    if ($hit !== null) {
        return $hit['list'] ?? [];
    }
    if (!$fetch) {
        throw new SchedNavMiss($key);
    }
    if (sched_nav_calls(1) > SCHED_NAV_MAX_CALLS) {
        throw new RuntimeException('一度に調べられる上限に達しました。もう一度押してください（調べ済みの分は保存されています）');
    }
    $list = sched_nav_routes($from, $to, sched_nav_time());
    sched_nav_cache_put($pdo, $key, ['list' => $list]);
    return $list;
}

/** 経路の運賃を、定期の範囲（駅 ID）を除いて数える。運賃が分からなければ null */
function sched_nav_route_fare(callable $routes, array $r, ?array $pass): ?int
{
    if (!$r['moves']) {
        return 0; // 徒歩だけ（電車に乗らない）= 運賃なし
    }
    if ($r['fare'] === null) {
        return null;
    }
    if ($pass === null) {
        return $r['fare'];
    }
    $total = 0;
    foreach (sched_nav_stretches($r['moves'], $pass) as $s) {
        if ($s['whole']) {
            $total += $r['fare']; // 定期がまったく効かない = 経路の運賃そのまま
            continue;
        }
        // 定期外の区間は、その区間の両端で運賃を調べ直す（通しの運賃・乗継割引を反映）。経路が複数あれば最も安いもの
        $fares = array_filter(array_column($routes([$s['from']['lat'], $s['from']['lon']], [$s['to']['lat'], $s['to']['lon']]), 'fare'), static fn($f) => $f !== null);
        $total += $fares ? min($fares) : 0;
    }
    return $total;
}

/**
 * 定期外の運賃（円。IC）。経路の候補のうち、定期外の運賃がいちばん安いものを採る。API を呼ばない計算の本体
 * @param callable $routes  (from, to) → 経路の候補の配列（テストできるよう関数で受ける）
 * @param ?array $pass      定期の範囲（sched_nav_pass_spec の結果。無ければ null = 定期なし）
 * @return ?int  経路が無ければ null
 */
function sched_nav_fare(callable $routes, array $origin, array $studio, ?array $pass): ?int
{
    $best = null;
    foreach ($routes($origin, $studio) as $r) {
        $f = sched_nav_route_fare($routes, $r, $pass);
        if ($f !== null && ($best === null || $f < $best)) {
            $best = $f;
        }
    }
    return $best;
}

/**
 * 定期の範囲（sched_nav_pass_spec の形）。登録のときに選んだ経路（pass_via）があればそれ、無ければ 自宅駅 → 定期の到着駅 の最初の経路
 *   定期なし（到着駅が空）は null
 */
function sched_pass_spec(PDO $pdo, array $prof, bool $fetch): ?array
{
    $home = (string)($prof['home_station'] ?? '');
    $to = (string)($prof['pass_to'] ?? '');
    if ($home === '' || $to === '' || (int)($prof['pass_on'] ?? 1) === 0) { // pass_on = 0 は、定期を無視して全額で計算
        return null;
    }
    $via = json_decode((string)($prof['pass_via'] ?? ''), true);
    if (is_array($via['ids'] ?? null) && $via['ids']) {
        return ['ids' => array_map('strval', $via['ids']), 'moves' => (array)($via['moves'] ?? [])];
    }
    $hc = sched_nav_geocode($pdo, $home, $fetch);
    $tc = sched_nav_geocode($pdo, $to, $fetch);
    if ($hc === null || $tc === null) {
        return null;
    }
    $list = sched_nav_routes_cached($pdo, $hc, $tc, $fetch);
    return $list ? sched_nav_pass_spec($list[0]) : null;
}

/**
 * 定期の経路の候補（登録のときに人が選ぶ）。[['label' => 'ＪＲ中央線 → … 35分', 'ids' => [駅ID…]], …]
 *   API を呼んで、結果は sched_nav_cache（'p|自宅駅|到着駅'）に覚える。経路が無ければ []
 */
function sched_pass_candidates(PDO $pdo, string $home, string $to, bool $fetch): array
{
    $key = 'p|' . $home . '|' . $to;
    $hit = sched_nav_cache_get($pdo, $key);
    if ($hit !== null) {
        return $hit['list'] ?? [];
    }
    if (!$fetch) {
        return [];
    }
    $hc = sched_nav_geocode($pdo, $home, true);
    $tc = sched_nav_geocode($pdo, $to, true);
    $out = [];
    if ($hc !== null && $tc !== null) {
        foreach (sched_nav_routes_cached($pdo, $hc, $tc, true) as $r) {
            $out[] = ['label' => implode(' → ', $r['lines'] ?: ['徒歩']) . '（' . $r['min'] . '分）'] + sched_nav_pass_spec($r);
        }
    }
    sched_nav_cache_put($pdo, $key, ['list' => $out]);
    return $out;
}

/**
 * メンバーの、出発地（'home' = 自宅最寄り駅 / 'campus'）→ スタジオ の定期外の運賃。分からなければ null
 *   $prof … ['campus' => 1|2, 'home_station' => ?string, 'pass_to' => ?string, 'pass_via' => ?string（JSON）]
 *   $fetch = false … キャッシュにあるものだけ使う（画面を開いたときはこちら。API は呼ばない）
 */
function sched_member_fare(PDO $pdo, array $prof, string $origin, array $studioCoord, bool $fetch): ?int
{
    $home = (string)($prof['home_station'] ?? '');
    try {
        $oc = null;
        if ($origin === 'campus') {
            foreach (studio_campuses() as $camp) {
                if ($camp['name'] === (SCHED_CAMPUSES[(int)$prof['campus']] ?? '')) {
                    $oc = [(float)$camp['lat'], (float)$camp['lng']];
                }
            }
        } elseif ($home !== '') {
            $oc = sched_nav_geocode($pdo, $home, $fetch);
        }
        if ($oc === null) {
            return null;
        }
        return sched_nav_fare(static fn(array $a, array $b) => sched_nav_routes_cached($pdo, $a, $b, $fetch), $oc, $studioCoord, sched_pass_spec($pdo, $prof, $fetch));
    } catch (SchedNavMiss $e) {
        return null;
    }
}

/**
 * 練習の1コマ（開始 $t0・長さ $len）で、メンバーが「学校から来る / 学校へ帰る」か。前後 $gap 分以内に授業があれば学校
 *   busy … sched_collect_busy の1人分 [[開始, 終了, 場所, 種類, 題], …]（絶対分）
 * @return array{0: string, 1: string} [行き: 'home'|'campus', 帰り: 'home'|'campus']
 */
function sched_fare_legs(array $busy, int $t0, int $len, int $gap): array
{
    $in = 'home';
    $out = 'home';
    foreach ($busy as $b) {
        if (($b[3] ?? '') !== 'class') {
            continue;
        }
        if ($b[1] <= $t0 && $t0 - $b[1] <= $gap) {
            $in = 'campus';
        }
        if ($b[0] >= $t0 + $len && $b[0] - ($t0 + $len) <= $gap) {
            $out = 'campus';
        }
    }
    return [$in, $out];
}
