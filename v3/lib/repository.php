<?php
/**
 * =====================================================================
 *  repository.php — 「探して、無ければ作る」系の DB 操作をまとめたファイル（v3）
 * =====================================================================
 *  取り込み・バンド編集・ライブ編集で同じ処理が必要になるので、ここに1つだけ書いて使い回す。
 *
 *  v2（local_abbeydb）との違い:
 *    - band_member が1テーブルなので、出演の登録・削除が1か所で済む
 *    - 外部キーに ON DELETE CASCADE があるので、ライブを消すと日程・バンド・出演記録が DB 側で一緒に消える
 *      （PHP で「孫 → 子 → 親」の順に消す必要がない）
 *    - 複合主キーがあるので、重複は INSERT IGNORE で DB に弾かせる
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/import/text.php';

/** 会場名 → venue_id。無ければ作る。空なら NULL（venue_id は NULL 可） */
function find_or_create_venue(PDO $pdo, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    // venue.name は UNIQUE なので、INSERT IGNORE で「無ければ作る」を1文で書ける
    $pdo->prepare('INSERT IGNORE INTO venue (name) VALUES (?)')->execute([mb_substr($name, 0, 50)]);
    $st = $pdo->prepare('SELECT venue_id FROM venue WHERE name = ?');
    $st->execute([mb_substr($name, 0, 50)]);
    return (int)$st->fetchColumn();
}

/** (年度, ライブ名) → live_id。無ければ作る。 */
function find_or_create_live(PDO $pdo, int $year, string $name): int
{
    $pdo->prepare('INSERT IGNORE INTO live (fiscal_year, name) VALUES (?, ?)')->execute([$year, $name]);
    $st = $pdo->prepare('SELECT live_id FROM live WHERE fiscal_year = ? AND name = ?');
    $st->execute([$year, $name]);
    return (int)$st->fetchColumn();
}

/**
 * バンド名 → artist_id。「ヨルシカ（安田）」なら括弧を外した「ヨルシカ」で探す。
 *
 * 表記ゆれ（KingGnu / King Gnu、全角/半角）は band_key() でそろえて既存アーティストを探し、
 * 見つからなければ新しく作る。
 */
function find_or_create_artist(PDO $pdo, string $bandName): ?int
{
    [$base] = band_split_suffix($bandName);
    $base = mb_substr(trim($base), 0, 100);
    if ($base === '') {
        return null;
    }
    static $index = null; // band_key → artist_id（1リクエスト中は使い回す）
    if ($index === null) {
        $index = [];
        foreach ($pdo->query('SELECT artist_id, name FROM artist') as $r) {
            $index[band_key($r['name'])] = (int)$r['artist_id'];
        }
    }
    $key = band_key($base);
    if (isset($index[$key])) {
        return $index[$key];
    }
    $pdo->prepare('INSERT IGNORE INTO artist (name) VALUES (?)')->execute([$base]);
    $st = $pdo->prepare('SELECT artist_id FROM artist WHERE name = ?');
    $st->execute([$base]);
    return $index[$key] = (int)$st->fetchColumn();
}

/**
 * 既存メンバーを「比較用キー → [id, name]」の配列にして返す。
 * member_key() で空白除去・全角半角統一・異体字（﨑→崎）をそろえてから比べるため。
 */
function load_member_index(PDO $pdo): array
{
    $index = [];
    foreach ($pdo->query('SELECT member_id, name FROM member') as $row) {
        $index[member_key($row['name'])] = ['id' => (int)$row['member_id'], 'name' => $row['name']];
    }
    return $index;
}

/**
 * 名前 → member_id。無ければ作る。
 * @param array $index load_member_index() の結果。新しく作った人もここに足していく（参照渡し &）
 */
function find_or_create_member(PDO $pdo, array &$index, string $name): int
{
    $key = member_key($name);
    if (isset($index[$key])) {
        return $index[$key]['id'];
    }
    $display = mb_substr(member_display($name), 0, 50);
    // name_kana は NULL 可なので省略できる（v2 の local_abbeydb では '' を入れる必要があった）
    $pdo->prepare('INSERT IGNORE INTO member (name) VALUES (?)')->execute([$display]);
    $st = $pdo->prepare('SELECT member_id FROM member WHERE name = ?');
    $st->execute([$display]);
    $id = (int)$st->fetchColumn();
    $index[$key] = ['id' => $id, 'name' => $display];
    return $id;
}

/**
 * バンドにメンバーを登録する。
 * @param array $assignments [[名前, instrument_id], ...]
 *
 * band_member の主キーが (band_id, member_id, instrument_id) なので、
 * 同じ組み合わせが2回来ても INSERT IGNORE で DB が無視してくれる（PHP で重複管理しなくていい）。
 */
function attach_members(PDO $pdo, array &$index, int $bandId, array $assignments): void
{
    $ins = $pdo->prepare('INSERT IGNORE INTO band_member (band_id, member_id, instrument_id) VALUES (?, ?, ?)');
    foreach ($assignments as [$name, $instrumentId]) {
        $ins->execute([$bandId, find_or_create_member($pdo, $index, $name), $instrumentId ?? OTHER_INSTRUMENT_ID]);
    }
}

/** 楽器が分からないときに使う「その他」 */
const OTHER_INSTRUMENT_ID = 10;

/**
 * 最初から入っている楽器（schema.sql の INSERT）の略称。
 * プログラムが略称で探している楽器（Vo / Gt / Key / etc など）もあるので、楽器の管理画面でも消せないようにする。
 * 消せるのは、名簿の取り込みで「etc」から追加された楽器だけ。
 */
const BUILTIN_INSTRUMENTS = ['Vo', 'Gt', 'Ba', 'Dr', 'Key', 'Cho', 'Perc', 'Vn', 'Sax', 'etc'];

/**
 * 楽器を追加する（名簿の取り込みで「etc」を選んだときのモーダルから）。
 * 同じ略称がもうあれば、新しく作らずにそれを返す（instrument.short_name は UNIQUE）。
 * 表示順は「その他（99）」の手前に並べる。
 * @return array ['instrument_id' => ..., 'short_name' => ..., 'name' => ..., 'existed' => bool]
 */
function create_instrument(PDO $pdo, string $shortName, string $name): array
{
    $find = $pdo->prepare('SELECT instrument_id, short_name, name FROM instrument WHERE short_name = ?');
    $find->execute([$shortName]);
    if ($row = $find->fetch()) {
        return ['instrument_id' => (int)$row['instrument_id'], 'short_name' => $row['short_name'], 'name' => $row['name'], 'existed' => true];
    }
    $order = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 9) FROM instrument WHERE sort_order < 99')->fetchColumn() + 1;
    try {
        $pdo->prepare('INSERT INTO instrument (name, short_name, sort_order) VALUES (?, ?, ?)')
            ->execute([$name, $shortName, min($order, 98)]);
    } catch (PDOException $e) {
        // 23000 = UNIQUE 違反。同じ瞬間に別の人が同じ略称を追加したときだけ起きる → その楽器を返す
        if ($e->getCode() !== '23000') {
            throw $e;
        }
        $find->execute([$shortName]);
        $row = $find->fetch();
        return ['instrument_id' => (int)$row['instrument_id'], 'short_name' => $row['short_name'], 'name' => $row['name'], 'existed' => true];
    }
    return ['instrument_id' => (int)$pdo->lastInsertId(), 'short_name' => $shortName, 'name' => $name, 'existed' => false];
}

/** 略称（'Vo' 'Gt'…）→ instrument_id。大文字小文字は区別しない。無ければ null */
function instrument_id_by_short(string $short): ?int
{
    foreach (instruments() as $ins) {
        if (strtolower($ins['short_name']) === strtolower($short)) {
            return (int)$ins['instrument_id'];
        }
    }
    return null;
}

/**
 * 「ボーカルの形」。名簿取り込み（import.php）の Vo. 欄と、バンド編集（band_edit.php）の楽器欄で使う。
 *   also = ボーカルと一緒に登録する楽器（instrument.short_name）。単体ボーカルは null。
 *   ギターボーカルは band_member に「Vo」と「Gt」の2行を入れる（schema.sql の方針どおり。楽器マスタに組み合わせは作らない）
 */
const VOCAL_ROLES = [
    'vo'  => ['label' => 'Vo',     'title' => 'ボーカルのみ',       'also' => null],
    'gt'  => ['label' => 'Vo/Gt',  'title' => 'ギターボーカル',     'also' => 'Gt'],
    'ba'  => ['label' => 'Vo/Ba',  'title' => 'ベースボーカル',     'also' => 'Ba'],
    'key' => ['label' => 'Vo/Key', 'title' => 'キーボードボーカル', 'also' => 'Key'],
    'dr'  => ['label' => 'Vo/Dr',  'title' => 'ドラムボーカル',     'also' => 'Dr'],
];

/** フォームから来た値をボーカルの形のキーにする。知らない値（書き換えられた値など）は単体ボーカル扱い */
function vocal_role(mixed $value): string
{
    return is_string($value) && isset(VOCAL_ROLES[$value]) ? $value : 'vo';
}

/** 楽器（「山田(Gt)」の Gt など）→ ボーカルの形。当てはまらなければ null */
function vocal_role_from_instrument(?int $instrumentId): ?string
{
    foreach (VOCAL_ROLES as $key => $role) {
        if ($role['also'] !== null && $instrumentId === instrument_id_by_short($role['also'])) {
            return $key;
        }
    }
    return null;
}

/**
 * バンド編集の楽器欄の値 → 登録する instrument_id の配列。
 *   '2'      → [2]（ふつうの楽器）
 *   'vo:gt'  → [Vo の id, Gt の id]（ギターボーカル = 2行）
 *   それ以外（書き換えられた値など）→ [その他]
 */
function instruments_for_choice(mixed $value): array
{
    if (is_string($value) && preg_match('/^vo:(\w+)$/', $value, $m) && isset(VOCAL_ROLES[$m[1]])) {
        $also = VOCAL_ROLES[$m[1]]['also'];
        return array_values(array_filter([instrument_id_by_short('Vo'), $also === null ? null : instrument_id_by_short($also)]));
    }
    $id = filter_var($value, FILTER_VALIDATE_INT);
    $valid = array_map('intval', array_column(instruments(), 'instrument_id'));
    return [is_int($id) && in_array($id, $valid, true) ? $id : OTHER_INSTRUMENT_ID];
}

/**
 * 楽器欄（<select>）の <option> を作る。バンド編集・タイムテーブル編集で共通。
 *   ボーカルのすぐ下に「Vo/Gt ギターボーカル」などを並べる（値は 'vo:gt'。保存すると Vo + Gt の2行になる）
 * @param string $selected 選んでおく値（'2' や 'vo:gt'）
 */
function instrument_choice_options(string $selected): string
{
    $html = '';
    $option = static fn(string $value, string $text) =>
        '<option value="' . h($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . h($text) . '</option>';
    foreach (instruments() as $ins) {
        $html .= $option((string)$ins['instrument_id'], $ins['short_name'] . ' ' . $ins['name']);
        if ($ins['short_name'] === 'Vo') {
            foreach (VOCAL_ROLES as $key => $role) {
                if ($role['also'] !== null) {
                    $html .= $option("vo:$key", $role['label'] . ' ' . $role['title']);
                }
            }
        }
    }
    return $html;
}

/**
 * DB の行（1人1楽器）→ バンド編集の行。
 * 同じ人が「Vo」と「Gt」を両方持っていたら、1行の「vo:gt（Vo/Gt）」にまとめる。
 * @param array $rows [['name' => ..., 'instrument_id' => ...], ...]（楽器の sort_order 順。Vo が先頭に来る前提）
 * @return array [['name' => ..., 'choice' => '2' | 'vo:gt'], ...]
 */
function merge_vocal_roles(array $rows): array
{
    $has = []; // 名前 => [instrument_id => true]
    foreach ($rows as $r) {
        $has[$r['name']][(int)$r['instrument_id']] = true;
    }
    $vo = instrument_id_by_short('Vo');
    $merged = []; // 名前 => [Vo にまとめた instrument_id => true]
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['instrument_id'];
        if (isset($merged[$r['name']][$id])) {
            continue; // Vo の行にまとめ済み
        }
        $choice = (string)$id;
        if ($id === $vo) {
            foreach (VOCAL_ROLES as $key => $role) {
                $also = $role['also'] === null ? null : instrument_id_by_short($role['also']);
                if ($also !== null && isset($has[$r['name']][$also])) {
                    $choice = "vo:$key";
                    $merged[$r['name']][$also] = true;
                    break; // 1人につきまとめるのは1つだけ（Vo + Gt + Key なら Vo/Gt と Key の2行）
                }
            }
        }
        $out[] = ['name' => $r['name'], 'choice' => $choice];
    }
    return $out;
}

/**
 * 1つのバンドのメンバーの行（1人1楽器）→ 表示用の「人 + パート」の並び。
 * 同じ人が「Vo」と「Gt」を両方持っていたら、1つの「Vo/Gt」にまとめる（ラベルは左右で Vo と Gt の色。part_badge()）。
 * バンドページ・ライブページ・アーティストページの表示で共通。
 * @param array $rows [['member_id', 'name', 'short_name', 'instrument_name', 'sort_order'], ...]（sort_order 順。1つのバンドの分だけ）
 * @return array [['member_id', 'name', 'short', 'title', 'segments' => [['short' => 'Vo', 'class' => 'vo'], ...], 'order'], ...]
 *   order = 並び順（Vo/Gt などは Vo のすぐ後ろ。バンド編集の楽器欄と同じ並び）
 * @param bool $mergeVocal false なら Vo/Gt にまとめず、1行 = 1パートのまま（統計の「Voを合算する」）
 */
function lineup_parts(array $rows, bool $mergeVocal = true): array
{
    $has = []; // member_id => [short_name => true]
    foreach ($rows as $r) {
        $has[(int)$r['member_id']][$r['short_name']] = true;
    }
    $merged = []; // member_id => [Vo にまとめた short_name => true]
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['member_id'];
        if (isset($merged[$id][$r['short_name']])) {
            continue; // Vo の方にまとめ済み
        }
        $part = ['member_id' => $id, 'name' => $r['name'], 'short' => $r['short_name'], 'title' => $r['instrument_name'],
            'segments' => [['short' => $r['short_name'], 'class' => instrument_class($r['short_name'])]], 'order' => (int)$r['sort_order'] * 10];
        if ($mergeVocal && $r['short_name'] === 'Vo') {
            $n = 0;
            foreach (VOCAL_ROLES as $role) {
                $n++;
                if ($role['also'] !== null && isset($has[$id][$role['also']])) {
                    $part = ['short' => $role['label'], 'title' => $role['title'], 'order' => $part['order'] + $n,
                        'segments' => [...$part['segments'], ['short' => $role['also'], 'class' => instrument_class($role['also'])]]] + $part;
                    $merged[$id][$role['also']] = true;
                    break; // 1人につきまとめるのは1つだけ（merge_vocal_roles と同じ）
                }
            }
        }
        $out[] = $part;
    }
    return $out;
}

/**
 * lineup_parts() の結果を、パートごとにまとめる（バンドページ・ライブページの「Vo 鈴木 / Vo/Gt 山田 / Gt 田中…」）。
 * @return array [order => ['short', 'title', 'segments', 'members' => [['member_id', 'name'], ...]], ...]（並び順どおり）
 */
function lineup_by_part(array $rows): array
{
    $lineup = [];
    foreach (lineup_parts($rows) as $p) {
        $lineup[$p['order']] ??= ['short' => $p['short'], 'title' => $p['title'], 'segments' => $p['segments'], 'members' => []];
        $lineup[$p['order']]['members'][] = ['member_id' => $p['member_id'], 'name' => $p['name']];
    }
    ksort($lineup);
    return $lineup;
}

/**
 * いくつものバンドの行 → lineup_parts() の結果を1つの配列に（各パートに band_id を付ける）。
 * 「Vo/Gt」のまとめはバンドの中だけで考える（別のバンドで Vo と Gt をやった人をまとめない）。
 * 個人ページ・メンバー一覧・統計の集計で使う。
 * @param iterable $rows [['band_id', 'member_id', 'name', 'short_name', 'instrument_name', 'sort_order'], ...]（sort_order 順）
 * @param bool $mergeVocal false なら Vo/Gt にまとめない（lineup_parts と同じ）
 */
function lineup_parts_by_band(iterable $rows, bool $mergeVocal = true): array
{
    $rowsByBand = [];
    foreach ($rows as $r) {
        $rowsByBand[(int)$r['band_id']][] = $r;
    }
    $out = [];
    foreach ($rowsByBand as $bandId => $bandRows) {
        foreach (lineup_parts($bandRows, $mergeVocal) as $p) {
            $out[] = $p + ['band_id' => $bandId];
        }
    }
    return $out;
}

/**
 * いくつものバンドの行 → バンドごとに「楽器ラベル + 名前」を1人1行（アーティストページ・検索結果）。
 *   Vo と Gt を持つ人は「Vo/Gt」の1行（lineup_parts）。それ以外の兼任（Ba + Cho など）も「Cho/Ba」のように1行にまとめる
 * @param iterable $rows [['band_id', 'member_id', 'name', 'short_name', 'instrument_name', 'sort_order'], ...]（sort_order, name 順）
 * @return array [band_id][member_id] = ['member_id', 'name', 'title', 'segments' => [['short' => 'Vo', 'class' => 'vo'], ...]]
 */
function member_lineups_by_band(iterable $rows): array
{
    $rowsByBand = [];
    foreach ($rows as $r) {
        $rowsByBand[(int)$r['band_id']][] = $r;
    }
    // 歌うパート（Vo / Cho）を先に（Vo/Gt、Cho/Ba）。usort は同じ値の順番を保つ（PHP 8）
    $sing = static fn(array $p): int => in_array($p['segments'][0]['class'], ['vo', 'cho'], true) ? 0 : 1;
    $lineups = [];
    foreach ($rowsByBand as $bandId => $bandRows) {
        $byMember = [];
        foreach (lineup_parts($bandRows) as $p) {
            $byMember[$p['member_id']][] = $p; // 並び位置は、その人の最初のパートの位置
        }
        foreach ($byMember as $memberId => $parts) {
            usort($parts, static fn($a, $b) => $sing($a) <=> $sing($b));
            $lineups[$bandId][$memberId] = [
                'member_id' => $memberId,
                'name' => $parts[0]['name'],
                'title' => implode('/', array_column($parts, 'title')),
                'segments' => array_merge(...array_column($parts, 'segments')), // 左右に色分けして並べる
            ];
        }
    }
    return $lineups;
}

/**
 * パートをラベル（'Vo' 'Vo/Gt' …）ごとに数える。Vo/Gt の人は「Vo」ではなく「Vo/Gt」の方に数える。
 * @return array [ラベル => ['short', 'title', 'segments', 'order', 'n' => 何回, 'members' => [member_id => 何回]], ...]（並び順どおり）
 */
function tally_parts(array $parts): array
{
    $tally = [];
    foreach ($parts as $p) {
        $t = &$tally[$p['short']];
        $t ??= ['short' => $p['short'], 'title' => $p['title'], 'segments' => $p['segments'], 'order' => $p['order'], 'n' => 0, 'members' => []];
        $t['n']++;
        $t['members'][$p['member_id']] = ($t['members'][$p['member_id']] ?? 0) + 1;
        unset($t);
    }
    uasort($tally, static fn($a, $b) => $a['order'] <=> $b['order']);
    return $tally;
}

/** tally_parts() の結果を、回数の多い順に（同じ回数なら楽器の並び順）。uasort は同じ値の順番を保つ（PHP 8） */
function sort_tally_by_count(array $tally): array
{
    uasort($tally, static fn($a, $b) => $b['n'] <=> $a['n']);
    return $tally;
}

/**
 * 「セットリスト登録済」のバッジ（live.php のバンドカード / member.php の出演履歴）。
 *   登録した曲数（song の行数）がタイムテーブルの曲数（band.song_count）と一致したときだけ出す。
 *   途中まで（3/5）や多すぎ（6/5）は何も出さない。
 */
function setlist_badge(int $registered, int $planned): string
{
    if ($registered <= 0 || $registered !== $planned) {
        return '';
    }
    return '<span class="setlist-badge" title="セットリスト登録済（' . $registered . '曲）">'
        . icon('queue_music') . 'セットリスト登録済</span>';
}

/**
 * 楽器ラベルの HTML。1つの楽器ならいつもの .part。
 * 兼任（Vo/Gt）は1つのラベルの中に「Vo/Gt」と書き、文字も背景も左から順にそれぞれの楽器の色にする（境目は少しグラデーション）。
 * @param array  $part   ['title' => 'ギターボーカル', 'segments' => [['short' => 'Vo', 'class' => 'vo'], ['short' => 'Gt', 'class' => 'gt']]]
 * @param string $suffix ラベルの後ろに付ける文字（個人ページの「 × 5」など）。付けると兼任ラベルの幅は中身に合わせる
 */
function part_badge(array $part, string $suffix = ''): string
{
    $segs = $part['segments'];
    if (count($segs) === 1) {
        return '<span class="part part--' . h($segs[0]['class']) . '" title="' . h($part['title']) . '">' . h($segs[0]['short'] . $suffix) . '</span>';
    }
    // 背景: 楽器ごとに幅を等分して塗り、境目の前後 10% だけ色を混ぜる
    //   class は instrument_class() が返す決まった名前（vo / gt …）だけなので、style に入れても安全
    $n = count($segs);
    $stops = [];
    foreach ($segs as $i => $s) {
        $color = 'color-mix(in srgb, var(--' . $s['class'] . ') 13%, transparent)';
        $stops[] = $color . ' ' . ($i === 0 ? 0 : round($i / $n * 100 + 10)) . '%';
        $stops[] = $color . ' ' . ($i === $n - 1 ? 100 : round(($i + 1) / $n * 100 - 10)) . '%';
    }
    $text = implode('<span class="part__slash">/</span>', array_map(
        static fn(array $s): string => '<span class="part__seg part--' . h($s['class']) . '">' . h($s['short']) . '</span>', $segs));
    if ($suffix !== '') {
        $text .= '<span class="part__suffix">' . h($suffix) . '</span>';
    }
    return '<span class="part part--split' . ($suffix !== '' ? ' part--auto' : '') . '" title="' . h($part['title'])
        . '" style="background: linear-gradient(90deg, ' . h(implode(', ', $stops)) . ')">' . $text . '</span>';
}

/**
 * 担当楽器を色付きのマーク（part_badge）で並べる。メンバー一覧と個人ページで共通。
 *   $tally: tally_parts() の結果（Vo/Gt の人は「Vo/Gt」1つ）
 *   $class: .partbar に足すクラス（表のセルの中なら 'partbar--cell'）
 *   楽器が無ければ「—」
 */
function part_marks(array $tally, bool $withCount = false, string $class = ''): string
{
    if (!$tally) {
        return '<span class="muted small">—</span>';
    }
    $html = '<div class="' . h(trim('partbar ' . $class)) . '">';
    foreach ($tally as $t) {
        $html .= part_badge($t, $withCount ? ' × ' . (int)$t['n'] : '');
    }
    return $html . '</div>';
}

function detach_members(PDO $pdo, int $bandId): void
{
    $pdo->prepare('DELETE FROM band_member WHERE band_id = ?')->execute([$bandId]);
}

/**
 * バンドのメンバーを「フォームの内容と同じ状態」にそろえる（差分だけ更新）。
 *
 * ⚠ なぜ「全部消して入れ直す」をやめたか:
 *   song_performer は band_member に ON DELETE CASCADE でぶら下がっている。
 *   全部消すと、曲ごとの演奏記録まで道連れで消えてしまう。
 *   → 「フォームから消えた (人, 楽器)」だけ DELETE、「新しく増えたもの」だけ INSERT する。
 */
function sync_band_members(PDO $pdo, array &$index, int $bandId, array $assignments): void
{
    // ほしい状態: "member_id-instrument_id" => true
    $want = [];
    foreach ($assignments as [$name, $instrumentId]) {
        $want[find_or_create_member($pdo, $index, $name) . '-' . ($instrumentId ?? OTHER_INSTRUMENT_ID)] = true;
    }
    // 今の状態
    $st = $pdo->prepare('SELECT member_id, instrument_id FROM band_member WHERE band_id = ?');
    $st->execute([$bandId]);
    $have = [];
    foreach ($st as $r) {
        $have[$r['member_id'] . '-' . $r['instrument_id']] = true;
    }

    $del = $pdo->prepare('DELETE FROM band_member WHERE band_id = ? AND member_id = ? AND instrument_id = ?');
    foreach (array_diff_key($have, $want) as $key => $_) {   // 今あるけど、ほしくないもの
        [$m, $i] = explode('-', $key);
        $del->execute([$bandId, $m, $i]);
    }
    $ins = $pdo->prepare('INSERT IGNORE INTO band_member (band_id, member_id, instrument_id) VALUES (?, ?, ?)');
    foreach (array_diff_key($want, $have) as $key => $_) {   // ほしいけど、まだ無いもの
        [$m, $i] = explode('-', $key);
        $ins->execute([$bandId, $m, $i]);
    }
}

/**
 * バンドの曲（セットリスト）と、曲ごとの演奏者を保存する。
 *
 * @param array $songs 上から順に
 *   [['song_id' => 既存ならID / 新規なら null, 'title' => '曲名',
 *     'artist_id' => その曲のアーティスト / null（= バンドと同じ）,
 *     'track' => 紐付けた曲 [source, track_id] / null（紐付けなし。曲の情報は先に track テーブルに入れておく）,
 *     'performers' => [[member_id, instrument_id], ...]], ...]
 *
 *  1. フォームから消えた曲を DELETE（演奏者は CASCADE で消える）
 *  2. track_no を 1,2,3... に振り直す（UNIQUE なので、いったん +100 に逃がしてから）
 *  3. 曲ごとに演奏者を入れ直す。曲だけ別の楽器を弾いた人は、先に band_member にその楽器を足す
 *     （song_performer の外部キーが band_member を指しているので、足さないと INSERT できない）
 */
function save_songs(PDO $pdo, int $bandId, array $songs): void
{
    $st = $pdo->prepare('SELECT song_id FROM song WHERE band_id = ?');
    $st->execute([$bandId]);
    $existing = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $keep = array_filter(array_map(static fn($s) => $s['song_id'], $songs));

    $del = $pdo->prepare('DELETE FROM song WHERE song_id = ? AND band_id = ?');
    foreach (array_diff($existing, $keep) as $id) {
        $del->execute([$id, $bandId]);
    }

    $pdo->prepare('UPDATE song SET track_no = track_no + 100 WHERE band_id = ?')->execute([$bandId]);
    $update = $pdo->prepare('UPDATE song SET track_no = ?, title = ?, artist_id = ?, track_source = ?, track_id = ?
        WHERE song_id = ? AND band_id = ?');
    $insert = $pdo->prepare('INSERT INTO song (band_id, track_no, title, artist_id, track_source, track_id) VALUES (?, ?, ?, ?, ?, ?)');
    $addRole = $pdo->prepare('INSERT IGNORE INTO band_member (band_id, member_id, instrument_id) VALUES (?, ?, ?)');
    $clear = $pdo->prepare('DELETE FROM song_performer WHERE song_id = ?');
    $addPerformer = $pdo->prepare('INSERT IGNORE INTO song_performer (song_id, band_id, member_id, instrument_id) VALUES (?, ?, ?, ?)');

    foreach (array_values($songs) as $i => $song) {
        [$trackSource, $trackId] = $song['track'] ?? [null, null];
        if ($song['song_id'] && in_array($song['song_id'], $existing, true)) {
            $update->execute([$i + 1, $song['title'], $song['artist_id'], $trackSource, $trackId, $song['song_id'], $bandId]);
            $songId = $song['song_id'];
        } else {
            $insert->execute([$bandId, $i + 1, $song['title'], $song['artist_id'], $trackSource, $trackId]);
            $songId = (int)$pdo->lastInsertId();
        }
        $clear->execute([$songId]);
        foreach ($song['performers'] as [$memberId, $instrumentId]) {
            $addRole->execute([$bandId, $memberId, $instrumentId]);
            $addPerformer->execute([$songId, $bandId, $memberId, $instrumentId]);
        }
    }
    // band.song_count（タイムテーブルの曲数）はここで上書きしない。
    // 「登録した曲数 = タイムテーブルの曲数」でセトリが揃ったかを判定しているので（setlist_badge）
}

/**
 * 「登録済みのライブと統合」のポップアップ用（partials/live_picker.php）。登録済みの日程名も一緒に取る
 *   LEFT JOIN: 日程が無いライブも出す / GROUP_CONCAT: 複数行のラベルを「1日目・2日目」の1つの文字にまとめる
 */
function lives_with_labels(PDO $pdo): array
{
    return $pdo->query("SELECT l.live_id, l.fiscal_year, l.name,
            GROUP_CONCAT(d.label ORDER BY d.label SEPARATOR '・') AS labels
        FROM live l LEFT JOIN live_day d ON d.live_id = l.live_id
        GROUP BY l.live_id, l.fiscal_year, l.name
        ORDER BY l.fiscal_year DESC, l.name")->fetchAll();
}

/**
 * 日程を削除する。band / band_member は ON DELETE CASCADE で DB が一緒に消す。
 * 日程が1つも無くなったライブも消す。
 */
function delete_live_day(PDO $pdo, int $liveDayId): void
{
    $st = $pdo->prepare('SELECT live_id FROM live_day WHERE live_day_id = ?');
    $st->execute([$liveDayId]);
    $liveId = $st->fetchColumn();
    if ($liveId === false) {
        return;
    }
    $pdo->prepare('DELETE FROM live_day WHERE live_day_id = ?')->execute([$liveDayId]);
    // NOT EXISTS: 「日程が1つも無いライブなら」消す
    $pdo->prepare('DELETE FROM live WHERE live_id = ? AND NOT EXISTS (SELECT 1 FROM live_day WHERE live_id = ?)')
        ->execute([$liveId, $liveId]);
}

/**
 * ライブの休憩・転換など（live_break）を日程ごとに取る。
 * @return array live_day_id => [休憩の行, ...]（after_order, seq 順）
 */
function load_breaks_by_day(PDO $pdo, int $liveId): array
{
    $st = $pdo->prepare('SELECT k.* FROM live_break k
        JOIN live_day d ON d.live_day_id = k.live_day_id
        WHERE d.live_id = ? ORDER BY k.after_order, k.seq, k.break_id');
    $st->execute([$liveId]);
    $byDay = [];
    foreach ($st as $k) {
        $byDay[(int)$k['live_day_id']][] = $k;
    }
    return $byDay;
}

/**
 * 休憩が登録されていない日程用: バンドとバンドの間が空いていたら、そこを休憩とみなして作る。
 * （休憩を保存する前に取り込んだライブでも、タイムテーブルに休憩が出るように）
 * @param array $bands 出演順に並んだバンド
 */
function gap_breaks(array $bands, string $name): array
{
    $breaks = [];
    $prevEnd = null;
    $prevOrder = 0;
    foreach ($bands as $b) {
        if ($prevEnd && $b['start_time'] && $b['start_time'] > $prevEnd) {
            $breaks[] = ['after_order' => $prevOrder, 'seq' => 0, 'name' => $name, 'start_time' => $prevEnd, 'end_time' => $b['start_time']];
        }
        $prevEnd = $b['end_time'] ?: $prevEnd;
        $prevOrder = (int)$b['play_order'];
    }
    return $breaks;
}

/**
 * バンド（出演順）と休憩を、タイムテーブルの上から順の1列にまとめる。
 * 休憩は「出演順が after_order 以下のバンドを出し終わった所」に入る（0 = 最初のバンドより前）。
 * @param array $bands  出演順に並んだバンド（キーはそのまま 'key' に入れて返す）
 * @param array $breaks after_order, seq 順に並んだ休憩
 * @return array [['type' => 'band' | 'break', 'key' => 元のキー, 'row' => 行], ...]
 */
function timetable_rows(array $bands, array $breaks): array
{
    $rows = [];
    $breaks = array_values($breaks);
    $i = 0;
    foreach ($bands as $key => $b) {
        while (isset($breaks[$i]) && (int)$breaks[$i]['after_order'] < (int)$b['play_order']) {
            $rows[] = ['type' => 'break', 'key' => $i, 'row' => $breaks[$i++]];
        }
        $rows[] = ['type' => 'band', 'key' => $key, 'row' => $b];
    }
    for (; isset($breaks[$i]); $i++) { // 最後のバンドより後（撤収など）
        $rows[] = ['type' => 'break', 'key' => $i, 'row' => $breaks[$i]];
    }
    return $rows;
}

/** バンドを1組削除（band_member は CASCADE で消える） */
function delete_band(PDO $pdo, int $bandId): void
{
    $pdo->prepare('DELETE FROM band WHERE band_id = ?')->execute([$bandId]);
}

/**
 * 2人のメンバーを1人にまとめる（表記ゆれで同じ人が2人登録されたとき用）。
 *
 *   1. INSERT IGNORE ... SELECT で、統合元の出演記録（バンド・曲）を統合先の名前でコピー
 *      （統合先にすでに同じ (バンド, 楽器) があれば主キーで弾かれる = 重複しない）
 *   2. 統合元の出演記録を消す
 *   3. ふりがな・入部年度は、統合先が空なら引き継ぐ
 *   4. 統合元を消す（アカウントの紐付けは、統合先が空いていれば付け替え）
 */
function merge_members(PDO $pdo, int $fromId, int $toId): void
{
    if ($fromId === $toId) {
        throw new RuntimeException('同じメンバー同士は統合できません');
    }
    $pdo->prepare('INSERT IGNORE INTO band_member (band_id, member_id, instrument_id)
        SELECT band_id, ?, instrument_id FROM band_member WHERE member_id = ?')->execute([$toId, $fromId]);
    // 曲ごとの演奏記録も同じようにコピー（band_member を先にコピーしたので外部キーを満たせる）
    $pdo->prepare('INSERT IGNORE INTO song_performer (song_id, band_id, member_id, instrument_id)
        SELECT song_id, band_id, ?, instrument_id FROM song_performer WHERE member_id = ?')->execute([$toId, $fromId]);
    // 統合元の band_member を消すと、統合元の song_performer も CASCADE で消える
    $pdo->prepare('DELETE FROM band_member WHERE member_id = ?')->execute([$fromId]);

    // COALESCE(a, b): a が NULL なら b を使う
    $pdo->prepare('UPDATE member t JOIN member f ON f.member_id = ?
        SET t.name_kana = COALESCE(t.name_kana, f.name_kana), t.entry_year = COALESCE(t.entry_year, f.entry_year),
            t.music_app = COALESCE(t.music_app, f.music_app)
        WHERE t.member_id = ?')->execute([$fromId, $toId]);

    // 統合先にアカウントが紐付いていなければ、統合元のアカウントを付け替える
    $pdo->prepare('UPDATE user_account SET member_id = ?
        WHERE member_id = ? AND NOT EXISTS (SELECT 1 FROM (SELECT member_id FROM user_account WHERE member_id = ?) t)')
        ->execute([$toId, $fromId, $toId]);
    sync_account_names($pdo, $toId); // 付け替えたアカウントの名前を統合先の名前にそろえる

    // 好きなアルバムも統合先へ引っ越す（統合先のアルバムの後ろに付け足す）
    //   ・統合先の今の最大番号を先に調べ、統合元の番号にその分を足して重ならないようにする
    //   ・INSERT IGNORE: 統合先がすでに同じアルバムを登録していたら（UNIQUE 違反）その行は飛ばす
    //   ・上限30枚はここではチェックしない（統合は管理者の操作なので、超えても消さずに残す）
    //   ・統合元の行は、下で統合元の member を消したときに ON DELETE CASCADE で自動的に消える
    $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM member_favorite_album WHERE member_id = ?');
    $st->execute([$toId]);
    $offset = (int)$st->fetchColumn();
    $pdo->prepare('INSERT IGNORE INTO member_favorite_album
            (member_id, sort_order, source, album_id, title, artist_name, artwork_url, release_year, created_at)
        SELECT ?, sort_order + ?, source, album_id, title, artist_name, artwork_url, release_year, created_at
        FROM member_favorite_album WHERE member_id = ?')->execute([$toId, $offset, $fromId]);
    // ↑ 重複で飛ばした行があると番号に隙間ができる（1,2,4…）が、表示は ORDER BY なので問題ない

    // 統合元を消す（まだアカウントが紐付いていても ON DELETE SET NULL で外れる）
    $pdo->prepare('DELETE FROM member WHERE member_id = ?')->execute([$fromId]);
}

/**
 * ある日程のバンドの出演順を 1, 2, 3... に振り直す。
 *
 * ⚠ (live_day_id, play_order) が UNIQUE なので、いきなり 1,2,3 と UPDATE すると
 *   途中で「3番が2組」になった瞬間に制約エラーになる。
 *   → いったん全部を 1000 番台に逃がしてから、正しい番号に入れ直す（2段階）。
 */
function renumber_bands(PDO $pdo, int $liveDayId, ?int $movedBandId = null, ?int $position = null): void
{
    $st = $pdo->prepare('SELECT band_id FROM band WHERE live_day_id = ? ORDER BY play_order, band_id');
    $st->execute([$liveDayId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    if ($movedBandId !== null && $position !== null) {
        $ids = array_values(array_diff($ids, [$movedBandId]));  // いったん抜いて
        $position = max(1, min($position, count($ids) + 1));
        array_splice($ids, $position - 1, 0, [$movedBandId]);  // 指定の位置に差し込む
    }
    $pdo->prepare('UPDATE band SET play_order = play_order + 1000 WHERE live_day_id = ?')->execute([$liveDayId]);
    $update = $pdo->prepare('UPDATE band SET play_order = ? WHERE band_id = ?');
    foreach ($ids as $i => $id) {
        $update->execute([$i + 1, $id]);
    }
}

/** その日程の「次の出演順」（最後尾） */
function next_play_order(PDO $pdo, int $liveDayId): int
{
    $st = $pdo->prepare('SELECT COALESCE(MAX(play_order), 0) + 1 FROM band WHERE live_day_id = ?');
    $st->execute([$liveDayId]);
    return (int)$st->fetchColumn();
}

/**
 * user_account.member_id がまだ無いユーザーを、同じ名前のメンバーに紐付ける。
 * member_id は UNIQUE なので、既に誰かに紐付いているメンバーには紐付けない。
 */
function link_users_to_members(PDO $pdo): void
{
    $index = load_member_index($pdo);
    $update = $pdo->prepare('UPDATE IGNORE user_account SET member_id = ? WHERE user_id = ?');
    foreach ($pdo->query('SELECT user_id, name FROM user_account WHERE member_id IS NULL')->fetchAll() as $u) {
        $m = $index[member_key($u['name'])] ?? null;
        if ($m) {
            $update->execute([$m['id'], $u['user_id']]); // 既に使われていれば UNIQUE で無視される
        }
    }
    // 表記ゆれ（髙/高・空白）で紐付いた人もいるので、名前をメンバー名にそろえる
    sync_account_names($pdo);
}

/**
 * メンバーと紐付いているアカウントの名前（user_account.name）を、メンバー名にそろえる。
 * 名前の正は member.name。アカウント名はその写し（未紐付けの人だけ自分の名前を持つ）。
 * $memberId を渡すとその人だけ、null なら紐付いている全員。
 */
function sync_account_names(PDO $pdo, ?int $memberId = null): void
{
    $sql = 'UPDATE user_account u JOIN member m ON m.member_id = u.member_id SET u.name = m.name';
    if ($memberId === null) {
        $pdo->exec($sql);
        return;
    }
    $pdo->prepare($sql . ' WHERE u.member_id = ?')->execute([$memberId]);
}
