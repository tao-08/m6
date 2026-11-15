<?php
/**
 * =====================================================================
 *  lib/sched.php — スタ連の日程調整（sched*.php）の共通部品
 * =====================================================================
 *  ■ この機能の流れ
 *    1. 部員が「マイ予定」（sched_me.php）に 時間割・キャンパス・iCal の URL・NG 枠 を入れておく
 *    2. 誰かが日程調整（sched_poll）を作る … 部員・期間・時間帯・練習の長さ・遅刻の上限・候補スタジオを選ぶ
 *    3. 候補の計算（sched_candidates）… 全員の「埋まり」を集めて、練習できる枠を出す
 *    4. 仮決定・決定 … 決定した練習は、ほかの調整では「埋まり」になる
 *
 *  ■ 時刻の持ち方（計算の中だけ）
 *    「1970-01-01 からの分」（＝絶対分）の整数で持つ。日付をまたぐ予定も、ただの引き算・比較で扱える。
 *    DB には DATETIME で入れる。日本は夏時間が無いので、分に直しても1日は必ず 1440 分になる。
 *
 *  ■ 計算と DB 読み書きを分けている理由
 *    sched_candidates / sched_member_late などは「引数だけで答えが決まる関数（純粋関数）」にしてある。
 *    DB が無くてもテスト（tests/run.php）で動かせるので、計算の間違いに早く気づける。業界でも定番の設計。
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/studio_info.php'; // studio_campuses()（キャンパスの座標）・studio_sim_load()（料金の計算データ）

/* =====================================================================
 *  定数
 * ===================================================================== */

/** キャンパス（sched_profile.campus の値 => 名前）。中野・生田は扱わない（部員にいないため） */
const SCHED_CAMPUSES = [1 => '和泉', 2 => '駿河台'];

/** 学期（sched_class.term の値 => 名前） */
const SCHED_TERMS = [1 => '春学期', 2 => '秋学期'];

/** 時間割の曜日（sched_class.weekday の値 => 名前）。日曜は授業が無いので持たない */
const SCHED_WEEKDAYS = [1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土'];

/** 表示用の曜日（date('w') = 0 が日曜） */
const SCHED_DOW = ['日', '月', '火', '水', '木', '金', '土'];

/** 調整の状態（sched_poll.status の値 => 名前） */
const SCHED_STATUS = [0 => '調整中', 1 => '仮決定', 2 => '仮決定', 3 => '決定'];

/** 計算・入力の刻み（分）。NG 枠のマス・候補の開始時刻はこの単位 */
const SCHED_STEP = 30;

/** 練習の長さの選択肢（分） */
const SCHED_LENGTHS = [60, 90, 120, 150, 180, 240];

/** 遅刻の上限の選択肢（分）。0 = 全員そろう枠だけ */
const SCHED_LATE_OPTIONS = [0, 15, 30, 45, 60, 90];

/** 期間の上限（日）。長すぎると計算が重く、画面も見づらいので */
const SCHED_MAX_DAYS = 31;

/** 1つの調整に呼べる人数の上限 */
const SCHED_MAX_MEMBERS = 12;

/** 候補の数の上限（多すぎるとスマホで見切れないので、良い順に切る） */
const SCHED_MAX_WINDOWS = 120;

/**
 * 時限の時刻の初期値（0時からの分）。明治大学の 2026年度の時限（全学共通・1コマ100分）
 *   1限 9:00-10:40 / 2限 10:50-12:30 / 3限 13:30-15:10 / 4限 15:20-17:00 / 5限 17:10-18:50 / 6限 19:00-20:40
 */
const SCHED_DEFAULT_PERIODS = [1 => [540, 640], 2 => [650, 750], 3 => [810, 910], 4 => [920, 1020], 5 => [1030, 1130], 6 => [1140, 1240]];

/**
 * 学期の「授業期間」の初期値（明治大学 2026年度学年暦）。年度が替わったら設定画面（sched_settings.php）で幹部が直す
 *   春学期 4/10〜7/22、秋学期 9/20〜1/23。冬季休業 12/25〜1/7 は授業が無い
 *   定期試験の期間は授業の時間割どおりではないので、授業期間に含めない（＝時間割は効かない）
 */
const SCHED_DEFAULT_TERMS = [
    1 => ['2026-04-10', '2026-07-22'],
    2 => ['2026-09-20', '2027-01-23'],
];
const SCHED_DEFAULT_BREAKS = [['2026-12-25', '2027-01-07']];

/** 「1人あたり何帖あれば足りるか」の目安の初期値（部屋の定員が未登録のときに使う） */
const SCHED_DEFAULT_JO_PER_PERSON = 3.0;

/**
 * 移動時間の見積もり（2点の直線距離から）。sched_travel_estimate() で使う
 *   ・近い（1.2km 以内）… 徒歩。1km あたり 15分（時速4km）
 *   ・遠い … 電車。駅までの徒歩・待ち・乗り換えで 10分 ＋ 1km あたり 2.5分
 *   ・最後に 10分単位に切り上げる（細かく出しても誤差のほうが大きいので）
 *   検算: 和泉 → 新宿（直線 約5.6km）→ 10 + 14 = 24分 → 30分（部員の実測と一致）
 */
const SCHED_WALK_MAX_KM = 1.2;
const SCHED_WALK_MIN_PER_KM = 15.0;
const SCHED_RAIL_BASE_MIN = 10.0;
const SCHED_RAIL_MIN_PER_KM = 2.5;
const SCHED_TRAVEL_ROUND = 10;
/** 座標が未登録の場所との移動時間 */
const SCHED_TRAVEL_UNKNOWN = 30;

/** お知らせのカテゴリ名（lib/bootstrap.php の NOTICE_SYSTEM_CATEGORIES にも入れてある＝画面から消せない） */
const SCHED_NOTICE_CATEGORY = 'スタ連';

/* =====================================================================
 *  設定（app_setting に JSON で保存。無ければ上の初期値）
 * ===================================================================== */

/**
 * 日程調整の設定をまとめて返す
 *   ['periods' => [1 => [開始分, 終了分], …], 'terms' => [1 => [開始日, 終了日], 2 => …], 'breaks' => [[開始日, 終了日], …], 'jo' => 3.0]
 *   static … 1回のリクエストで何度呼ばれても DB は1回だけ読む
 */
function sched_settings(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $rows = [];
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM app_setting WHERE setting_key LIKE 'sched\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        // 表が無い等 → 初期値のまま
    }
    // json_decode(…, true) … JSON を連想配列に。壊れていたら null → 初期値を使う（?? の右）
    $periods = json_decode((string)($rows['sched_periods'] ?? ''), true);
    $terms = json_decode((string)($rows['sched_terms'] ?? ''), true);
    $jo = (float)($rows['sched_jo'] ?? 0);
    $cache = [
        'periods' => is_array($periods) && count($periods) === 6 ? array_combine(range(1, 6), array_values($periods)) : SCHED_DEFAULT_PERIODS,
        'terms'   => is_array($terms['terms'] ?? null) ? [1 => $terms['terms'][1] ?? $terms['terms']['1'], 2 => $terms['terms'][2] ?? $terms['terms']['2']] : SCHED_DEFAULT_TERMS,
        'breaks'  => is_array($terms['breaks'] ?? null) ? $terms['breaks'] : SCHED_DEFAULT_BREAKS,
        'jo'      => $jo > 0 ? $jo : SCHED_DEFAULT_JO_PER_PERSON,
    ];
    return $cache;
}

/** 設定を1つ保存する（REPLACE … 行があれば上書き、無ければ追加。主キー setting_key でぶつかった行を消して入れ直す） */
function sched_setting_save(PDO $pdo, string $key, string $value, int $userId): void
{
    $pdo->prepare('REPLACE INTO app_setting (setting_key, setting_value, updated_by) VALUES (?, ?, ?)')->execute([$key, $value, $userId]);
}

/* =====================================================================
 *  日付（祝日・学期・曜日区分）
 * ===================================================================== */

/**
 * その年の祝日の一覧（['2026-01-01' => true, …]）。法律（国民の祝日に関する法律）の決まりを計算で出す
 *   ・日付が決まっている祝日 … 元日・建国記念の日など
 *   ・ハッピーマンデー … 「1月の第2月曜」のように曜日で決まる祝日
 *   ・春分・秋分 … 天文の計算の近似式（1980〜2099年で使える定番の式）
 *   ・振替休日 … 祝日が日曜なら、その後の最初の「祝日でない日」が休み
 *   ・国民の休日 … 祝日と祝日にはさまれた平日（9月の敬老の日と秋分の日の間など）
 *   外部の API に頼らないので、ネットが止まっても動く（2019年以前の特例・2020/21年の五輪の移動は扱わない）
 */
function sched_holidays(int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }
    // n 番目の月曜（例: 1月の第2月曜）。strtotime の「second monday of January 2026」という英語の書き方が使える
    $nthMonday = static fn(int $month, int $n): string => date('Y-m-d', strtotime(['', 'first', 'second', 'third', 'fourth'][$n] . " monday of {$year}-{$month}"));
    $d = static fn(int $m, int $day): string => sprintf('%04d-%02d-%02d', $year, $m, $day);
    $y = $year - 1980;
    $spring = (int)floor(20.8431 + 0.242194 * $y - floor($y / 4)); // 春分の日（3月の何日か）
    $autumn = (int)floor(23.2488 + 0.242194 * $y - floor($y / 4)); // 秋分の日（9月の何日か）
    $list = [
        $d(1, 1), $nthMonday(1, 2), $d(2, 11), $d(2, 23), $d(3, $spring), $d(4, 29), $d(5, 3), $d(5, 4), $d(5, 5),
        $nthMonday(7, 3), $d(8, 11), $nthMonday(9, 3), $d(9, $autumn), $nthMonday(10, 2), $d(11, 3), $d(11, 23),
    ];
    $set = array_fill_keys($list, true);
    // 国民の休日: 前の日も次の日も祝日なら、その日も休み
    foreach ($list as $h) {
        $next2 = date('Y-m-d', strtotime($h . ' +2 day'));
        $next1 = date('Y-m-d', strtotime($h . ' +1 day'));
        if (isset($set[$next2]) && !isset($set[$next1]) && date('w', strtotime($next1)) !== '0') {
            $set[$next1] = true;
        }
    }
    // 振替休日: 日曜の祝日 → その後の「祝日でない日」
    foreach (array_keys($set) as $h) {
        if (date('w', strtotime($h)) === '0') {
            $t = strtotime($h . ' +1 day');
            while (isset($set[date('Y-m-d', $t)])) {
                $t = strtotime('+1 day', $t);
            }
            $set[date('Y-m-d', $t)] = true;
        }
    }
    return $cache[$year] = $set;
}

function sched_is_holiday(string $date): bool
{
    return isset(sched_holidays((int)substr($date, 0, 4))[$date]);
}

/**
 * スタジオの料金の曜日区分（lib/studio_info.php の STUDIO_DAY_TYPES と同じ番号）
 *   1 = 平日 / 3 = 土 / 4 = 日祝。料金の計算（JS: studioSimEngine）に渡す
 */
function sched_day_type(string $date): int
{
    $w = (int)date('w', strtotime($date));
    if ($w === 0 || sched_is_holiday($date)) {
        return 4;
    }
    return $w === 6 ? 3 : 1;
}

/**
 * その日に時間割が効く学期（1 = 春 / 2 = 秋 / null = 授業が無い日）
 *   授業期間の外・休業期間・日曜・祝日は null
 *   日付 'YYYY-MM-DD' は文字列のまま比べても大小が正しい（桁がそろっているので）
 */
function sched_term_of(string $date, array $settings): ?int
{
    if (date('w', strtotime($date)) === '0' || sched_is_holiday($date)) {
        return null;
    }
    foreach ($settings['breaks'] as [$from, $to]) {
        if ($date >= $from && $date <= $to) {
            return null;
        }
    }
    foreach ($settings['terms'] as $term => [$from, $to]) {
        if ($date >= $from && $date <= $to) {
            return (int)$term;
        }
    }
    return null;
}

/** 'YYYY-MM-DD' の日付から、その日の 0:00 の「絶対分」 */
function sched_day_abs(string $date): int
{
    return intdiv((int)strtotime($date . ' 00:00:00'), 60);
}

/** 絶対分 → 'Y-m-d H:i:s'（DB の DATETIME 用） */
function sched_abs_to_dt(int $abs): string
{
    return date('Y-m-d H:i:s', $abs * 60);
}

/** DATETIME の文字列 → 絶対分 */
function sched_dt_to_abs(string $dt): int
{
    return intdiv((int)strtotime($dt), 60);
}

/** 期間の日付の一覧（'Y-m-d' の配列。from〜to を含む） */
function sched_dates(string $from, string $to): array
{
    $out = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
        $out[] = date('Y-m-d', $t);
    }
    return $out;
}

/** 0時からの分 → 'H:MM'（1440 = 24:00 はそのまま 24:00 と出す。候補の時間帯の終わりに使う） */
function sched_hm(int $min): string
{
    return intdiv($min, 60) . ':' . sprintf('%02d', $min % 60);
}

/** '2026-11-20' → '11/20(金)' */
function sched_md(string $date): string
{
    $t = strtotime($date);
    return date('n/j', $t) . '(' . SCHED_DOW[(int)date('w', $t)] . ')';
}

/* =====================================================================
 *  移動時間
 * ===================================================================== */

/**
 * 2点の直線距離（km）。ハーバーサイン（haversine）の公式 = 地球を球として、緯度・経度から大円の距離を出す定番の式
 *   deg2rad … 度 → ラジアン。三角関数（sin・cos）はラジアンで計算するので変換が要る
 */
function sched_distance_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0; // 地球の半径（km）
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

/**
 * 距離（km）→ 移動時間（分。10分単位に切り上げ）。null（座標が無い）なら SCHED_TRAVEL_UNKNOWN
 *   ceil … 切り上げ。5分でも「10分」にする（遅れるより早く着くほうがまし）
 */
function sched_travel_estimate(?float $km): int
{
    if ($km === null) {
        return SCHED_TRAVEL_UNKNOWN;
    }
    if ($km < 0.05) {
        return 0; // ほぼ同じ場所（同じビルのスタジオなど）
    }
    $min = $km <= SCHED_WALK_MAX_KM ? $km * SCHED_WALK_MIN_PER_KM : SCHED_RAIL_BASE_MIN + $km * SCHED_RAIL_MIN_PER_KM;
    return (int)(ceil($min / SCHED_TRAVEL_ROUND) * SCHED_TRAVEL_ROUND);
}

/**
 * 場所のキー → 座標 の表。キーは 'campus:和泉' / 'studio:12'
 *   座標が無いスタジオは表に入れない（→ 移動時間は SCHED_TRAVEL_UNKNOWN）
 */
function sched_place_coords(PDO $pdo): array
{
    $out = [];
    foreach (studio_campuses() as $c) {
        $out['campus:' . $c['name']] = [(float)$c['lat'], (float)$c['lng']];
    }
    foreach ($pdo->query('SELECT studio_id, lat, lng FROM studio WHERE lat IS NOT NULL AND lng IS NOT NULL') as $s) {
        $out['studio:' . (int)$s['studio_id']] = [(float)$s['lat'], (float)$s['lng']];
    }
    return $out;
}

/** 場所のキー → 表示名（設定画面の選択肢）。キャンパス → スタジオ（並び順）の順 */
function sched_place_names(PDO $pdo): array
{
    $out = [];
    foreach (SCHED_CAMPUSES as $name) {
        $out['campus:' . $name] = $name . 'キャンパス';
    }
    foreach ($pdo->query('SELECT studio_id, name FROM studio ORDER BY sort_order, studio_id') as $s) {
        $out['studio:' . (int)$s['studio_id']] = (string)$s['name'];
    }
    return $out;
}

/** 移動時間の上書きの表（['campus:和泉|studio:3' => 40, …]。向きは両方入れる） */
function sched_travel_overrides(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT from_place, to_place, minutes FROM sched_travel') as $r) {
        $out[$r['from_place'] . '|' . $r['to_place']] = (int)$r['minutes'];
        $out[$r['to_place'] . '|' . $r['from_place']] = (int)$r['minutes'];
    }
    return $out;
}

/**
 * 移動時間を返す関数を作る（クロージャ）。sched_candidates に渡す
 *   どちらかが null（手入力・iCal の予定 = 場所がわからない）なら 0分（移動を足さない）
 *   同じ組み合わせは何百回も聞かれるので、1回計算したら $memo に覚えておく（メモ化）
 *   use (&$memo) … 外の変数を「参照で」取り込む。& が無いと、呼ばれるたびに空の $memo から始まってしまう
 */
function sched_travel_fn(array $coords, array $overrides): Closure
{
    $memo = [];
    return static function (?string $a, ?string $b) use ($coords, $overrides, &$memo): int {
        if ($a === null || $b === null || $a === $b) {
            return 0;
        }
        $key = $a . '|' . $b;
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        if (isset($overrides[$key])) {
            return $memo[$key] = $overrides[$key];
        }
        $km = isset($coords[$a], $coords[$b]) ? sched_distance_km($coords[$a][0], $coords[$a][1], $coords[$b][0], $coords[$b][1]) : null;
        return $memo[$key] = sched_travel_estimate($km);
    };
}

/**
 * 部員のキャンパス（1 = 和泉 / 2 = 駿河台）。プロフィールで選んでいればそれ、無ければ学年から
 *   1・2年 → 和泉、3年以上（OB も）→ 駿河台。入学年度が未登録なら和泉
 */
function sched_member_campus(?int $chosen, ?int $entryYear): int
{
    if ($chosen !== null && isset(SCHED_CAMPUSES[$chosen])) {
        return $chosen;
    }
    $grade = grade_of($entryYear);
    return $grade !== null && $grade >= 3 ? 2 : 1;
}

/* =====================================================================
 *  候補の計算（純粋関数。DB を使わない）
 * ===================================================================== */

/**
 * 1人の部員が、時刻 t0 から len 分の練習に「何分遅れで来られるか」
 *   戻り値: 遅れの分（0 = 最初からいる）/ null = 来られない（途中で抜ける必要がある・遅れが上限を超える）
 *
 *   $busy  … [[開始, 終了, 場所キー|null], …]（絶対分）
 *   $place … 練習するスタジオの場所キー
 *   考え方: 埋まりの前後に移動時間を足して「動けない時間」に広げ、
 *           練習の開始より前から続いている分は「遅刻」、練習の途中で始まるものは「来られない」（早退は扱わない）
 */
function sched_member_late(array $busy, int $t0, int $len, string $place, callable $travel, int $lateMax): ?int
{
    $end = $t0 + $len;
    $blocks = [];
    foreach ($busy as [$s, $e, $p]) {
        $bs = $s - $travel($place, $p); // 練習の後にその予定へ向かう移動
        $be = $e + $travel($p, $place); // その予定から練習へ向かう移動
        if ($be > $t0 && $bs < $end) {   // 練習の時間に重なるものだけ見る
            $blocks[] = [$bs, $be];
        }
    }
    // 始まりの早い順に並べる。<=> は「宇宙船演算子」: 左が小さければ -1、同じなら 0、大きければ 1
    usort($blocks, static fn($x, $y) => $x[0] <=> $y[0]);
    $arrive = $t0; // 来られる時刻
    foreach ($blocks as [$bs, $be]) {
        if ($bs <= $arrive) {
            $arrive = max($arrive, $be); // 来る前から続いている → 来られる時刻が後ろにずれる
        } else {
            return null; // 来たあとに予定が入っている → 途中で抜けないといけない
        }
    }
    $late = $arrive - $t0;
    return ($late > $lateMax || $arrive >= $end) ? null : $late;
}

/**
 * 練習できる枠（候補）を計算する
 *
 * 引数 $p（連想配列）:
 *   dates      … ['2026-11-20', …]
 *   day_start / day_end / length / late_max … 分
 *   members    … [member_id => 名前]
 *   busy       … [member_id => [[開始, 終了, 場所キー|null], …]]（絶対分）。授業・手入力・iCal・決定済みの練習
 *   studios    … [studio_id => ['place' => 'studio:12']]
 *   practices  … ほかの調整の仮決定・決定（連続の判定と注意の表示に使う）
 *                [['id', 'title', 'status', 'date', 's'|null, 'e'|null, 'studio_id'|null, 'members' => [id, …]], …]
 *   travel     … sched_travel_fn() の関数
 *   not_before … （省略可）この絶対分より前に始まる枠は出さない（今より前を外す）
 *
 * 戻り値: 候補（窓）の配列。開始時刻が30分ずつ並んだ「同じ条件の枠」は1つの窓にまとめる
 *   ['date', 'studio_id', 'starts' => [開始の分…], 'from' => 最初の開始, 'to' => 最後の終了,
 *    'late' => [member_id => 分], 'adj' => 2(◎)|1(○)|0, 'adj_with' => [題…], 'warn' => [題…]]
 */
function sched_candidates(array $p): array
{
    $step = SCHED_STEP;
    $len = (int)$p['length'];
    $windows = [];
    foreach ($p['dates'] as $date) {
        $day0 = sched_day_abs($date);
        // その日の「日だけ仮決定」＝ 時間は未定だが、その日は注意
        $dayWarn = [];
        foreach ($p['practices'] as $pr) {
            if ($pr['date'] === $date && (int)$pr['status'] === 1 && array_intersect($pr['members'], array_keys($p['members']))) {
                $dayWarn[] = $pr['title'];
            }
        }
        foreach ($p['studios'] as $sid => $studio) {
            $prev = null; // 直前の開始時刻の結果（同じ条件なら窓を伸ばす）
            for ($m = (int)$p['day_start']; $m + $len <= (int)$p['day_end']; $m += $step) {
                $t0 = $day0 + $m;
                if ($t0 < ($p['not_before'] ?? PHP_INT_MIN)) {
                    $prev = null;
                    continue; // もう過ぎた時間
                }
                // ---- 全員の遅れを調べる（1人でも来られなければ、この開始時刻は候補にしない）----
                $late = [];
                $ok = true;
                foreach ($p['members'] as $mid => $_name) {
                    $l = sched_member_late($p['busy'][$mid] ?? [], $t0, $len, $studio['place'], $p['travel'], (int)$p['late_max']);
                    if ($l === null) {
                        $ok = false;
                        break;
                    }
                    if ($l > 0) {
                        $late[$mid] = $l;
                    }
                }
                if (!$ok) {
                    $prev = null;
                    continue;
                }
                // ---- 連続（同じスタジオの、ほかの練習の直前・直後）と、仮決定（日時）の注意 ----
                $adj = 0;
                $adjWith = [];
                $warn = $dayWarn;
                foreach ($p['practices'] as $pr) {
                    if ($pr['s'] === null || (int)$pr['status'] < 2) {
                        continue;
                    }
                    $shared = (bool)array_intersect($pr['members'], array_keys($p['members']));
                    if ((int)$pr['studio_id'] === (int)$sid && ($pr['e'] === $t0 || $pr['s'] === $t0 + $len)) {
                        $adj = max($adj, $shared ? 2 : 1);
                        $adjWith[] = $pr['title'];
                    }
                    // 仮決定（日時）は埋まりにしない代わりに、重なる共通メンバーがいれば注意を出す
                    if ((int)$pr['status'] === 2 && $shared && $pr['s'] < $t0 + $len && $pr['e'] > $t0) {
                        $warn[] = $pr['title'];
                    }
                }
                ksort($late);
                $warn = array_values(array_unique($warn));
                $sig = json_encode([$late, $adj, $adjWith, $warn]); // 「同じ条件か」を比べるための目印
                if ($prev !== null && $prev['sig'] === $sig) {
                    // 前の開始時刻と同じ条件 → 窓を30分伸ばす（&$windows[...] は配列の中身を直接書き換えるための参照）
                    $w = &$windows[$prev['i']];
                    $w['starts'][] = $m;
                    $w['to'] = $m + $len;
                    unset($w); // 参照を切る（切らないと、次に $w へ代入したとき配列の中身まで変わってしまう。PHP のよくある落とし穴）
                } else {
                    $windows[] = [
                        'date' => $date, 'studio_id' => (int)$sid, 'starts' => [$m], 'from' => $m, 'to' => $m + $len,
                        'late' => $late, 'adj' => $adj, 'adj_with' => array_values(array_unique($adjWith)), 'warn' => $warn,
                    ];
                    $prev = ['i' => array_key_last($windows), 'sig' => $sig];
                }
            }
        }
    }
    // 並び順: 全員そろう → 連続◎ → 連続○ → 日付 → 時刻（料金での並べ替えは画面の JS でする）
    usort($windows, static fn($a, $b) => [count($a['late']) > 0, -$a['adj'], array_sum($a['late']), $a['date'], $a['from']]
        <=> [count($b['late']) > 0, -$b['adj'], array_sum($b['late']), $b['date'], $b['from']]);
    return array_slice($windows, 0, SCHED_MAX_WINDOWS);
}

/**
 * 部屋が人数に合うか
 *   'ok' … 定員（無ければ 広さ ÷ 1人あたりの帖）が人数以上 / 'small' … 狭すぎる / 'unknown' … 定員も広さも不明
 */
function sched_room_fit(?int $capacity, ?float $areaJo, int $people, float $joPerPerson): string
{
    if ($capacity !== null && $capacity > 0) {
        return $capacity >= $people ? 'ok' : 'small';
    }
    if ($areaJo !== null && $areaJo > 0) {
        return $areaJo / $joPerPerson >= $people ? 'ok' : 'small';
    }
    return 'unknown';
}

/* =====================================================================
 *  DB の読み書き
 * ===================================================================== */

/** 調整1件（無ければ null） */
function sched_poll_load(PDO $pdo, int $pollId, bool $lock = false): ?array
{
    // FOR UPDATE … トランザクションの間、この行を他の人が書き換えられないように鍵をかける（行ロック）
    $st = $pdo->prepare('SELECT * FROM sched_poll WHERE sched_poll_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$pollId]);
    return $st->fetch() ?: null;
}

/** 調整に呼ばれた部員（名前・回答日時・紐づくアカウント・iCal の有無つき。名前のふりがな順） */
function sched_poll_members(PDO $pdo, int $pollId): array
{
    $st = $pdo->prepare('SELECT m.member_id, m.name, m.entry_year, pm.answered_at, MIN(u.user_id) AS user_id,
            (p.ical_url IS NOT NULL) AS has_ical, p.ical_error
        FROM sched_poll_member pm
        JOIN member m ON m.member_id = pm.member_id
        LEFT JOIN user_account u ON u.member_id = m.member_id
        LEFT JOIN sched_profile p ON p.member_id = m.member_id
        WHERE pm.sched_poll_id = ?
        GROUP BY m.member_id, m.name, m.entry_year, pm.answered_at, p.ical_url, p.ical_error, m.name_kana
        ORDER BY m.name_kana, m.name');
    $st->execute([$pollId]);
    return $st->fetchAll();
}

/** 調整の候補スタジオ（名前・エリア・予約ページつき） */
function sched_poll_studios(PDO $pdo, int $pollId): array
{
    $st = $pdo->prepare('SELECT s.studio_id, s.name, s.area, s.reserve_url, s.website_url, s.address FROM sched_poll_studio ps
        JOIN studio s ON s.studio_id = ps.studio_id WHERE ps.sched_poll_id = ? ORDER BY s.sort_order, s.studio_id');
    $st->execute([$pollId]);
    return $st->fetchAll();
}

/**
 * 調整を操作できるか（候補の確定・編集・削除）。作った人・呼ばれた部員・幹部
 *   $memberIds … その調整の部員の id
 */
function sched_can_manage(array $poll, array $user, array $memberIds): bool
{
    return (int)$poll['created_by'] === (int)$user['user_id']
        || ($user['member_id'] !== null && in_array((int)$user['member_id'], array_map('intval', $memberIds), true))
        || is_executive();
}

/**
 * 部員たちの「埋まり」と、ほかの調整の仮決定・決定を集める（sched_candidates に渡す形）
 *   $exceptPollId … いま計算している調整（自分自身の決定は埋まりにしない）
 *   戻り値: ['busy' => [member_id => [[開始, 終了, 場所キー|null, 種類, ラベル], …]], 'practices' => [...], 'campus' => [member_id => 1|2]]
 *   種類: class（授業）/ manual（手入力）/ ical / practice（決定済みの練習）
 */
function sched_collect_busy(PDO $pdo, array $memberIds, string $from, string $to, ?int $exceptPollId): array
{
    $out = ['busy' => [], 'practices' => [], 'campus' => []];
    $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
    if (!$memberIds) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($memberIds), '?')); // IN (?, ?, ?) の ? を人数分作る
    $settings = sched_settings($pdo);
    $fromAbs = sched_day_abs($from);
    $toAbs = sched_day_abs($to) + 1440;

    // ---- キャンパス（プロフィール → 無ければ学年）----
    $st = $pdo->prepare("SELECT m.member_id, m.entry_year, p.campus FROM member m LEFT JOIN sched_profile p ON p.member_id = m.member_id WHERE m.member_id IN ($in)");
    $st->execute($memberIds);
    foreach ($st as $r) {
        $out['campus'][(int)$r['member_id']] = sched_member_campus($r['campus'] === null ? null : (int)$r['campus'], $r['entry_year'] === null ? null : (int)$r['entry_year']);
    }

    // ---- 授業（時間割 × 期間の日付）----
    $st = $pdo->prepare("SELECT member_id, term, weekday, period FROM sched_class WHERE member_id IN ($in)");
    $st->execute($memberIds);
    $classes = []; // [member_id][term][weekday] = [period, …]
    foreach ($st as $r) {
        $classes[(int)$r['member_id']][(int)$r['term']][(int)$r['weekday']][] = (int)$r['period'];
    }
    foreach (sched_dates($from, $to) as $date) {
        $term = sched_term_of($date, $settings);
        if ($term === null) {
            continue;
        }
        $wd = (int)date('N', strtotime($date)); // N … 1 = 月 〜 7 = 日
        $day0 = sched_day_abs($date);
        foreach ($classes as $mid => $byTerm) {
            foreach ($byTerm[$term][$wd] ?? [] as $period) {
                [$s, $e] = $settings['periods'][$period];
                $place = 'campus:' . SCHED_CAMPUSES[$out['campus'][$mid] ?? 1];
                $out['busy'][$mid][] = [$day0 + $s, $day0 + $e, $place, 'class', $period . '限'];
            }
        }
    }

    // ---- 手入力・iCal の埋まり ----
    $st = $pdo->prepare("SELECT member_id, start_at, end_at, source FROM sched_busy WHERE member_id IN ($in) AND end_at > ? AND start_at < ?");
    $st->execute([...$memberIds, sched_abs_to_dt($fromAbs), sched_abs_to_dt($toAbs)]);
    foreach ($st as $r) {
        $out['busy'][(int)$r['member_id']][] = [sched_dt_to_abs($r['start_at']), sched_dt_to_abs($r['end_at']), null, (int)$r['source'] === 1 ? 'ical' : 'manual', ''];
    }

    // ---- ほかの調整の仮決定・決定（この部員たちが1人でも入っているもの）----
    $st = $pdo->prepare("SELECT DISTINCT p.sched_poll_id, p.title, p.status, p.fix_date, p.fix_start, p.fix_end, p.fix_studio_id
        FROM sched_poll p JOIN sched_poll_member pm ON pm.sched_poll_id = p.sched_poll_id
        WHERE p.status >= 1 AND pm.member_id IN ($in) AND p.fix_date BETWEEN ? AND ? AND p.sched_poll_id <> ?");
    $st->execute([...$memberIds, $from, $to, $exceptPollId ?? 0]);
    $polls = $st->fetchAll();
    $practiceIds = array_map(static fn($r) => (int)$r['sched_poll_id'], $polls);
    // 連続の判定（○）は、部員が重ならない練習も見るので、同じ期間の「決定・仮決定（日時）」を全部足す
    $st = $pdo->prepare('SELECT sched_poll_id, title, status, fix_date, fix_start, fix_end, fix_studio_id FROM sched_poll
        WHERE status >= 2 AND fix_date BETWEEN ? AND ? AND sched_poll_id <> ?');
    $st->execute([$from, $to, $exceptPollId ?? 0]);
    foreach ($st as $r) {
        if (!in_array((int)$r['sched_poll_id'], $practiceIds, true)) {
            $polls[] = $r;
            $practiceIds[] = (int)$r['sched_poll_id'];
        }
    }
    $members = [];
    if ($practiceIds) {
        $in2 = implode(',', array_fill(0, count($practiceIds), '?'));
        $st = $pdo->prepare("SELECT sched_poll_id, member_id FROM sched_poll_member WHERE sched_poll_id IN ($in2)");
        $st->execute($practiceIds);
        foreach ($st as $r) {
            $members[(int)$r['sched_poll_id']][] = (int)$r['member_id'];
        }
    }
    foreach ($polls as $r) {
        $pid = (int)$r['sched_poll_id'];
        $s = $r['fix_start'] !== null ? sched_dt_to_abs($r['fix_start']) : null;
        $e = $r['fix_end'] !== null ? sched_dt_to_abs($r['fix_end']) : null;
        $pm = $members[$pid] ?? [];
        $out['practices'][] = [
            'id' => $pid, 'title' => (string)$r['title'], 'status' => (int)$r['status'], 'date' => (string)$r['fix_date'],
            's' => $s, 'e' => $e, 'studio_id' => $r['fix_studio_id'] === null ? null : (int)$r['fix_studio_id'], 'members' => $pm,
        ];
        // 決定（3）だけ、その部員の埋まりにする（仮決定は注意だけ）
        if ((int)$r['status'] === 3 && $s !== null) {
            foreach (array_intersect($pm, $memberIds) as $mid) {
                $out['busy'][$mid][] = [$s, $e, $r['fix_studio_id'] === null ? null : 'studio:' . (int)$r['fix_studio_id'], 'practice', (string)$r['title']];
            }
        }
    }
    return $out;
}

/**
 * 調整の部員にお知らせ（＋プッシュ通知）を出す。自分（操作した人）には出さない
 *   お知らせは notice テーブルに宛先つきで入れる。プッシュ通知は partials/header.php の push_dispatch_after_response() が拾って送る
 */
function sched_notify(PDO $pdo, int $pollId, string $title, string $body, int $exceptUserId): void
{
    $st = $pdo->prepare('SELECT DISTINCT u.user_id FROM sched_poll_member pm JOIN user_account u ON u.member_id = pm.member_id
        WHERE pm.sched_poll_id = ?');
    $st->execute([$pollId]);
    sched_notify_users($pdo, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), $pollId, $title, $body, $exceptUserId);
}

/** 決まった人たち（user_id）にお知らせを出す。本文の [[リンク先|文字]] はリンクになる（notice_body_html） */
function sched_notify_users(PDO $pdo, array $userIds, int $pollId, string $title, string $body, int $exceptUserId): void
{
    $targets = array_values(array_diff(array_unique($userIds), [$exceptUserId]));
    if (!$targets) {
        return;
    }
    // INSERT IGNORE … カテゴリ名は UNIQUE なので、2回目以降は何もしない（無ければ作る、の定番の書き方）
    $pdo->prepare('INSERT IGNORE INTO notice_category (name, color) VALUES (?, ?)')->execute([SCHED_NOTICE_CATEGORY, 'green']);
    $st = $pdo->prepare('SELECT category_id FROM notice_category WHERE name = ?');
    $st->execute([SCHED_NOTICE_CATEGORY]);
    $cat = (int)$st->fetchColumn();
    $ins = $pdo->prepare('INSERT INTO notice (user_id, target_user_id, category_id, is_important, title, body, link_url) VALUES (?, ?, ?, 0, ?, ?, ?)');
    foreach ($targets as $uid) {
        $ins->execute([$exceptUserId, $uid, $cat, mb_substr($title, 0, 100), $body, 'sched_poll?id=' . $pollId]);
    }
}

/** 状態のラベル（一覧・詳細の見出し）。仮決定は黄、決定は緑 */
function sched_status_pill(array $poll): string
{
    $s = (int)$poll['status'];
    $cls = match ($s) { 3 => ' pill--ok', 1, 2 => ' pill--warn', default => '' };
    return '<span class="pill' . $cls . '">' . h(SCHED_STATUS[$s] ?? '') . '</span>';
}

/* =====================================================================
 *  カレンダーに追加（Google のリンク・.ics ファイル）
 * ===================================================================== */

/**
 * Google カレンダーの「予定を作成」画面を開く URL。ログイン連携（OAuth）なしで、押した人のカレンダーに1タップで入る
 *   dates は UTC ではなく「現地時刻」で書き、ctz（タイムゾーン）を付ける
 *   http_build_query … 配列を ?a=1&b=2 の形にし、日本語や記号を URL で使える形（%E3%81%82…）に変換してくれる
 */
function sched_gcal_url(string $title, int $startAbs, int $endAbs, string $location, string $details): string
{
    $fmt = static fn(int $abs): string => date('Ymd\THis', $abs * 60);
    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action' => 'TEMPLATE', 'text' => $title, 'dates' => $fmt($startAbs) . '/' . $fmt($endAbs),
        'ctz' => 'Asia/Tokyo', 'location' => $location, 'details' => $details,
    ]);
}

/**
 * .ics ファイル（iCalendar 形式。RFC 5545 という国際規格）の中身。iPhone・Android・Outlook どれでも開ける
 *   ・改行は CRLF（\r\n）と決まっている
 *   ・文字の , ; \ 改行 は \ でエスケープする（規格の決まり）
 *   ・UID … 予定の背番号。同じ UID のファイルをもう一度開くと「更新」として扱われる
 */
function sched_ics(int $pollId, string $title, int $startAbs, int $endAbs, string $location, string $details): string
{
    $esc = static fn(string $s): string => str_replace(["\\", "\n", ',', ';'], ["\\\\", "\\n", '\\,', '\\;'], str_replace("\r", '', $s));
    $utc = static fn(int $abs): string => gmdate('Ymd\THis\Z', $abs * 60); // 世界標準時（Z = UTC）で書くと、端末のタイムゾーンに関係なく正しく出る
    $host = (defined('APP_URL') ? parse_url(APP_URL, PHP_URL_HOST) : null) ?: 'abbeyroad.online'; // defined … 定数があるか（テストでは bootstrap を読まないので無い）
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//AbbeyRoad.online//sched//JA', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:sched-' . $pollId . '@' . $host,
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . $utc($startAbs),
        'DTEND:' . $utc($endAbs),
        'SUMMARY:' . $esc($title),
        'LOCATION:' . $esc($location),
        'DESCRIPTION:' . $esc($details),
        'END:VEVENT', 'END:VCALENDAR',
    ];
    // 1行は 75 バイトまで（規格の決まり）。長い行は「改行＋空白1つ」で折り返す（読む側はつなげ直す）。
    //   日本語の1文字（UTF-8 で3バイト）の途中で切らないよう、mb_strcut（バイト数で切るが、文字の途中では切らない）を使う
    $folded = [];
    foreach ($lines as $line) {
        $first = true;
        while (strlen($line) > ($first ? 75 : 74)) {
            $part = mb_strcut($line, 0, $first ? 75 : 74, 'UTF-8');
            $folded[] = ($first ? '' : ' ') . $part;
            $line = substr($line, strlen($part));
            $first = false;
        }
        $folded[] = ($first ? '' : ' ') . $line;
    }
    return implode("\r\n", $folded) . "\r\n";
}
