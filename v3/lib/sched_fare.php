<?php
declare(strict_types=1);

/**
 * =====================================================================
 *  sched_fare.php — メンバーごとの「定期外の交通費」（NAVITIME の経路 + Google の駅の座標。結果は DB に覚える）
 * =====================================================================
 *  ・画面を開いたときは、覚えてある結果だけ使う（API は呼ばない。NAVITIME は Basic プランで月500回まで）
 *  ・「交通費を調べる」ボタンを押したときだけ API を呼び、結果を sched_nav_cache に180日覚える
 *  ・スタジオは最寄り駅（studio.access）まで調べて、同じ駅のスタジオで結果を共有する。徒歩は access の「徒歩N分」、無ければ Google（Routes API）
 *  ・出発地は、練習の前後（設定。初期 4時間）以内に授業があれば学校、なければ自宅（行きと帰りを別々に判定。運賃は片道ずつ足す）
 * =====================================================================
 */
require_once __DIR__ . '/sched_navitime.php';

/** キャッシュに無い（API を呼ぶ許可が無いとき）。運賃は「不明」として扱う */
class SchedNavMiss extends RuntimeException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message);
        sched_nav_misses(1);
    }
}

/** キャッシュに無かった回数（1回のリクエストの中で数える。0 なら、全部覚えてある = 調べるボタンは要らない） */
function sched_nav_misses(int $add = 0): int
{
    static $n = 0;
    $n += $add;
    return $n;
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

/** Google の API キー（config.php の drive.api_key → youtube.api_key の順）。未設定なら例外 */
function sched_google_key(): string
{
    $gkey = (string)((config('drive')['api_key'] ?? '') ?: (config('youtube')['api_key'] ?? ''));
    if ($gkey === '') {
        throw new RuntimeException('config.php に Google の API キー（youtube.api_key）が設定されていません');
    }
    return $gkey;
}

/** Google の Geocoding API で、駅名 → [緯度, 経度]。見つからなければ null */
function sched_nav_geocode(PDO $pdo, string $station, bool $fetch): ?array
{
    $name = sched_station_norm($station) . '駅'; // 書き方をそろえて、同じ駅は同じ結果を使う
    $key = 'g|' . $name;
    $hit = sched_nav_cache_get($pdo, $key);
    if ($hit !== null) {
        return isset($hit['lat']) ? [(float)$hit['lat'], (float)$hit['lon']] : null;
    }
    if (!$fetch) {
        throw new SchedNavMiss($key);
    }
    $gkey = sched_google_key();
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

/**
 * 駅名の書き方をそろえる（同じ駅を同じ名前にして、調べた結果を共有するため）。API を呼ばない
 *   全角の英数字・空白 → 半角 / 半角カナ → 全角 / 空白を消す / 先頭の「JR」と末尾の「駅」を外す
 *   例: 「ＪＲ 高円寺駅」→「高円寺」
 */
function sched_station_norm(string $name): string
{
    $s = mb_convert_kana($name, 'asKV');
    $s = (string)preg_replace('/\s+/u', '', $s);
    $s = (string)preg_replace('/^JR/iu', '', $s);
    return (string)preg_replace('/駅$/u', '', $s);
}

/**
 * 駅名の入力欄の候補（書き方をそろえた名前の一覧）。登録済みの自宅・定期の駅 + スタジオの最寄り駅 + キャンパスの駅
 *   誰の駅かは出さない（名前だけ）
 */
function sched_station_suggestions(PDO $pdo): array
{
    $names = array_merge(...array_values(SCHED_PASS_STATIONS));
    foreach ($pdo->query('SELECT home_station AS s FROM sched_profile WHERE home_station IS NOT NULL UNION SELECT pass_to FROM sched_profile WHERE pass_to IS NOT NULL') as $r) {
        $names[] = (string)$r['s'];
    }
    foreach ($pdo->query('SELECT access FROM studio WHERE access IS NOT NULL') as $r) {
        foreach (sched_studio_access((string)$r['access']) as $a) {
            $names[] = $a['station'];
        }
    }
    $out = array_values(array_unique(array_filter(array_map('sched_station_norm', $names), static fn($s) => $s !== '')));
    sort($out);
    return $out;
}

/**
 * スタジオのアクセス（studio.access）から、最寄り駅と駅からの徒歩（分）を全部取り出す。API を呼ばない
 *   駅 … 「○○駅」の名前（sched_station_norm でそろえる）。徒歩 … その駅の後ろ（次の駅まで）の「徒歩N分」（無ければ null）
 *   例: 「神保町駅 A5出口 徒歩3分（JR御茶ノ水駅 徒歩10分）」→ [['station' => '神保町', 'walk' => 3], ['station' => '御茶ノ水', 'walk' => 10]]
 *   同じ駅は1つにまとめる。駅が無ければ []
 * @return array<int, array{station: string, walk: ?int}>
 */
function sched_studio_access(string $access): array
{
    $s = mb_convert_kana($access, 'asn'); // 全角の英数字・空白 → 半角
    if (!preg_match_all('/([^\s（）()、，,\/／・]+?)駅/u', $s, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $out = [];
    foreach ($m[0] as $i => [$text, $pos]) {
        $station = sched_station_norm($m[1][$i][0]);
        if ($station === '' || isset($out[$station])) {
            continue;
        }
        $end = $pos + strlen($text);
        $next = $m[0][$i + 1][1] ?? strlen($s);
        $walk = preg_match('/徒歩\s*(\d+)\s*分/u', substr($s, $end, $next - $end), $w) ? (int)$w[1] : null;
        $out[$station] = ['station' => $station, 'walk' => $walk];
    }
    return array_values($out);
}

/**
 * Google の Routes API で、2点（[緯度, 経度]）の徒歩の所要時間（分）。経路が無ければ null（結果は sched_nav_cache に覚える）
 *   キャッシュに無く $fetch = false なら SchedNavMiss
 * @throws RuntimeException キー未設定・通信・API のエラー
 */
function sched_nav_walk_minutes(PDO $pdo, array $from, array $to, bool $fetch): ?int
{
    $key = 'w|' . round($from[0], 4) . ',' . round($from[1], 4) . '|' . round($to[0], 4) . ',' . round($to[1], 4);
    $hit = sched_nav_cache_get($pdo, $key);
    if ($hit !== null) {
        return isset($hit['min']) ? (int)$hit['min'] : null;
    }
    if (!$fetch) {
        throw new SchedNavMiss($key);
    }
    $gkey = sched_google_key();
    if (sched_nav_calls(1) > SCHED_NAV_MAX_CALLS) {
        throw new RuntimeException('一度に調べられる上限に達しました。もう一度押してください（調べ済みの分は保存されています）');
    }
    $point = static fn(array $c) => ['location' => ['latLng' => ['latitude' => (float)$c[0], 'longitude' => (float)$c[1]]]];
    $ch = curl_init('https://routes.googleapis.com/directions/v2:computeRoutes');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['origin' => $point($from), 'destination' => $point($to), 'travelMode' => 'WALK', 'languageCode' => 'ja'], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $gkey, 'X-Goog-FieldMask: routes.duration'],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $json = $raw === false ? null : json_decode((string)$raw, true);
    if ($raw === false || $status >= 400 || !is_array($json)) {
        throw new RuntimeException('Google で徒歩の時間を調べられませんでした（Routes API の有効化とキーの制限を確認してください）');
    }
    $dur = (string)($json['routes'][0]['duration'] ?? ''); // 例 "312s"
    if ($dur === '' || !preg_match('/^(\d+(?:\.\d+)?)s$/', $dur, $m)) {
        sched_nav_cache_put($pdo, $key, ['none' => 1]);
        return null;
    }
    $min = (int)ceil((float)$m[1] / 60);
    sched_nav_cache_put($pdo, $key, ['min' => $min]);
    return $min;
}

/**
 * スタジオまでの経路の「行き先」の候補。最寄り駅（studio.access。複数あれば全部）の座標と、駅からスタジオまでの徒歩（分）
 *   同じ駅のスタジオは同じ座標になるので、NAVITIME の結果（sched_nav_cache）を共有できる
 *   徒歩は access の「徒歩N分」、無ければ Google（Routes API）
 *   駅が分からない・駅の座標が無いときは、スタジオの座標・徒歩 0 の1つだけ
 *   $fetch = false で覚えた結果が無い駅は飛ばす（全部無ければ SchedNavMiss）
 * @return array<int, array{coord: array, walk: int}>
 * @throws SchedNavMiss キャッシュに無く $fetch = false
 * @throws RuntimeException API のエラー
 */
function sched_studio_dests(PDO $pdo, array $studioCoord, string $access, bool $fetch): array
{
    $stations = sched_studio_access($access);
    if (!$stations) {
        return [['coord' => $studioCoord, 'walk' => 0]];
    }
    $out = [];
    $missed = null;
    foreach ($stations as $a) {
        try {
            $sc = sched_nav_geocode($pdo, $a['station'], $fetch);
            if ($sc !== null) {
                $out[] = ['coord' => $sc, 'walk' => $a['walk'] ?? sched_nav_walk_minutes($pdo, $sc, $studioCoord, $fetch) ?? 0];
            }
        } catch (SchedNavMiss $e) {
            $missed = $e;
        }
    }
    if (!$out && $missed !== null) {
        throw $missed;
    }
    return $out ?: [['coord' => $studioCoord, 'walk' => 0]];
}

/**
 * 出発地 $oc → スタジオ。行き先の候補（sched_studio_dests）のうち、所要時間（駅まで + 徒歩）がいちばん短い駅を採り、運賃もその駅のもの
 *   覚えた結果が無い駅（$fetch = false）は飛ばす。どの駅も経路が無ければ null
 * @return ?array{fare: ?int, min: int}
 */
function sched_trip_best(PDO $pdo, array $oc, array $dests, int $transferMin, bool $fetch, ?array $pass): ?array
{
    $routes = static fn(array $a, array $b) => sched_nav_routes_cached($pdo, $a, $b, $fetch);
    $best = null;
    foreach ($dests as $d) {
        try {
            $list = $routes($oc, $d['coord']);
        } catch (SchedNavMiss $e) {
            continue;
        }
        if (!$list) {
            continue;
        }
        $min = min(array_map(static fn(array $r) => sched_nav_door_minutes($r, $transferMin), $list)) + $d['walk'];
        if ($best === null || $min < $best['min']) {
            $best = ['coord' => $d['coord'], 'min' => $min];
        }
    }
    if ($best === null) {
        return null;
    }
    return ['fare' => sched_nav_fare($routes, $oc, $best['coord'], $pass), 'min' => $best['min']];
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
    // 'p2|' … 所要時間を「徒歩・乗車の合計 + 乗換」に変えたので、古い 'p|'（NAVITIME の合計の分数入り）は使わない
    // 覚えるのは経路の中身（路線名・合計・乗換回数）。分数の表示は、設定の「乗換1回あたり」で毎回つくる
    $key = 'p2|' . sched_station_norm($home) . '|' . sched_station_norm($to);
    $transferMin = (int)sched_settings($pdo)['transfer_min'];
    $label = static function (array $c) use ($transferMin): array {
        $min = sched_nav_door_minutes($c, $transferMin);
        return ['label' => $c['names'] . '（' . $min . '分）'] + $c;
    };
    $hit = sched_nav_cache_get($pdo, $key);
    if ($hit !== null) {
        return array_map($label, $hit['list'] ?? []);
    }
    if (!$fetch) {
        return [];
    }
    $hc = sched_nav_geocode($pdo, $home, true);
    $tc = sched_nav_geocode($pdo, $to, true);
    $out = [];
    if ($hc !== null && $tc !== null) {
        foreach (sched_nav_routes_cached($pdo, $hc, $tc, true) as $r) {
            $out[] = ['names' => implode(' → ', $r['lines'] ?: ['徒歩']), 'sum' => $r['sum'] ?? $r['min'], 'transfers' => $r['transfers'] ?? 0] + sched_nav_pass_spec($r);
        }
    }
    sched_nav_cache_put($pdo, $key, ['list' => $out]);
    return array_map($label, $out);
}

/**
 * メンバーの、出発地（'home' = 自宅最寄り駅 / 'campus'）→ スタジオ の定期外の運賃。分からなければ null
 *   $prof … ['campus' => 1|2, 'home_station' => ?string, 'pass_to' => ?string, 'pass_via' => ?string（JSON）]
 *   $fetch = false … キャッシュにあるものだけ使う（画面を開いたときはこちら。API は呼ばない）
 *   $dests … スタジオの行き先の候補（sched_studio_dests）。所要時間がいちばん短い駅の運賃
 */
function sched_member_fare(PDO $pdo, array $prof, string $origin, array $dests, int $transferMin, bool $fetch): ?int
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
        return sched_trip_best($pdo, $oc, $dests, $transferMin, $fetch, sched_pass_spec($pdo, $prof, $fetch))['fare'] ?? null;
    } catch (SchedNavMiss $e) {
        return null;
    }
}

/**
 * 自宅の最寄り駅 → スタジオ の片道（日程調整の作成画面で、スタジオを選ぶ目安に使う）
 *   fare … 定期外の運賃（円）。min … いちばん早い経路の所要時間（分。徒歩・乗車の合計 + 乗換 × $transferMin）
 *   自宅の駅が未登録・覚えた結果が無い（$fetch = false のとき）・経路が無い ときは null
 *   $dests … スタジオの行き先の候補（sched_studio_dests）。所要時間（駅まで + 徒歩）がいちばん短い駅を採る
 * @return ?array{fare: ?int, min: ?int}
 */
function sched_member_trip(PDO $pdo, array $prof, array $dests, int $transferMin, bool $fetch): ?array
{
    $home = (string)($prof['home_station'] ?? '');
    if ($home === '') {
        return null;
    }
    try {
        $hc = sched_nav_geocode($pdo, $home, $fetch);
        if ($hc === null) {
            return null;
        }
        return sched_trip_best($pdo, $hc, $dests, $transferMin, $fetch, sched_pass_spec($pdo, $prof, $fetch));
    } catch (SchedNavMiss $e) {
        return null;
    }
}

/**
 * スタジオ1つ分の目安を、メンバー全員の片道（sched_member_trip の結果。不明は null）から集計する
 *   fare … 往復の定期外の運賃の1人平均（円。分かる人だけで平均。誰も分からなければ null）
 *   lo / hi … 片道の所要時間の最短・最長（分。分かる人だけ）。差 = hi - lo
 *   known … 所要時間が分かった人数 / total … メンバーの人数
 * @param array<int, ?array{fare: ?int, min: ?int}> $trips
 * @return array{fare: ?int, lo: ?int, hi: ?int, known: int, total: int}
 */
function sched_studio_trip_stats(array $trips): array
{
    $fares = [];
    $mins = [];
    foreach ($trips as $t) {
        if ($t === null) {
            continue;
        }
        if ($t['fare'] !== null) {
            $fares[] = $t['fare'] * 2; // 往復
        }
        if ($t['min'] !== null) {
            $mins[] = $t['min'];
        }
    }
    return [
        'fare' => $fares ? (int)round(array_sum($fares) / count($fares)) : null,
        'lo' => $mins ? min($mins) : null,
        'hi' => $mins ? max($mins) : null,
        'known' => count($mins),
        'total' => count($trips),
    ];
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
