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
 *   なぜ数値？ → 文字列より小さく、並び替え（全日→平日→土日祝→土→日祝）もそのまま数値順でできるから
 *   2 = 土日祝（土曜も日曜・祝日も同じ料金）/ 3 = 土 / 4 = 日祝（日曜と祝日）… 土曜と日祝で料金が違うスタジオ用に 3・4 を足した
 *   値を足しただけなので、DB の列（TINYINT）は変えていない。今まで入っている 0・1・2 のデータはそのまま使える
 *   「土日祝」の行は、土を選んでも日祝を選んでも当てはまる（JS: dayOk）。土・日祝の行のほうが優先される
 */
const STUDIO_DAY_TYPES = [0 => '全日', 1 => '平日', 2 => '土日祝', 3 => '土', 4 => '日祝'];

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
    return $people === null ? 'バンド練習' :'個人' . $people . '人';
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

/**
 * DB に入れてよい時刻（0時からの分）の上限 = 48:00。画面には出さない「内部の持ち方」の話
 *   日をまたぐ時間帯は「終了 = 翌日の時刻 + 24時間」で持つ（例: 23:00〜10:00 → 開始 1380 / 終了 2040）。
 *   画面の入力・表示は 0:00〜23:30 の「時計の時刻」だけ（24時以降の表記はしない）。変換は studio_span() と studio_fmt_clock()
 *   古いデータの「25:00」「29:00」のような値も、そのまま読める（表示は 1:00・5:00 になる）
 */
const STUDIO_MAX_MIN = 48 * 60;

/**
 * 0時からの分 → "H:MM"。「長さ」の表示用（2時間30分 → "2:30"）。時刻には studio_fmt_clock を使う
 *   intdiv() = 割り算の商（小数を切り捨てた整数）、% = 余り
 *   sprintf('%02d') = 2桁に満たなければ 0 で埋める（5 → "05"）
 */
function studio_fmt_min(int $min): string
{
    return sprintf('%d:%02d', intdiv($min, 60), $min % 60);
}

/**
 * 0時からの分 → 時計の時刻 "H:MM"（24時間で一周。25:00 → "1:00" / 24:00 → "0:00"）
 *   料金表・パックの時間帯の表示に使う。% 1440 = 1日（24時間 = 1440分）で割った余り
 */
function studio_fmt_clock(int $min): string
{
    return studio_fmt_min($min % 1440);
}

/**
 * 時刻の <select> の選択肢（30分きざみ、0:00〜23:30）を HTML で返す
 *   $selected と同じ時刻の option に selected を付ける。25:00 のような古い値は 1:00 に直して選ぶ（% 1440）
 */
function studio_time_options(?int $selected): string
{
    $sel = $selected === null ? null : $selected % 1440;
    $html = '';
    for ($m = 0; $m < 1440; $m += 30) {
        $html .= '<option value="' . $m . '"' . ($m === $sel ? ' selected' : '') . '>' . studio_fmt_min($m) . '</option>';
    }
    return $html;
}

/**
 * 時間帯の入力（開始・終了）を、DB に入れる分（0時からの分）に直す。使えない入力は null
 *   入力は 0:00〜23:30 の時計の時刻（<select> の値の文字列）
 *   ・開始 < 終了 → そのまま（例: 18:00〜23:00）。古い画面から 25:00 のような値が来ても、そのまま通す
 *   ・終了 ≦ 開始 → 終了は翌日。24時間（1440分）足す（例: 23:00〜10:00 → 1380〜2040 / 18:00〜0:00 → 1080〜1440）
 *   ・0:00〜0:00 は「終日」（0〜1440）。0:00 以外で 開始 = 終了 は、24時間ぴったりの時間帯になってしまうので不正
 *   ctype_digit = 数字だけの文字列か（"-5" や "1e3" や空文字は false）
 *   戻り値: [開始, 終了] / null
 */
function studio_span(string $start, string $end): ?array
{
    if (!ctype_digit($start) || !ctype_digit($end) || (int)$start > STUDIO_MAX_MIN || (int)$end > STUDIO_MAX_MIN) {
        return null;
    }
    $s = (int)$start;
    $e = (int)$end;
    if ($s % 30 !== 0 || $e % 30 !== 0) {
        return null; // 30分きざみ
    }
    if ($s < $e) {
        return [$s, $e];
    }
    if ($s === 0 && $e === 0) {
        return [0, 1440]; // 終日
    }
    return ($s === $e || $s >= 1440) ? null : [$s, $e + 1440];
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

/** 割引の種類（migrations/090 の kind）。画面の選択肢の表示名 */
const STUDIO_DISC_KINDS = [0 => '金額', 1 => '円引', 2 => '％引'];

/**
 * 割引を適用した料金。$kind 0 = 金額指定（$value がそのまま料金）/ 1 = ◯円引き / 2 = ◯%引き（%引きの端数は円未満を四捨五入）
 *   引きすぎてマイナスにならないよう 0 で止める
 */
function studio_disc_apply(int $base, int $kind, int $value): int
{
    return match ($kind) {
        1 => max(0, $base - $value),
        2 => max(0, (int)round($base * (100 - min(100, $value)) / 100)),
        default => $value,
    };
}

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

/**
 * 料金が全部同じ部屋のグループを1つにまとめる（料金表の見出しが「Aスタ・Bスタ」になる）
 *   $groups = ['rooms' => [部屋id…], 'rows' => [料金の行…]] の配列（rooms が空 = 全部屋共通）
 *   保存すると料金は「1行 = 1つの時間帯 × 1つの部屋」になるので、同じ料金でも部屋ごとに別グループになる。それを表示のときに合体させる
 *   ・比べるのは 人数・曜日・開始・終了・料金・割引の料金。部屋や行の id は比べない（id は違って当然なので）
 *   ・全部屋共通（rooms が空）のグループと、料金が1つも無い「見出しだけ」のグループ（rows が空）は、そのまま残す
 *   ・並びは最初に出てきた順（呼び出し側で部屋の画面順にそろえてある）。合体したグループの位置は、いちばん先頭の部屋のところ
 */
function studio_merge_same_rate_groups(array $groups): array
{
    $out = [];
    $pos = []; // 「料金の目印 => $out の何番目か」
    foreach ($groups as $g) {
        if (!$g['rooms'] || !$g['rows']) {
            $out[] = $g;
            continue;
        }
        // 目印 = 行ごとの文字列を並べ替えてつなげたもの（行の並びが違っても同じ料金なら同じ目印になるように sort する）
        $keys = [];
        foreach ($g['rows'] as $r) {
            $disc = $r['disc'];
            ksort($disc); // 割引区分の順番が違っても同じと見なす
            $keys[] = ($r['people'] ?? 0) . '|' . (int)$r['day_type'] . '|' . (int)$r['start_min'] . '|' . (int)$r['end_min'] . '|' . (int)$r['price'] . '|' . json_encode($disc);
        }
        sort($keys);
        $sig = implode(';', $keys);
        if (isset($pos[$sig])) {
            // 同じ料金のグループがもうある → 部屋だけ足す（料金の行は同じなので、先に入れた方のまま）
            $out[$pos[$sig]]['rooms'] = array_values(array_unique(array_merge($out[$pos[$sig]]['rooms'], $g['rooms'])));
        } else {
            $pos[$sig] = count($out);
            $out[] = $g;
        }
    }
    return $out;
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
