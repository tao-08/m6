<?php
/**
 * =====================================================================
 *  lib/tabs.php — 流れる TAB 譜（tab.php）の部品
 *  前半（データの検証・曲キー）は DB を使わない純粋な関数。tests/run.php から読んで確かめられる
 *  後半（編集履歴 tab_record_revision）だけ DB を使う
 * =====================================================================
 *  TAB 譜1つは song_tab の1行。小節と音符は data 列に JSON で入れる:
 *
 *    {"bars": [ {"s": "Aメロ", "t": 12345, "n": [[step, string, fret], ...]}, ... ]}
 *
 *      s … セクション名（任意。20文字まで）。歌詞は入れない（著作権）
 *      t … その小節の頭を「タップして合わせた」位置（音源の何ミリ秒目か。任意。無い小節は BPM から自動で並べる）
 *      n … 音符。step = 小節の中の何マス目か（0 始まり）、string = 何弦か（0 = 1弦 = 一番高い弦 = 画面の一番上）、
 *          fret = フレット（-1 = ミュート x、0 = 開放弦）
 *
 *  ブラウザから届いた JSON は信用しない。保存の前に必ず tab_sanitize_data() を通して、
 *  形・範囲を確かめた「きれいな配列」を作り直す（受け取った JSON をそのまま保存しない）。
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/tracks.php'; // song_title_key（albums.php の album_key / album_parse_key も読み込まれる）

/**
 * 新規作成で選べる楽器と弦の数。キーは画面のラジオ（楽器のタイル）の value。
 *   instrument  … instrument テーブルの instrument_id（2 = Gt、3 = Ba）
 *   tuning      … 低い弦 → 高い弦の順（6弦ギターなら "E A D G B E"）
 *   part_base   … パート名の候補の元（Gt → Gt1, Gt2…）。tab_suggest_part() に渡す
 *   part_number … true = 最初から番号を付ける（Gt1）/ false = 最初は番号なし（Ba → 2本目から Ba2）
 */
const TAB_PRESETS = [
    'gt6' => ['label' => 'ギター6弦', 'instrument' => 2, 'tuning' => 'E A D G B E', 'part_base' => 'Gt', 'part_number' => true],
    'gt7' => ['label' => 'ギター7弦', 'instrument' => 2, 'tuning' => 'B E A D G B E', 'part_base' => 'Gt', 'part_number' => true],
    'ba4' => ['label' => 'ベース4弦', 'instrument' => 3, 'tuning' => 'E A D G', 'part_base' => 'Ba', 'part_number' => false],
    'ba5' => ['label' => 'ベース5弦', 'instrument' => 3, 'tuning' => 'B E A D G', 'part_base' => 'Ba', 'part_number' => false],
    // ドラム譜: tuning はレーンの略号（上から下）。fret は奏法（1 通常 / 2 アクセント / 3 ゴースト / 4 オープン = HH のみ）
    'dr'  => ['label' => 'ドラム', 'instrument' => 4, 'tuning' => 'CR RD HH SD T1 T2 FT BD', 'kind' => 'drum', 'part_base' => 'Dr', 'part_number' => false],
];

/**
 * 新規作成フォームの「パート名」の候補。同じ曲にもう付いている名前（$existing）と重ならないものを返す。
 *   $base        … 名前の元（'Gt' / 'Ba' / 'Dr'）
 *   $numberFirst … true なら Gt1, Gt2, Gt3…（番号から始める）/ false なら Ba, Ba2, Ba3…（最初は番号なし）
 *   なぜ分けるのか: ギターは「リードとリズムの2本」が当たり前なので、最初から Gt1 と書いておくと2本目の Gt2 が自然に続く。
 *                  ベース・ドラムは1本だけの曲がほとんどなので、素の Ba / Dr で十分（2本目から Ba2）。
 *   比較は大文字小文字を区別しない: DB の UNIQUE (song_key, part) の照合順序（_ci = case insensitive）が区別しないので、
 *   'gt1' が有るのに 'Gt1' を候補にすると、作成時に UNIQUE 違反（エラー 1062）になってしまうため。
 *   mb_strtolower = 日本語などの多バイト文字にも使える strtolower（PHP の標準の strtolower は ASCII 専用）
 */
function tab_suggest_part(string $base, bool $numberFirst, array $existing): string
{
    $taken = [];
    foreach ($existing as $name) {
        $taken[mb_strtolower((string)$name)] = true; // 連想配列のキーにして、isset() で一瞬で「有るか」を調べる
    }
    for ($n = 1; $n <= 99; $n++) {
        $name = ($n === 1 && !$numberFirst) ? $base : $base . $n; // 番号なしで始めるときだけ、1 本目は番号を付けない
        if (!isset($taken[mb_strtolower($name)])) {
            return $name;
        }
    }
    return $base; // 99 個も埋まっていることはまず無い。万一でも、重複は作成時に DB が断ってエラー表示になる
}

/** 1拍をいくつに分けるか（1 = 4分 / 2 = 8分 / 3 = 3連 / 4 = 16分 / 6 = 6連 / 8 = 32分） */
const TAB_STEPS = [1, 2, 3, 4, 6, 8];
const TAB_MAX_BARS = 400;            // 1曲の小節数の上限（data が巨大になるのを防ぐ）
const TAB_MAX_NOTES_PER_BAR = 256;   // 1小節の音符数の上限
const TAB_SECTION_MAX = 20;          // セクション名の文字数
const TAB_MAX_FRET = 24;
/** ドラムのレーン（上から下。tuning に空白区切りで入れる）。HH（ハイハット）は奏法 4 = オープンが使える唯一のレーン */
const TAB_DRUM_LANES = ['CR', 'RD', 'HH', 'SD', 'T1', 'T2', 'FT', 'BD'];
const TAB_DRUM_HH = 2;               // TAB_DRUM_LANES の中の HH の位置
const TAB_MAX_MS = 36000000;         // 時刻・開始位置の上限（10時間）
const TAB_MAX_CAPO = 12;
const TAB_MAX_LINKS = 10;            // 1つの TAB に付けられるリンクの数
const TAB_LINK_LABEL_MAX = 30;
const TAB_MAX_MARKERS = 100;         // 練習用マーカーの数（1人・1つの TAB あたり）
const TAB_MARKER_LABEL_MAX = 20;
const TAB_MEMO_MAX = 20000;          // メモの文字数（TAB のメモ・曲のメモ）

/**
 * チューニングの候補（弦の数 => [名前 => 低い弦から高い弦の音名]）。編集画面の select に出す。
 *   ここに無いチューニングは「カスタム」（音名を直接入力）。サーバーの検証は tab_tuning_valid + 弦の数が同じ、のまま
 */
const TAB_TUNINGS = [
    6 => ['レギュラー' => 'E A D G B E', '半音下げ' => 'Eb Ab Db Gb Bb Eb', '全音下げ' => 'D G C F A D', 'ドロップD' => 'D A D G B E',
        'ドロップC' => 'C G C F A D', 'オープンG' => 'D G D G B D', 'DADGAD' => 'D A D G A D'],
    4 => ['レギュラー' => 'E A D G', '半音下げ' => 'Eb Ab Db Gb', 'ドロップD' => 'D A D G'],
    5 => ['レギュラー' => 'B E A D G'],
    7 => ['レギュラー' => 'B E A D G B E'],
];

/**
 * TAB 譜の機能を使ってよい人か。今は管理人（is_admin）だけ。
 *   メンバーにも開くときは、ここを `return true;`（ログイン者なら誰でも）に変えるだけでよい。
 *   tab.php・api_tab_save.php・band.php のリンク・songs.php はすべてこの関数を見ている。
 *   is_admin() は lib/bootstrap.php の関数。tests/run.php は bootstrap を読まないので、この関数はテストから呼ばない
 */
function tab_allowed(): bool
{
    return is_admin();
}

/** チューニングの文字列が正しいか。空白区切りで 4〜7 本、各弦は音名（E, A, C#, Bb など） */
function tab_tuning_valid(string $tuning): bool
{
    $notes = preg_split('/ +/', $tuning);
    if ($notes === false || count($notes) < 4 || count($notes) > 7) {
        return false;
    }
    foreach ($notes as $note) {
        if (!preg_match('/^[A-G][#b]?$/', $note)) {
            return false;
        }
    }
    return true;
}

/**
 * 「曲」の同一性を表すキー（song_tab.song_key。形の説明は migrations/068_song_tab.sql）
 *   紐付けた曲  … album_key() と同じ "spotify:ID" / "itunes:ID"
 *   紐付けなし  … アーティストが分かる: "t:a<artist_id>|<曲名のキー>"
 *                 アーティストが無い  : "t:b<band_id>|<曲名のキー>"（そのバンドのオリジナル曲）
 *   曲名のキーは song_title_key()。"Lemon" と "Lemon - Acoustic ver." は同じキーになる
 */
function tab_song_key(?string $source, ?string $trackId, ?int $artistId, int $bandId, string $title, ?string $artistName): string
{
    if ($source !== null && $trackId !== null) {
        return album_key($source, $trackId);
    }
    if ($artistId !== null) {
        return 't:a' . $artistId . '|' . mb_substr(song_title_key($title, $artistName), 0, 180);
    }
    return 't:b' . $bandId . '|' . mb_substr(song_title_key($title, null), 0, 180);
}

/** song_key の形が正しいか（URL の ?key= など、ブラウザから来た値の検査用） */
function tab_key_valid(string $key): bool
{
    return album_parse_key($key) !== null || (bool)preg_match('/^t:[ab]\d+\|.{1,180}$/us', $key);
}

/**
 * チューニング（ドラムはレーン）が正しいか。$kind = 'string' は tab_tuning_valid、'drum' は TAB_DRUM_LANES の略号を重複なしで 3〜8 個
 */
function tab_tuning_valid_for(string $kind, string $tuning): bool
{
    if ($kind !== 'drum') {
        return tab_tuning_valid($tuning);
    }
    $lanes = preg_split('/ +/', $tuning);
    return $lanes !== false && count($lanes) >= 3 && count($lanes) <= count(TAB_DRUM_LANES)
        && count(array_unique($lanes)) === count($lanes) && !array_diff($lanes, TAB_DRUM_LANES);
}

/** 候補に一致するチューニングの名前（無ければ null = カスタム） */
function tab_tuning_name(string $tuning): ?string
{
    $tuning = trim(preg_replace('/ +/', ' ', $tuning) ?? '');
    foreach (TAB_TUNINGS[tab_string_count($tuning)] ?? [] as $name => $notes) {
        if ($notes === $tuning) {
            return $name;
        }
    }
    return null;
}

/** チューニングの弦の数 */
function tab_string_count(string $tuning): int
{
    return count(preg_split('/ +/', trim($tuning)) ?: []);
}

/** 新規作成時の中身（空の小節を並べただけ） */
function tab_empty_data(int $bars = 8): array
{
    return ['bars' => array_fill(0, $bars, ['n' => []])];
}

/**
 * コード名の形（コード譜。歌詞などの自由な文字を入れさせないため、決まった形だけ通す）。最大16文字
 *   N.C.（ノーコード）、または
 *   ルート [A-G][#b]?
 *   + 種類（任意）: maj7 maj9 maj11 maj13 maj M7 M9 M13 M mM7 m7b5 m7-5 m6 m7 m9 m11 m13 m dim7 dim aug7 aug 7sus4 sus2 sus4 sus add9 add11 6 7 9 11 13
 *   + 変化音（任意・複数可）: (b5) (#5) (b9) (#9) (#11) (b13) または b5 #5 b9 #9 #11 b13 -5
 *   + オンコード（任意）: /[A-G][#b]?
 *   例: C  Am  G7  Cmaj7  F#m7b5  Bb  D/F#  Asus4  A7sus4  Dadd9  C7(#9)  Gdim7  N.C.
 */
const TAB_CHORD_RE = '/^(?:N\.C\.|[A-G][#b]?(?:maj7|maj9|maj11|maj13|maj|M7|M9|M13|M|mM7|m7b5|m7-5|m6|m7|m9|m11|m13|m|dim7|dim|aug7|aug|7sus4|sus2|sus4|sus|add9|add11|6|7|9|11|13)?(?:\((?:b5|#5|b9|#9|#11|b13)\)|b5|#5|b9|#9|#11|b13|-5)*(?:\/[A-G][#b]?)?)$/D'; // D = 末尾の改行を許さない
const TAB_CHORD_MAX = 16;

function tab_chord_valid(mixed $chord): bool
{
    return is_string($chord) && strlen($chord) <= TAB_CHORD_MAX && preg_match(TAB_CHORD_RE, $chord) === 1;
}

/** 整数だけ通す（JSON の 12 は int、12.5 や "12" は null）。12.0 のような整数の float は int にする */
function tab_int(mixed $v): ?int
{
    if (is_int($v)) {
        return $v;
    }
    if (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < 1e15) {
        return (int)$v;
    }
    return null;
}

/**
 * 届いた TAB の中身（json_decode した配列）を検証して、保存用のきれいな配列にする。形がおかしければ null
 *   $strings     … 弦の数（string は 0〜$strings-1）
 *   $stepsPerBar … 1小節のマス数 = 拍数 × 1拍の分割（step は 0〜$stepsPerBar-1）
 *   同じ (step, string) が2つあれば後のものが勝つ。音符は step → string の順に並べ直す
 *   形（v2。古い形のまま保存されたものも読める）:
 *     音符 n … [step, string, fret] または [step, string, fret, dur]。dur = 音の長さ（マス数。省略 = 1）。小節の終わりまでで切る
 *     休符 r … [[step, dur], ...]（任意）。dur は音符と同じ
 *     コード c … [[step, "Am7"], ...]（任意。コード譜。名前の形は TAB_CHORD_RE。ドラム譜では捨てる）
 *   $kind = 'string'（ギター・ベース。fret は -1（ミュート x）〜24）/ 'drum'（ドラム。string = レーン、fret = 奏法 1 通常 2 アクセント 3 ゴースト 4 オープン（HH のみ））
 */
function tab_sanitize_data(mixed $data, int $strings, int $stepsPerBar, string $kind = 'string'): ?array
{
    if (!is_array($data) || !isset($data['bars']) || !is_array($data['bars']) || !array_is_list($data['bars'])) {
        return null;
    }
    $count = count($data['bars']);
    if ($count < 1 || $count > TAB_MAX_BARS) {
        return null;
    }
    $bars = [];
    foreach ($data['bars'] as $bar) {
        if (!is_array($bar)) {
            return null;
        }
        $out = [];

        // セクション名: 制御文字（改行など）を除いて 20 文字まで。空なら項目ごと省く
        if (isset($bar['s'])) {
            if (!is_string($bar['s'])) {
                return null;
            }
            $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $bar['s']);
            $s = $s === null ? '' : trim(mb_substr(trim($s), 0, TAB_SECTION_MAX));
            if ($s !== '') {
                $out['s'] = $s;
            }
        }

        // タップした小節頭の時刻（ミリ秒）。null・無しは「自動」
        if (isset($bar['t'])) {
            $t = tab_int($bar['t']);
            if ($t === null || $t < 0 || $t > TAB_MAX_MS) {
                return null;
            }
            $out['t'] = $t;
        }

        // 音符。キーを step と string から作って、同じ位置は後勝ちにする
        $notes = [];
        $rawNotes = $bar['n'] ?? [];
        if (!is_array($rawNotes) || !array_is_list($rawNotes) || count($rawNotes) > TAB_MAX_NOTES_PER_BAR) {
            return null;
        }
        foreach ($rawNotes as $note) {
            if (!is_array($note) || !array_is_list($note) || count($note) < 3 || count($note) > 4) {
                return null;
            }
            $step = tab_int($note[0]);
            $string = tab_int($note[1]);
            $fret = tab_int($note[2]);
            $dur = count($note) === 4 ? tab_int($note[3]) : 1;
            if ($step === null || $string === null || $fret === null || $dur === null
                || $step < 0 || $step >= $stepsPerBar
                || $string < 0 || $string >= $strings
                || $dur < 1) {
                return null;
            }
            if ($kind === 'drum' ? ($fret < 1 || $fret > 4 || ($fret === 4 && $string !== TAB_DRUM_HH)) : ($fret < -1 || $fret > TAB_MAX_FRET)) {
                return null;
            }
            $dur = min($dur, $stepsPerBar - $step); // 小節の終わりまでで切る
            $notes[$step * 100 + $string] = $dur > 1 ? [$step, $string, $fret, $dur] : [$step, $string, $fret]; // string は 7 本までなので 100 倍で衝突しない
        }
        ksort($notes);
        $out['n'] = array_values($notes);

        // コード [[step, "Am7"], ...]（コード譜。ドラム譜では使わない）。同じ step は後勝ち。名前は tab_chord_valid の形だけ
        $chords = [];
        $rawChords = $bar['c'] ?? [];
        if (!is_array($rawChords) || !array_is_list($rawChords) || count($rawChords) > TAB_MAX_NOTES_PER_BAR) {
            return null;
        }
        foreach ($rawChords as $chord) {
            $step = is_array($chord) && array_is_list($chord) && count($chord) === 2 ? tab_int($chord[0]) : null;
            if ($step === null || $step < 0 || $step >= $stepsPerBar || !tab_chord_valid($chord[1])) {
                return null;
            }
            $chords[$step] = [$step, $chord[1]];
        }
        if ($chords && $kind !== 'drum') {
            ksort($chords);
            $out['c'] = array_values($chords);
        }

        // 休符 [[step, dur], ...]。同じ step は後勝ち。無ければ項目ごと省く
        $rests = [];
        $rawRests = $bar['r'] ?? [];
        if (!is_array($rawRests) || !array_is_list($rawRests) || count($rawRests) > TAB_MAX_NOTES_PER_BAR) {
            return null;
        }
        foreach ($rawRests as $rest) {
            $step = is_array($rest) && array_is_list($rest) && count($rest) === 2 ? tab_int($rest[0]) : null;
            $dur = $step === null ? null : tab_int($rest[1]);
            if ($step === null || $dur === null || $step < 0 || $step >= $stepsPerBar || $dur < 1) {
                return null;
            }
            $rests[$step] = [$step, min($dur, $stepsPerBar - $step)];
        }
        if ($rests) {
            ksort($rests);
            $out['r'] = array_values($rests);
        }
        $bars[] = $out;
    }
    return ['bars' => $bars];
}

/* =====================================================================
 *  編集履歴（song_tab_revision。説明は migrations/068_song_tab.sql）
 * ===================================================================== */

const TAB_REVISION_KEEP = 200;       // 1つの TAB につき残す履歴の数
const TAB_REVISION_MERGE_MIN = 10;   // 同じ人の連続した「保存」をまとめる時間（分）
const TAB_ACTION_LABELS = ['create' => '作成', 'save' => '保存', 'delete' => '削除', 'restore' => '復元'];

/** song_tab の1行（SELECT * の結果）を、履歴に入れる JSON にする。data は文字列のまま入れずに配列にする */
function tab_snapshot_json(array $row): string
{
    $data = json_decode((string)($row['data'] ?? ''), true);
    $row['data'] = is_array($data) ? $data : null;
    if (isset($row['links'])) { // リンクも配列にして入れる（data と同じ理由）
        $links = json_decode((string)$row['links'], true);
        $row['links'] = is_array($links) ? $links : [];
    }
    return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/**
 * 履歴の snapshot（JSON 文字列）を検証して、DB に入れられる形にする。おかしければ null
 *   復元は「保存されていた JSON をそのまま信用」しない。api_tab_save.php の保存と同じ検査を通す
 */
function tab_snapshot_clean(string $json): ?array
{
    $s = json_decode($json, true);
    if (!is_array($s)) {
        return null;
    }
    $str = static fn(mixed $v, int $max): ?string => is_string($v) && $v !== '' && mb_strlen($v) <= $max && !preg_match('/[\x00-\x1F\x7F]/u', $v) ? $v : null;
    $part = $str($s['part'] ?? null, 20);
    $title = $str($s['title'] ?? null, 100);
    $tuning = is_string($s['tuning'] ?? null) ? $s['tuning'] : '';
    $kind = ($s['kind'] ?? 'string') === 'drum' ? 'drum' : 'string';
    if ($part === null || $title === null || !tab_tuning_valid_for($kind, $tuning)) {
        return null;
    }
    $artist = $s['artist_name'] ?? null;
    if ($artist !== null && ($artist = $str($artist, 255)) === null) {
        return null;
    }
    foreach (['instrument_id', 'beats_per_bar', 'steps_per_beat', 'offset_ms'] as $k) {
        if (!isset($s[$k]) || !is_numeric($s[$k])) {
            return null;
        }
    }
    $bpm = $s['bpm'] ?? null;
    $inst = (int)$s['instrument_id'];
    $beats = (int)$s['beats_per_bar'];
    $steps = (int)$s['steps_per_beat'];
    $offset = (int)$s['offset_ms'];
    if (!is_numeric($bpm) || $bpm < 20 || $bpm > 400 || $inst < 1 || $inst > 255 || $beats < 1 || $beats > 16
        || !in_array($steps, TAB_STEPS, true) || $offset < 0 || $offset > TAB_MAX_MS) {
        return null;
    }
    $yt = $s['youtube_id'] ?? null;
    if ($yt !== null && (!is_string($yt) || !preg_match('/^[A-Za-z0-9_-]{11}$/', $yt))) {
        return null;
    }
    // カポ・リンク（古い履歴に無ければ、カポ0・リンク無し）
    $memo = tab_sanitize_memo($s['memo'] ?? null);
    if ($memo === false) {
        return null;
    }
    $capo = $s['capo'] ?? 0;
    if (!is_numeric($capo) || $capo < 0 || $capo > TAB_MAX_CAPO) {
        return null;
    }
    $rawLinks = $s['links'] ?? [];
    if (is_string($rawLinks)) {
        $rawLinks = json_decode($rawLinks, true);
    }
    $links = tab_sanitize_links($rawLinks);
    if ($links === null) {
        return null;
    }
    $source = $s['track_source'] ?? null;
    $trackId = $s['track_id'] ?? null;
    if ($source !== null || $trackId !== null) {
        if (!is_string($source) || !is_string($trackId) || album_parse_key($source . ':' . $trackId) === null) {
            return null;
        }
    }
    $firstBar = $s['first_bar'] ?? 1;
    if (!is_numeric($firstBar) || $firstBar < 1 || $firstBar > 999) {
        return null;
    }
    $data = tab_sanitize_data($s['data'] ?? null, tab_string_count($tuning), $beats * $steps, $kind);
    if ($data === null) {
        return null;
    }
    return [
        'kind' => $kind, 'first_bar' => (int)$firstBar, 'is_partial' => !empty($s['is_partial']) ? 1 : 0,
        'part' => $part, 'title' => $title, 'artist_name' => $artist, 'instrument_id' => $inst, 'tuning' => $tuning,
        'bpm' => round((float)$bpm, 2), 'beats_per_bar' => $beats, 'steps_per_beat' => $steps, 'offset_ms' => $offset,
        'capo' => (int)$capo, 'links' => $links, 'memo' => $memo === '' ? null : $memo,
        'youtube_id' => $yt, 'track_source' => $source, 'track_id' => $trackId,
        'created_by' => isset($s['created_by']) && is_numeric($s['created_by']) ? (int)$s['created_by'] : null,
        'data' => $data,
    ];
}

/**
 * 履歴を1行足す。変更と同じトランザクションの中で呼ぶこと（変更だけ入って履歴が無い、を作らない）
 *   $row … 渡せばその行を記録（削除は「消す直前の行」を渡す）。省略すると song_tab の今の行を読む
 *   同じ人の10分以内の連続した保存は、新しい行を足さずに直前の履歴を上書きする（1文字ごとの保存で埋まらないように）
 *   足したあと、この TAB の履歴は新しい TAB_REVISION_KEEP 件だけ残す
 */
function tab_record_revision(PDO $pdo, int $tabId, string $action, int $userId, ?array $row = null): void
{
    if ($row === null) {
        $st = $pdo->prepare('SELECT * FROM song_tab WHERE tab_id = ?');
        $st->execute([$tabId]);
        $row = $st->fetch();
        if (!$row) {
            return;
        }
    }
    $snapshot = tab_snapshot_json($row);

    if ($action === 'save') {
        $st = $pdo->prepare('SELECT rev_id, action, user_id, created_at >= NOW() - INTERVAL ' . TAB_REVISION_MERGE_MIN . ' MINUTE AS recent
            FROM song_tab_revision WHERE tab_id = ? ORDER BY rev_id DESC LIMIT 1');
        $st->execute([$tabId]);
        $last = $st->fetch();
        if ($last && $last['action'] === 'save' && (int)$last['user_id'] === $userId && (int)$last['recent'] === 1) {
            $pdo->prepare('UPDATE song_tab_revision SET snapshot = ?, part = ?, created_at = NOW() WHERE rev_id = ?')
                ->execute([$snapshot, $row['part'], $last['rev_id']]);
            return;
        }
    }
    $pdo->prepare('INSERT INTO song_tab_revision (tab_id, song_key, part, action, snapshot, user_id) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$tabId, $row['song_key'], $row['part'], $action, $snapshot, $userId]);

    // 古い履歴を消す。OFFSET 番目（新しい順で KEEP+1 件目）が無ければ副問い合わせは NULL になり、何も消えない
    $pdo->prepare('DELETE FROM song_tab_revision WHERE tab_id = ? AND rev_id < (
            SELECT rev_id FROM (SELECT rev_id FROM song_tab_revision WHERE tab_id = ? ORDER BY rev_id DESC LIMIT 1 OFFSET ' . (TAB_REVISION_KEEP - 1) . ') AS keep_from)')
        ->execute([$tabId, $tabId]);
}

/* =====================================================================
 *  リンク（参考にする TAB サイトなど）と練習用マーカーの検証
 * ===================================================================== */

/**
 * メモ（TAB のメモ・曲のメモ）を検証してきれいにする。文字列でない・長すぎるなら false、それ以外はきれいにした文字列（空なら ''）
 *   改行は LF にそろえ、LF と タブ 以外の制御文字は取り除く。表示のときは linkify_body() / JS の linkify で URL をリンクにする（どちらも先にエスケープ）
 */
function tab_sanitize_memo(mixed $v): string|false
{
    if ($v === null) {
        return '';
    }
    if (!is_string($v)) {
        return false;
    }
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $v); // \n（\x0A）と \t（\x09）以外の制御文字を取り除く
    if ($v === null || mb_strlen($v) > TAB_MEMO_MAX) {
        return false;
    }
    return rtrim($v);
}

/**
 * リンクの配列を検証してきれいにする。おかしければ null
 *   [{"u": URL, "l": 表示名（任意・30文字まで）}] を最大10個。
 *   URL は https のみ・500文字まで・空白や制御文字なし・ホスト名あり（ホストは何でもよい。リンクを張るだけで、中身はコピーしない）
 */
function tab_sanitize_links(mixed $links): ?array
{
    if (!is_array($links) || !array_is_list($links) || count($links) > TAB_MAX_LINKS) {
        return null;
    }
    $out = [];
    foreach ($links as $link) {
        if (!is_array($link) || !is_string($link['u'] ?? null)) {
            return null;
        }
        $u = $link['u'];
        if ($u === '' || strlen($u) > 500 || preg_match('/[\s\x00-\x1F\x7F]/u', $u)) {
            return null;
        }
        $parts = parse_url($u);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return null;
        }
        $item = ['u' => $u];
        if (isset($link['l'])) {
            if (!is_string($link['l']) || preg_match('/[\x00-\x1F\x7F]/u', $link['l'])) {
                return null;
            }
            $label = trim(mb_substr(trim($link['l']), 0, TAB_LINK_LABEL_MAX));
            if ($label !== '') {
                $item['l'] = $label;
            }
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * 練習用マーカー（song_tab_practice.data）を検証してきれいにする。おかしければ null
 *   {"m": [{"t": ミリ秒, "e": ミリ秒 or null, "l": ラベル（20文字まで）}], "ab": [A, B] or null, "bpm": 練習BPM or null, "metro": メトロノームの設定 or null}
 *   マーカーは最大100個、開始時刻の順に並べ直す。e（終点）は t より後。ab は A < B
 */
function tab_sanitize_practice(mixed $data): ?array
{
    if (!is_array($data)) {
        return null;
    }
    $markers = $data['m'] ?? [];
    if (!is_array($markers) || !array_is_list($markers) || count($markers) > TAB_MAX_MARKERS) {
        return null;
    }
    $m = [];
    foreach ($markers as $mk) {
        $t = is_array($mk) ? tab_int($mk['t'] ?? null) : null;
        if ($t === null || $t < 0 || $t > TAB_MAX_MS) {
            return null;
        }
        $e = null;
        if (isset($mk['e'])) {
            $e = tab_int($mk['e']);
            if ($e === null || $e <= $t || $e > TAB_MAX_MS) {
                return null;
            }
        }
        $label = $mk['l'] ?? '';
        if (!is_string($label) || preg_match('/[\x00-\x1F\x7F]/u', $label)) {
            return null;
        }
        $m[] = ['t' => $t, 'e' => $e, 'l' => trim(mb_substr(trim($label), 0, TAB_MARKER_LABEL_MAX))];
    }
    usort($m, static fn($x, $y) => $x['t'] <=> $y['t']);

    $ab = $data['ab'] ?? null;
    if ($ab !== null) {
        $a = is_array($ab) && array_is_list($ab) && count($ab) === 2 ? tab_int($ab[0]) : null;
        $b = $a === null ? null : tab_int($ab[1]);
        if ($a === null || $b === null || $a < 0 || $b > TAB_MAX_MS || $a >= $b) {
            return null;
        }
        $ab = [$a, $b];
    }

    $bpm = $data['bpm'] ?? null;
    if ($bpm !== null) {
        if ((!is_int($bpm) && !is_float($bpm)) || !is_finite((float)$bpm) || $bpm < 20 || $bpm > 400) {
            return null;
        }
        $bpm = round((float)$bpm, 2);
    }
    // メトロノームの設定（無ければ null = 初期値）。on = オン・オフ、vol = 音量 0〜1、sub = 1拍を何個に割るか（1 = 細分なし / 2 = 8分 / 3 = 3連 / 4 = 16分）、count = カウントインの小節数
    $metro = $data['metro'] ?? null;
    if ($metro !== null) {
        if (!is_array($metro)) {
            return null;
        }
        $vol = $metro['vol'] ?? 0.6;
        $sub = tab_int($metro['sub'] ?? 1);
        $count = tab_int($metro['count'] ?? 0);
        if (!is_bool($metro['on'] ?? false) || (!is_int($vol) && !is_float($vol)) || $vol < 0 || $vol > 1
            || !in_array($sub, [1, 2, 3, 4], true) || !in_array($count, [0, 1, 2], true)) {
            return null;
        }
        $metro = ['on' => (bool)($metro['on'] ?? false), 'vol' => round((float)$vol, 2), 'sub' => $sub, 'count' => $count];
    }
    return ['m' => $m, 'ab' => $ab, 'bpm' => $bpm, 'metro' => $metro];
}
