<?php
/**
 * =====================================================================
 *  lib/studio_info.php — スタジオ情報（studio_list.php / studio_info.php）の共通部品
 * =====================================================================
 *  ・定数（曜日区分・機材の区分）
 *  ・分 ⇔ "HH:MM" の変換
 *  ・入力値のチェック（URL・座標）
 *  ※ スタジオ割（lib/studio.php）とは別物なので、ファイル名を分けている
 * =====================================================================
 */
declare(strict_types=1);

/**
 * 料金の曜日区分（studio_rate.day_type の値 => 表示名）
 *   なぜ数値？ → 文字列より小さく、並び替え（全日→平日→土日祝）もそのまま数値順でできるから
 */
const STUDIO_DAY_TYPES = [0 => '全日', 1 => '平日', 2 => '土日祝'];

/** 入力フォームだけの曜日「平日・土日祝」。新しい行の保存時に 平日 + 土日祝 の2行に展開する（DB には保存しない） */
const STUDIO_DAY_BOTH = 3;

/** 機材の区分（この順に表示。会場の機材 venue.php と同じ並びにそろえる） */
const STUDIO_EQUIP_CATEGORIES = ['ギターアンプ', 'ベースアンプ', 'ドラム', 'キーボード', 'PA', 'その他'];

/** 個人練習の人数の上限（料金表・パックの「対象」の選択肢を 個人1人〜個人6人 にする） */
const STUDIO_SOLO_MAX = 6;

/**
 * 料金表・パックの「対象」の表示名。people = NULL → バンド / n → 個人n人
 *   ?int = int か null（nullable 型）
 */
function studio_target_label(?int $people): string
{
    return $people === null ? 'バンド' : '個人' . $people . '人';
}

/** 「対象」の <select> の選択肢（0 = バンド、1〜STUDIO_SOLO_MAX = 個人n人） */
function studio_target_options(?int $people): string
{
    $html = '';
    for ($n = 0; $n <= STUDIO_SOLO_MAX; $n++) {
        $html .= '<option value="' . $n . '"' . ($n === (int)$people ? ' selected' : '') . '>' . h(studio_target_label($n === 0 ? null : $n)) . '</option>';
    }
    return $html;
}

/** 料金表の時刻の上限（30:00 = 翌朝6時。深夜パックを1行で書けるように 24時を超えて持つ） */
const STUDIO_MAX_MIN = 30 * 60;

/**
 * 0時からの分 → "HH:MM"（24時を超えてもそのまま 25:00 のように出す）
 *   intdiv() = 割り算の商（小数を切り捨てた整数）、% = 余り
 *   sprintf('%02d') = 2桁に満たなければ 0 で埋める（5 → "05"）
 */
function studio_fmt_min(int $min): string
{
    return sprintf('%d:%02d', intdiv($min, 60), $min % 60);
}

/**
 * 時刻の <select> の選択肢（30分きざみ、0:00〜30:00）を HTML で返す
 *   $selected と同じ値の option に selected を付ける
 */
function studio_time_options(?int $selected): string
{
    $html = '';
    for ($m = 0; $m <= STUDIO_MAX_MIN; $m += 30) {
        $html .= '<option value="' . $m . '"' . ($m === $selected ? ' selected' : '') . '>' . studio_fmt_min($m) . '</option>';
    }
    return $html;
}

/**
 * URL として保存してよいか（空は OK = 未登録）
 *   href に入れるので http(s) だけ許す（"javascript:alert(1)" のような URL で XSS させないため）
 */
function studio_url_ok(string $url): bool
{
    return $url === '' || (mb_strlen($url) <= 255 && preg_match('#^https?://[^\s<>"]+$#i', $url) === 1);
}

/**
 * "35.6812, 139.7671" のような文字列を [緯度, 経度] にする。空なら [null, null]、読めなければ false
 *   Google マップで地点を右クリック（スマホは長押し）すると、この形でコピーできる
 *   preg_match の () で囲んだ部分が $m[1], $m[2] に入る（キャプチャ）
 *   日本の範囲だけを許す（緯度 20〜46、経度 122〜154）→ 緯度と経度を逆に入れたミスに気づける
 */
function studio_parse_coords(string $raw): array|false
{
    $raw = trim(str_replace(['，', '　'], [',', ' '], $raw)); // 全角のカンマ・空白も受け付ける
    if ($raw === '') {
        return [null, null];
    }
    if (!preg_match('/^(-?\d{1,3}(?:\.\d+)?)\s*[, ]\s*(-?\d{1,3}(?:\.\d+)?)$/', $raw, $m)) {
        return false;
    }
    $lat = round((float)$m[1], 6); // DECIMAL(9,6) に合わせて小数6桁で丸める
    $lng = round((float)$m[2], 6);
    if ($lat < 20 || $lat > 46 || $lng < 122 || $lng > 154) {
        return false;
    }
    return [$lat, $lng];
}

/**
 * 料金・パック・機材 ←→ 部屋 の中間テーブル（migrations/088）。テーブル名・列名は固定の定数だけを SQL に入れる（利用者の入力は入れない）
 *   [親テーブル => [中間テーブル, 親の主キー列]]
 */
const STUDIO_ROOM_LINKS = [
    'studio_rate'      => ['studio_rate_room', 'studio_rate_id'],
    'studio_pack'      => ['studio_pack_room', 'studio_pack_id'],
    'studio_equipment' => ['studio_equipment_room', 'studio_equipment_id'],
];

/**
 * 料金・パック ←→ 割引区分 の中間テーブル（migrations/089）。中間テーブルの price = その区分の料金
 *   [親テーブル => [中間テーブル, 親の主キー列]]。SQL に入れるのは、この固定の文字だけ
 */
const STUDIO_DISCOUNT_LINKS = [
    'studio_rate' => ['studio_rate_discount', 'studio_rate_id'],
    'studio_pack' => ['studio_pack_discount', 'studio_pack_id'],
];

/** 割引区分の数の上限（画面の列が増えすぎないように） */
const STUDIO_DISCOUNT_MAX = 5;

/** 割引区分の「使える曜日」の表示（0 = 全日 → 空文字。何も書かない = いつでも使える） */
function studio_discount_day_label(int $day): string
{
    return $day === 0 ? '' : (STUDIO_DAY_TYPES[$day] ?? '') . 'のみ';
}

/** このスタジオの割引区分（並び順どおり）。[['studio_discount_id' => 1, 'name' => '学割', 'day_type' => 0], …] */
function studio_discounts(PDO $pdo, int $studioId): array
{
    $st = $pdo->prepare('SELECT studio_discount_id, name, day_type FROM studio_discount WHERE studio_id = ? ORDER BY sort_order, studio_discount_id');
    $st->execute([$studioId]);
    return array_map(fn($d) => ['studio_discount_id' => (int)$d['studio_discount_id'], 'name' => $d['name'], 'day_type' => (int)$d['day_type']], $st->fetchAll());
}

/**
 * 料金表の表示用: 「対象」と「料金（通常＋割引区分ごとの料金すべて）」が同じ行を1行にまとめる
 *   例: 平日 18:00〜23:00 ¥3,000 と 土日祝 10:00〜23:00 ¥3,000 → 1行（時間帯が2つ）
 *   戻り値: [['row' => 代表の行（料金の表示に使う）, 'slots' => [まとめた行, …]], …]
 *           並びは「最初に出てきた位置」のまま（元の並び = 対象 → 曜日 → 開始時刻）
 *   ※ 表示だけの処理。DB の行は別々のまま（編集・シミュレーションには影響しない）
 */
function studio_merge_same_price(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $disc = $r['disc'];
        ksort($disc); // 区分の順番が違っても同じ料金と見なすため、区分 id 順にそろえる（ksort = キーで並べ替え）
        // 比べるための「目印」の文字列。json_encode で配列も1つの文字列にできる
        $key = ($r['people'] ?? 0) . '|' . (int)$r['price'] . '|' . json_encode($disc);
        $out[$key]['row'] ??= $r; // ??= = まだ無いときだけ入れる（最初の行を代表にする）
        $out[$key]['slots'][] = $r;
    }
    return array_values($out); // キー（目印）はもう要らないので、0, 1, 2 … の配列に戻す
}

/** 円の表示（1234 → "¥1,234"）。number_format = 3桁ごとにカンマ */
function studio_yen(int $yen): string
{
    return '¥' . number_format($yen);
}

/**
 * 広さ（帖）の「数字だけ」。22.0 → "22" / 12.5 → "12.5" / null・0以下 → ""（広さ不明）。入力欄の初期値にも使う
 *   DB の DECIMAL は PHP では文字列 "22.0" で返ってくるので、float にしてから整える。
 *   rtrim($s, '0') → '.' の順で、小数の末尾の 0 と余った小数点だけを落とす（"22.0" → "22." → "22"）
 */
function studio_jo_num(string|float|int|null $jo): string
{
    if ($jo === null || (float)$jo <= 0) {
        return '';
    }
    return rtrim(rtrim(number_format((float)$jo, 1, '.', ''), '0'), '.');
}

/** 広さ（帖）の表示。22.0 → "22帖"。広さ不明は "" */
function studio_fmt_jo(string|float|int|null $jo): string
{
    $n = studio_jo_num($jo);
    return $n === '' ? '' : $n . '帖';
}

/**
 * フォームの「広さ（帖）」を検証して DB に入れる値にする
 *   戻り値: null = 空欄（広さ不明）/ float = 0.5〜999.9 を 0.1 刻みに丸めた値 / false = 不正
 *   mb_convert_kana($s, 'n') = 全角の数字（１２）を半角（12）にする。スマホの日本語入力で全角になりやすいので
 *   is_numeric = 数字として読めるか（"12abc" や "1e3" のような変な形も、範囲チェックで弾く）
 */
function studio_parse_jo(string $raw): float|null|false
{
    $raw = trim(mb_convert_kana($raw, 'n'));
    if ($raw === '') {
        return null;
    }
    if (!preg_match('/^\d{1,3}(\.\d)?$/', $raw)) {
        return false; // 整数3桁まで・小数は1桁まで（DECIMAL(5,1) に収まる形だけ）
    }
    $v = (float)$raw;
    return $v >= 0.5 ? $v : false;
}
