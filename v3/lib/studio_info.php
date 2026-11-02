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

/** 円の表示（1234 → "¥1,234"）。number_format = 3桁ごとにカンマ */
function studio_yen(int $yen): string
{
    return '¥' . number_format($yen);
}
