<?php
/**
 * =====================================================================
 *  lib/studio.php — スタジオ割（studio.php / studio_api.php / studio_answer.php）の部品
 * =====================================================================
 *  スタジオ割の state（studio_plan.state）は JSON 1個。形はこう:
 *    {
 *      "rooms":   ["D1", "D2", "D3", "E1"],        … 部屋の名前（並び = 表の列の並び）
 *      "slotMin": 90,                              … 練習1枠の分数（既定）。変えると全部の時刻が変わる
 *      "title":   "夏合宿 スタジオ割",              … 画像の一番上に出す見出し（空ならライブ名）
 *      "rows": [                                   … 表の行（上から順に）
 *        {"id": "r1", "t": "day",   "x": "8月6日"},                               … 日付の見出し（時刻はここで一旦リセット）
 *        {"id": "r2", "t": "block", "start": "15:00", "n": 2, "min": null},        … 練習の時間帯（n 枠。min = この時間帯だけの分数。null = slotMin）
 *        {"id": "r3", "t": "text",  "x": "昼食", "start": null, "min": 60},        … 自由記入（昼食・移動など）
 *        {"id": "r4", "t": "live",  "x": "Live 1日目", "d": 1, "start": "13:00", "min": 540} … ライブ枠（d = 何日目のライブか）
 *      ],
 *      "cells": {"r2:0:1": 123}                    … 「行id:何枠目:何番目の部屋」=> band_id
 *    }
 *  start が null の行は、1つ上の行の終わりから続ける（時刻は画面と画像で計算する。保存はしない）。
 *  「ライブ枠」の行は、自動配置で「このライブより前に練習を終える」「2日目のバンドは1日目のライブ後に寄せる」の基準になる。
 *
 *  ★ この形を変えるときは assets/studio.js の norm / 時刻計算も一緒に直すこと
 * =====================================================================
 */
declare(strict_types=1);

const STUDIO_MAX_ROOMS = 10;      // 部屋の数の上限
const STUDIO_MAX_ROWS = 60;       // 表の行の上限
const STUDIO_MAX_SLOTS = 12;      // 1つの時間帯の枠の数の上限
const STUDIO_ROW_ID = '/^[a-z][a-z0-9]{0,15}$/';      // 行 id の形（英小文字で始まる英数字。セルのキー "id:枠:部屋" の区切りと混ざらない）
const STUDIO_TIME = '/^([01]\d|2[0-9]):[0-5]\d$/';    // 時刻 "HH:MM"。深夜練習の「25:00」も書けるように 29 時台まで許す
const STUDIO_MAX_STATE_BYTES = 300_000;

/**
 * ライブの日程（live_day）と、バンド・メンバーをまとめて読む。
 * @return array{days: array<int,array>, bands: array<int,array>}
 *   days  … [['n' => 1, 'label' => '1日目', 'held_on' => '2026-08-07'], ...]（開催日の順。n = 何日目か）
 *   bands … band_id => ['id', 'name', 'day' => n, 'order' => 出演順, 'members' => [['id' => member_id, 'name' => 名前], ...]]
 */
function studio_bands(PDO $pdo, int $liveId): array
{
    // 日程は「開催日の順」（開催日が未定の日は後ろ）。同じ日付なら作った順（live_day_id）
    //   ORDER BY held_on IS NULL … NULL の行は 1、日付がある行は 0 になる → 0 が先に並ぶ（MySQL で NULL を後ろにする定番の書き方）
    $st = $pdo->prepare('SELECT live_day_id, label, held_on FROM live_day WHERE live_id = ?
        ORDER BY held_on IS NULL, held_on, live_day_id');
    $st->execute([$liveId]);
    $days = [];
    $dayNo = []; // live_day_id => 何日目か
    foreach ($st->fetchAll() as $i => $r) {
        $days[] = ['n' => $i + 1, 'label' => (string)$r['label'], 'held_on' => $r['held_on']];
        $dayNo[(int)$r['live_day_id']] = $i + 1;
    }
    if (!$days) {
        return ['days' => [], 'bands' => []];
    }

    // バンドとメンバー。LEFT JOIN … メンバー未登録のバンドも1行として出す（m.member_id が NULL になる）
    // 1人が同じバンドで2つの楽器を担当すると band_member が2行になるので、PHP 側で人の重複を消す
    $st = $pdo->prepare('SELECT b.band_id, b.name, b.live_day_id, b.play_order, m.member_id, m.name AS member_name
        FROM band b
        JOIN live_day d ON d.live_day_id = b.live_day_id
        LEFT JOIN band_member bm ON bm.band_id = b.band_id
        LEFT JOIN member m ON m.member_id = bm.member_id
        WHERE d.live_id = ?
        ORDER BY b.play_order, bm.instrument_id');
    $st->execute([$liveId]);
    $bands = [];
    foreach ($st->fetchAll() as $r) {
        $id = (int)$r['band_id'];
        // ??= は「まだ無いときだけ代入」（PHP 7.4〜）。バンドの最初の行で入れ物を作る
        $bands[$id] ??= ['id' => $id, 'name' => (string)$r['name'], 'day' => $dayNo[(int)$r['live_day_id']] ?? 1,
            'order' => (int)$r['play_order'], 'members' => []];
        if ($r['member_id'] !== null) {
            // キーを member_id にして入れる → 同じ人が2回来ても上書きされるだけ（重複が消える）
            $bands[$id]['members'][(int)$r['member_id']] = ['id' => (int)$r['member_id'], 'name' => (string)$r['member_name']];
        }
    }
    // 並び: 何日目 → 出演順（usort は「比べる関数」で並べ替える。<=> は左が小さいと -1、同じで 0、大きいと 1 を返す宇宙船演算子）
    uasort($bands, static fn($a, $b) => [$a['day'], $a['order']] <=> [$b['day'], $b['order']]);
    foreach ($bands as &$b) {
        $b['members'] = array_values($b['members']); // キー（member_id）を外して普通の配列に（JSON で [] になるように）
    }
    unset($b); // 参照（&$b）を使った foreach の後は unset する（後で $b を使うと最後の要素を書き換えてしまう、PHP の有名な落とし穴）
    return ['days' => $days, 'bands' => $bands];
}

/**
 * バンドごとの「練習が必要か」の回答。
 * @param int[] $bandIds
 * @return array<int,array> band_id => ['need' => 0|1, 'by' => 回答した人の名前|null, 'link' => 共有リンクからか, 'at' => 'n/j H:i']
 */
function studio_needs(PDO $pdo, array $bandIds): array
{
    if (!$bandIds) {
        return [];
    }
    // IN (?, ?, ?) のプレースホルダを、ID の数だけ作る（値は execute に渡すので SQL インジェクションにならない）
    $in = implode(',', array_fill(0, count($bandIds), '?'));
    $st = $pdo->prepare("SELECT n.band_id, n.need, n.via_link, n.answered_at, u.name AS user_name
        FROM studio_need n LEFT JOIN user_account u ON u.user_id = n.answered_by
        WHERE n.band_id IN ($in)");
    $st->execute(array_values($bandIds));
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['band_id']] = [
            'need' => (int)$r['need'],
            'by' => $r['user_name'],
            'link' => (bool)$r['via_link'],
            'at' => date('n/j H:i', strtotime((string)$r['answered_at'])),
        ];
    }
    return $out;
}

/**
 * 回答を1件保存する（あれば上書き）。
 *   INSERT … ON DUPLICATE KEY UPDATE … 「主キー（band_id）が既にあれば UPDATE」を1文でやる MySQL の書き方（UPSERT と呼ばれる）
 *   $need が null なら回答を消す（未回答に戻す）
 */
function studio_save_need(PDO $pdo, int $bandId, ?int $need, ?int $userId, bool $viaLink): void
{
    if ($need === null) {
        $pdo->prepare('DELETE FROM studio_need WHERE band_id = ?')->execute([$bandId]);
        return;
    }
    $pdo->prepare('INSERT INTO studio_need (band_id, need, answered_by, via_link, answered_at) VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE need = VALUES(need), answered_by = VALUES(answered_by), via_link = VALUES(via_link), answered_at = VALUES(answered_at)')
        ->execute([$bandId, $need, $userId, $viaLink ? 1 : 0]);
}

/** スタジオ割を1枚読む（無ければ null）。$lock = true なら SELECT … FOR UPDATE（トランザクションの中で行をロック） */
function studio_load(PDO $pdo, int $liveId, bool $lock = false): ?array
{
    $st = $pdo->prepare('SELECT * FROM studio_plan WHERE live_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$liveId]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $row['state'] = json_decode((string)$row['state'], true) ?: [];
    return $row;
}

/** 共有リンクの合言葉。random_bytes = 暗号用の乱数（rand() や mt_rand() は推測できるので合言葉には使わない） */
function studio_new_token(): string
{
    return bin2hex(random_bytes(16)); // 16バイト → 16進数で32文字
}

/**
 * 新しく作るときの state。日程に合わせて、よくある形を最初から入れておく（後で自由に直せる）
 *   1日だけ … 練習（2枠）→ ライブ
 *   2日以上 … 練習（2枠）→ 1日目のライブ → 練習（2枠）→ 2日目のライブ …（最後のライブの後には練習を置かない）
 */
function studio_default_state(array $days, string $liveName): array
{
    $rows = [];
    $seq = 0;
    $id = static function () use (&$seq): string { return 'r' . (++$seq); }; // 'r1', 'r2', … の行 id を作る小さな関数
    foreach ($days as $i => $d) {
        $date = $d['held_on'] ? date('n月j日', strtotime((string)$d['held_on'])) : $d['label'];
        $rows[] = ['id' => $id(), 't' => 'day', 'x' => $date];
        if ($i === 0) {
            $rows[] = ['id' => $id(), 't' => 'block', 'start' => '10:00', 'n' => 2, 'min' => null];
        }
        $rows[] = ['id' => $id(), 't' => 'live', 'x' => 'Live ' . $d['label'], 'd' => $d['n'], 'start' => '13:00', 'min' => 540];
        if ($i < count($days) - 1) {
            $rows[] = ['id' => $id(), 't' => 'block', 'start' => null, 'n' => 2, 'min' => null];
        }
    }
    return [
        'rooms' => ['A', 'B', 'C', 'D'],
        'slotMin' => 90,
        'title' => $liveName . ' スタジオ割',
        'rows' => $rows,
        'cells' => new stdClass(), // 空のオブジェクト。[] だと JSON で配列になってしまうので stdClass を使う
    ];
}

/**
 * 画面から送られてきた state を確かめて、きれいな形にして返す（おかしければ null）。
 * ★ ブラウザから来るデータは「誰でも好きな値に書き換えて送れる」ので、型・長さ・範囲を全部サーバーで確かめる（業界の鉄則: クライアントを信用しない）
 * @param int[] $bandIds このライブのバンドの id（他のライブのバンドを紛れ込ませない）
 */
function studio_sanitize(mixed $s, array $bandIds): ?array
{
    if (!is_array($s)) {
        return null;
    }
    // 文字列を「最大◯文字に切って、前後の空白を取る」小さな関数
    $str = static fn(mixed $x, int $max): string => is_string($x) ? mb_substr(trim($x), 0, $max) : '';
    // 整数で、min〜max の範囲ならその値、そうでなければ null
    $int = static fn(mixed $x, int $min, int $max): ?int => is_int($x) && $x >= $min && $x <= $max ? $x : null;
    // 時刻（"HH:MM"）なら値、そうでなければ null
    $time = static fn(mixed $x): ?string => is_string($x) && preg_match(STUDIO_TIME, $x) ? $x : null;

    // ---- 部屋 ----
    $rooms = [];
    foreach (array_slice(is_array($s['rooms'] ?? null) ? $s['rooms'] : [], 0, STUDIO_MAX_ROOMS) as $r) {
        $rooms[] = $str($r, 12);
    }
    if (!$rooms) {
        return null;
    }
    $slotMin = $int($s['slotMin'] ?? null, 5, 600);
    if ($slotMin === null) {
        return null;
    }

    // ---- 行 ----
    $rows = [];
    $seen = []; // 同じ id の行が2つあったら、2つ目は捨てる
    foreach (array_slice(is_array($s['rows'] ?? null) ? $s['rows'] : [], 0, STUDIO_MAX_ROWS) as $r) {
        $id = is_array($r) && is_string($r['id'] ?? null) && preg_match(STUDIO_ROW_ID, $r['id']) ? $r['id'] : null;
        if ($id === null || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        // match 式（PHP 8〜）: switch より短く書けて、どれにも当たらないと default。値を返せるのが switch との違い
        $row = match ($r['t'] ?? null) {
            'day' => ['id' => $id, 't' => 'day', 'x' => $str($r['x'] ?? '', 30)],
            'block' => ['id' => $id, 't' => 'block', 'start' => $time($r['start'] ?? null),
                'n' => $int($r['n'] ?? null, 1, STUDIO_MAX_SLOTS) ?? 1, 'min' => $int($r['min'] ?? null, 5, 600)],
            'text' => ['id' => $id, 't' => 'text', 'x' => $str($r['x'] ?? '', 40), 'start' => $time($r['start'] ?? null),
                'min' => $int($r['min'] ?? null, 0, 1440)],
            'live' => ['id' => $id, 't' => 'live', 'x' => $str($r['x'] ?? '', 40), 'd' => $int($r['d'] ?? null, 1, 9) ?? 1,
                'start' => $time($r['start'] ?? null), 'min' => $int($r['min'] ?? null, 0, 1440)],
            default => null,
        };
        if ($row !== null) {
            $rows[] = $row;
        }
    }

    // ---- セル（どの枠のどの部屋にどのバンドか）----
    // 存在する行・枠・部屋のセルだけ残す。同じバンドが2か所にあったら、先に見つけた方だけ残す
    $blocks = [];
    foreach ($rows as $r) {
        if ($r['t'] === 'block') {
            $blocks[$r['id']] = $r['n'];
        }
    }
    $valid = array_flip($bandIds); // array_flip で [id => 0, ...] にすると isset で速く調べられる
    $cells = [];
    $used = [];
    foreach (is_array($s['cells'] ?? null) ? $s['cells'] : [] as $k => $band) {
        if (!is_string($k) || !preg_match('/^([a-z][a-z0-9]{0,15}):(\d{1,2}):(\d{1,2})$/', $k, $m)) {
            continue;
        }
        [, $rid, $slot, $room] = $m; // 分割代入（最初の要素 = 全体の一致 は捨てる）
        if (!isset($blocks[$rid]) || (int)$slot >= $blocks[$rid] || (int)$room >= count($rooms)
            || !is_int($band) || !isset($valid[$band]) || isset($used[$band])) {
            continue;
        }
        $used[$band] = true;
        $cells[$k] = $band;
    }

    return [
        'rooms' => $rooms,
        'slotMin' => $slotMin,
        'title' => $str($s['title'] ?? '', 60),
        'rows' => $rows,
        'cells' => $cells ?: new stdClass(),
    ];
}

/** <script type="application/json"> に埋める JSON（バンド名に </script> があっても閉じられないように、< > & ' " を \u 形式にする） */
function studio_json(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/** ログイン中のユーザーの member_id（部員と紐付いていなければ null） */
function studio_my_member_id(?array $user): ?int
{
    if ($user === null) {
        return null;
    }
    // セッションの写しは古いことがあるので DB から読む
    $st = db()->prepare('SELECT member_id FROM user_account WHERE user_id = ?');
    $st->execute([(int)$user['user_id']]);
    $id = $st->fetchColumn();
    return $id === false || $id === null ? null : (int)$id;
}
