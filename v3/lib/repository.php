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

/**
 * 日程名の候補: いつもの日程名（DAY_LABELS）＋ これまでに新しく作られた日程名
 *   日程名のマスタ表は作らず、live_day に保存されている日程名をそのまま候補にする
 *   （新しく作った日程名は、保存した時点で次から選べるようになる）
 */
function day_labels(PDO $pdo): array
{
    $saved = $pdo->query("SELECT DISTINCT label FROM live_day WHERE label NOT LIKE '#%' ORDER BY label")->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_merge(DAY_LABELS, $saved)));
}

/**
 * 日程名のプルダウンの値（日程名 / '__new__'）と「新しい日程名」の入力から、保存する日程名を決める
 * @return array{0: string, 1: ?string}  [日程名, エラーメッセージ（問題なければ null）]
 */
function resolve_day_label(string $sel, string $new, array $labels): array
{
    if ($sel === '__new__') {
        $new = trim($new);
        // 「#」で始まる名前は、ライブ編集で入れ替えに使う仮の名前（#日程ID）とぶつかるので使わせない
        if ($new === '' || mb_strlen($new) > 50 || str_starts_with($new, '#')) {
            return [$new, '新しい日程名は1〜50文字で入力してください（「#」で始まる名前は使えません）'];
        }
        return [$new, null];
    }
    if (!in_array($sel, $labels, true)) { // 選択式だが、書き換えられたリクエストも弾く
        return [$sel, '日程名は一覧から選んでください'];
    }
    return [$sel, null];
}

/**
 * 日程名のプルダウン ＋「＋ 新しい日程名を作る」を選んだときだけ出る入力欄（会場と同じ形）。$prefix は d[ID] / nd / tt[番号] など
 *   値は '__new__'（「new」という日程名とぶつからないように）。出し入れは assets/app.js の setupSmallThings
 *   $attrs は select に付ける属性（ライブ編集では重複チェック用の data-day-label など）
 */
function day_label_control(string $prefix, string $sel, string $newName, array $labels, string $attrs = ''): string
{
    if ($sel !== '__new__' && !in_array($sel, $labels, true)) {
        $sel = $labels[0];
    }
    $html = '<select name="' . $prefix . '[label]" aria-label="日程名" data-new-select="__new__"' . $attrs . '>';
    foreach ($labels as $label) {
        $html .= '<option value="' . h($label) . '"' . ($sel === $label ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $html . '<option value="__new__"' . ($sel === '__new__' ? ' selected' : '') . '>＋ 新しい日程名を作る</option></select>'
        . '<input name="' . $prefix . '[label_new]" value="' . h($newName) . '" maxlength="50" placeholder="例: 野外ライブ"'
        . ' aria-label="新しい日程名" data-new-input data-label-new' . ($sel === '__new__' ? '' : ' hidden') . '>';
}

/** 会場名 → venue_id。無ければ作る。空なら NULL（venue_id は NULL 可） */
function find_or_create_venue(PDO $pdo, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    // venue.name は UNIQUE なので、INSERT IGNORE で「無ければ作る」を1文で書ける。新しい会場は並び順の一番うしろ
    $pdo->prepare('INSERT IGNORE INTO venue (name, sort_order) SELECT ?, COALESCE(MAX(sort_order), 0) + 1 FROM venue')
        ->execute([mb_substr($name, 0, 50)]);
    $st = $pdo->prepare('SELECT venue_id FROM venue WHERE name = ?');
    $st->execute([mb_substr($name, 0, 50)]);
    return (int)$st->fetchColumn();
}

/** 係の名前 → role_id。無ければ作る（find_or_create_venue と同じやり方） */
function find_or_create_role(PDO $pdo, string $name): int
{
    $pdo->prepare('INSERT IGNORE INTO role (name, sort_order) SELECT ?, COALESCE(MAX(sort_order), 0) + 1 FROM role')->execute([$name]);
    $st = $pdo->prepare('SELECT role_id FROM role WHERE name = ?');
    $st->execute([$name]);
    return (int)$st->fetchColumn();
}

/**
 * 会場・係の並び順を1つ上（$up = true）か下へ動かす（masters.php の ↑↓）。
 *   全部を今の順に読んで、PHP の配列で隣と入れ替え、sort_order を 1, 2, 3 … で付け直す。
 *   数十行しかないので全部書き直しても軽いし、sort_order が重なっていても（同じ数字が2つ）必ず正しく直る。
 * $table / $idCol は SQL に直接入るので、プログラムに書いた決まった名前しか渡さないこと（ユーザーの入力は渡さない）
 */
function move_sort_order(PDO $pdo, string $table, string $idCol, int $id, bool $up): void
{
    $ids = array_map('intval', $pdo->query("SELECT $idCol FROM $table ORDER BY sort_order, name")->fetchAll(PDO::FETCH_COLUMN));
    $i = array_search($id, $ids, true);
    $j = $up ? $i - 1 : $i + 1;
    if ($i === false || !isset($ids[$j])) {
        return; // 見つからない・もう一番上（下）
    }
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    $pdo->beginTransaction();
    $st = $pdo->prepare("UPDATE $table SET sort_order = ? WHERE $idCol = ?");
    foreach ($ids as $n => $rowId) {
        $st->execute([$n + 1, $rowId]);
    }
    $pdo->commit();
}

/** そのメンバーの係 [role_id => 名前]（masters.php で決めた並び順） */
function member_roles(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT r.role_id, r.name FROM member_role mr JOIN role r ON r.role_id = mr.role_id
        WHERE mr.member_id = ? ORDER BY r.sort_order, r.name');
    $st->execute([$memberId]);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * プロフィール編集フォームの「学部」「係」の欄（account.php と member.php で同じものを出す）。
 * 係は、今ある係のチェックボックス ＋ 新しい係の入力欄（1つ）。保存は member_edit.php
 */
function profile_faculty_role_fields(PDO $pdo, array $member): string
{
    $mine = member_roles($pdo, (int)$member['member_id']);
    $all = $pdo->query('SELECT role_id, name FROM role ORDER BY sort_order, name')->fetchAll(PDO::FETCH_KEY_PAIR);

    $html = '<label class="field"><span>学部</span><select name="faculty"><option value="">未選択</option>';
    foreach (FACULTIES as $f) {
        $html .= '<option value="' . h($f) . '"' . ($member['faculty'] === $f ? ' selected' : '') . '>' . h($f) . '</option>';
    }
    $html .= '</select></label>';

    $html .= '<fieldset class="field role-checks"><legend>係（自称可）</legend>';
    foreach ($all as $id => $name) {
        $html .= '<label class="role-checks__item"><input type="checkbox" name="roles[]" value="' . (int)$id . '"'
            . (isset($mine[$id]) ? ' checked' : '') . '> ' . h($name) . '</label>';
    }
    $html .= '<input name="new_role" maxlength="30" placeholder="新しい係を追加" aria-label="新しい係">';
    return $html . '</fieldset>';
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
function instruments_for_choice(mixed $value, bool $cho = false): array
{
    if (is_string($value) && preg_match('/^vo:(\w+)$/', $value, $m) && isset(VOCAL_ROLES[$m[1]])) {
        $also = VOCAL_ROLES[$m[1]]['also'];
        return array_values(array_filter([instrument_id_by_short('Vo'), $also === null ? null : instrument_id_by_short($also)]));
    }
    $id = filter_var($value, FILTER_VALIDATE_INT);
    $valid = array_map('intval', array_column(instruments(), 'instrument_id'));
    $id = is_int($id) && in_array($id, $valid, true) ? $id : OTHER_INSTRUMENT_ID;
    // Cho のトグル（Gt/Cho）。ボーカル・Cho そのもの・その他にはコーラスを付けない（書き換えられたリクエストでも）
    $choId = instrument_id_by_short('Cho');
    if ($cho && $choId !== null && chorus_allowed((string)$id)) {
        return [$id, $choId];
    }
    return [$id];
}

/**
 * 楽器欄の値（'2' や 'vo:gt'）に Cho のトグルを付けられるか。
 *   ボーカル（Vo / Vo/Gt など）は付けられない。Cho そのもの・その他（楽器が分からない）も付けない
 */
function chorus_allowed(string $choice): bool
{
    return !str_starts_with($choice, 'vo:') && !in_array($choice, chorus_blocked_choices(), true);
}

/** Cho のトグルを付けられない楽器欄の値（'vo:◯◯' 以外）。JS にも data-cho-block で渡す */
function chorus_blocked_choices(): array
{
    return array_map('strval', array_filter([instrument_id_by_short('Vo'), instrument_id_by_short('Cho'), OTHER_INSTRUMENT_ID]));
}

/**
 * 楽器欄の横の「コーラスあり」トグル（Gt/Cho = ギターを弾きながらコーラス）。バンド編集・タイムテーブル編集・セトリ編集で共通。
 *   押すと aria-pressed と隠し項目の値（0 / 1）が切り替わる（assets/app.js の setupChorusToggle）。
 *   チェックボックスにしないのは、バンド編集の m_name[] / m_inst[] が並んだ配列なので、
 *   チェックが無い行は送られずに行がずれてしまうから（隠し項目なら必ず 0 か 1 が送られる）
 *   ボーカル系の楽器を選んでいるときは押せない（JS が楽器欄の変更に合わせて切り替える）
 * @param string $name 隠し項目の name（'m_cho[]' など）
 */
function chorus_toggle(string $name, bool $on, string $choice, bool $disabled = false): string
{
    $allowed = chorus_allowed($choice);
    $on = $on && $allowed;
    return '<span class="cho-toggle-wrap" data-cho-toggle data-cho-block="' . h(implode(',', chorus_blocked_choices())) . '">'
        . '<button type="button" class="cho-toggle" aria-pressed="' . ($on ? 'true' : 'false') . '" title="弾きながらコーラス（Gt/Cho など）"'
        . ($allowed && !$disabled ? '' : ' disabled') . '>コーラスあり</button>'
        . ($disabled ? '' : '<input type="hidden" name="' . h($name) . '" value="' . ($on ? '1' : '0') . '">') . '</span>';
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
 *   Gt と Cho を持つ人（ボーカルではない人）は、Gt の行の Cho トグルを ON にして Cho の行は出さない（cho => true）
 * @return array [['name' => ..., 'choice' => '2' | 'vo:gt', 'cho' => bool], ...]
 */
function merge_vocal_roles(array $rows): array
{
    $has = []; // 名前 => [instrument_id => true]
    foreach ($rows as $r) {
        $has[$r['name']][(int)$r['instrument_id']] = true;
    }
    $vo = instrument_id_by_short('Vo');
    $cho = instrument_id_by_short('Cho');
    // 名前 => Cho トグルを付ける instrument_id（lineup_parts() と同じ決め方: chorus_host_in_band）
    //   行に band_id と member_id があればセトリも見る（1曲目 Cho だけ・2曲目 Vn だけ → Vn にトグルを付けない）
    $shortOf = array_column(instruments(), 'short_name', 'instrument_id');
    $idOf = array_flip($shortOf);
    $choHost = [];
    foreach ($rows as $r) {
        if ($cho === null || array_key_exists($r['name'], $choHost)) {
            continue;
        }
        $shorts = [];
        foreach ($rows as $o) {
            if ($o['name'] === $r['name']) {
                $shorts[] = $shortOf[(int)$o['instrument_id']] ?? '';
            }
        }
        $host = isset($r['band_id'], $r['member_id'])
            ? chorus_host_in_band((int)$r['band_id'], (int)$r['member_id'], $shorts) : chorus_host($shorts);
        $choHost[$r['name']] = $host === null ? null : (int)$idOf[$host];
    }
    $choAlone = []; // 名前 => true（コーラスだけの曲もあった → Cho の行も残す）
    foreach ($rows as $r) {
        if (($choHost[$r['name']] ?? null) !== null && isset($r['band_id'], $r['member_id'])
            && songs_played((int)$r['band_id'], (int)$r['member_id'], ['Cho'], $shortOf[$choHost[$r['name']]]) > 0) {
            $choAlone[$r['name']] = true;
        }
    }
    $merged = []; // 名前 => [Vo にまとめた instrument_id => true]
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['instrument_id'];
        if (isset($merged[$r['name']][$id])) {
            continue; // Vo の行にまとめ済み
        }
        if ($id === $cho && isset($choHost[$r['name']]) && !isset($choAlone[$r['name']])) {
            continue; // Gt の行の Cho トグルにまとめ済み
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
        $out[] = ['name' => $r['name'], 'choice' => $choice, 'cho' => ($choHost[$r['name']] ?? null) === $id];
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
 * @param string $chorus 楽器を弾きながらのコーラス（Gt + Cho の2行）をどう扱うか（chorus_host() の人だけ）
 *   'merge' = 「Gt/Cho」1つにまとめる（表示用。1曲でもコーラスがあれば Gt/Cho）
 *   'drop'  = Cho を消して「Gt」だけ（メンバー一覧の担当楽器・よく組むメンバーの回数。Cho 単体の出演は Cho のまま）
 *   'split' = まとめない。Gt と Cho を別々に数える（個人ページの「Gt × 3」・統計）
 */
function lineup_parts(array $rows, bool $mergeVocal = true, string $chorus = 'merge'): array
{
    $has = []; // member_id => [short_name => true]
    foreach ($rows as $r) {
        $has[(int)$r['member_id']][$r['short_name']] = true;
    }
    $choHost = [];  // member_id => Cho をくっつける楽器の short_name（Gt/Cho の Gt）
    $choAlone = []; // member_id => true（Gt/Cho のほかに、コーラスだけの曲もあった → Cho としても出す）
    foreach ($rows as $r) {
        $id = (int)$r['member_id'];
        if ($chorus === 'split' || array_key_exists($id, $choHost)) {
            continue;
        }
        $bandId = isset($r['band_id']) ? (int)$r['band_id'] : null;
        $choHost[$id] = chorus_host_in_band($bandId, $id, array_keys($has[$id]));
        if ($choHost[$id] !== null && $bandId !== null && songs_played($bandId, $id, ['Cho'], $choHost[$id]) > 0) {
            $choAlone[$id] = true;
        }
    }
    $merged = []; // member_id => [Vo にまとめた short_name => true]
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['member_id'];
        if (isset($merged[$id][$r['short_name']])) {
            continue; // Vo の方にまとめ済み
        }
        if ($r['short_name'] === 'Cho' && isset($choHost[$id]) && !isset($choAlone[$id])) {
            continue; // Gt/Cho の方にまとめ済み（drop なら消す）
        }
        $part = ['member_id' => $id, 'name' => $r['name'], 'short' => $r['short_name'], 'title' => $r['instrument_name'],
            'segments' => [['short' => $r['short_name'], 'class' => instrument_class($r['short_name'])]], 'order' => (int)$r['sort_order'] * 10];
        if ($chorus === 'merge' && ($choHost[$id] ?? null) === $r['short_name']) {
            // Gt/Cho: Gt のすぐ後ろ（Gt と Ba の間）に並べる
            $part = ['short' => $r['short_name'] . '/Cho', 'title' => $r['instrument_name'] . '＋コーラス', 'order' => $part['order'] + 5,
                'segments' => [...$part['segments'], ['short' => 'Cho', 'class' => instrument_class('Cho')]]] + $part;
        }
        if ($mergeVocal && $r['short_name'] === 'Vo') {
            $bandId = isset($r['band_id']) ? (int)$r['band_id'] : null;
            $n = 0;
            foreach (VOCAL_ROLES as $role) {
                $n++;
                if ($role['also'] !== null && isset($has[$id][$role['also']]) && sings_while_playing($bandId, $id, $role['also'])) {
                    $vocalOnly = $part; // Vo だけの曲もあったときに使う
                    $part = ['short' => $role['label'], 'title' => $role['title'], 'order' => $part['order'] + $n,
                        'segments' => [...$part['segments'], ['short' => $role['also'], 'class' => instrument_class($role['also'])]]] + $part;
                    // Vo/Gt の曲のほかに「Gt だけ」の曲もあれば、Gt の行はまとめずに残す（Gt としても数える）
                    if ($bandId === null || songs_played($bandId, $id, [$role['also']], 'Vo') === 0) {
                        $merged[$id][$role['also']] = true;
                    }
                    // 「Vo だけ」の曲もあれば、Vo としても数える
                    if ($bandId !== null && songs_played($bandId, $id, ['Vo'], $role['also']) > 0) {
                        $out[] = $vocalOnly;
                    }
                    break; // 1人につきまとめるのは1つだけ（merge_vocal_roles と同じ）
                }
            }
        }
        $out[] = $part;
    }
    return $out;
}

/**
 * 「楽器を弾きながらコーラス」の、Cho をくっつける楽器（Gt/Cho の Gt）。当てはまらなければ null。
 *   Cho と、Vo と Cho 以外の楽器を持っている人だけ。ボーカルの人（Vo を持つ人）は Cho を付けられない（入力でも選べない）
 *   楽器が2つ以上あれば、並び順で最初の楽器にくっつける（Gt + Key + Cho → Gt/Cho と Key）
 * @param string[] $shorts その人がそのバンドで持っている楽器の short_name（sort_order 順）
 */
function chorus_host(array $shorts): ?string
{
    if (!in_array('Cho', $shorts, true) || in_array('Vo', $shorts, true)) {
        return null;
    }
    foreach ($shorts as $short) {
        if ($short !== 'Cho') {
            return $short;
        }
    }
    return null;
}

/**
 * chorus_host() を、セトリ（song_performer）を見て決める版。lineup_parts() で使う。
 *   セトリにその人の行がある → 「同じ1曲で その楽器 と Cho を両方やった」楽器だけ（並び順で最初のもの）。
 *     例: 1曲目 Cho だけ・2曲目 Vn だけ → null（ヴァイオリンを弾きながらのコーラスではない。Vn と Cho は別々）
 *   セトリ未登録・band_id が分からない → band_member しか手がかりがないので chorus_host() のまま
 *   （sings_while_playing() の Vo/Gt と同じ考え方）
 */
function chorus_host_in_band(?int $bandId, int $memberId, array $shorts): ?string
{
    $host = chorus_host($shorts);
    if ($host === null || $bandId === null || songs_of_member($bandId, $memberId) === null) {
        return $host;
    }
    foreach ($shorts as $short) {
        if ($short !== 'Cho' && songs_played($bandId, $memberId, [$short, 'Cho']) > 0) {
            return $short;
        }
    }
    return null;
}

/**
 * そのパートで「演奏した曲」を数えるときの楽器（songs_played() に渡す）。
 *   Gt/Cho は「Gt を弾いた曲」（1曲でもコーラスがあれば Gt/Cho なので、Gt と Cho を同じ曲でやった曲だけに絞らない）
 *   Vo/Gt は今までどおり「Vo と Gt を同じ曲でやった曲」
 */
function part_play_shorts(array $part): array
{
    $shorts = array_column($part['segments'], 'short');
    return count($shorts) > 1 ? array_values(array_diff($shorts, ['Cho'])) : $shorts;
}

/**
 * セトリ（song_performer）から「その人が、そのバンドの各曲で何をやったか」。
 * 1リクエストで何度も使うので、1回だけ全部読んで覚えておく（サークルの規模なら全件でも軽い）
 * @return array|null [song_id => [short_name => true]]。セトリに行がない人は null
 */
function songs_of_member(int $bandId, int $memberId): ?array
{
    static $played = null; // ["band_id:member_id"][song_id][short_name] = true
    if ($played === null) {
        $played = [];
        foreach (db()->query('SELECT sp.band_id, sp.member_id, sp.song_id, i.short_name FROM song_performer sp
            JOIN instrument i ON i.instrument_id = sp.instrument_id') as $r) {
            $played[$r['band_id'] . ':' . $r['member_id']][(int)$r['song_id']][$r['short_name']] = true;
        }
    }
    return $played["$bandId:$memberId"] ?? null;
}

/**
 * その人が、そのバンドで $shorts の楽器を「全部いっしょに」演奏した曲の数（Vo/Gt なら Vo と Gt を両方やった曲）。
 *   $without を渡すと、その楽器をやっていない曲だけ数える（「Vo なしで Gt だけ」の曲など）
 * セトリ未登録なら 0
 */
function songs_played(int $bandId, int $memberId, array $shorts, ?string $without = null): int
{
    $n = 0;
    foreach (songs_of_member($bandId, $memberId) ?? [] as $songShorts) {
        if (!array_diff($shorts, array_keys($songShorts)) && ($without === null || !isset($songShorts[$without]))) {
            $n++;
        }
    }
    return $n;
}

/**
 * そのバンドで一番たくさんの曲に出た人の曲数（セトリ = song_performer）。songs_of_member と同じく1回だけ全部読んで覚えておく
 */
function band_max_member_songs(int $bandId): int
{
    static $max = null; // [band_id => 曲数]
    if ($max === null) {
        $max = [];
        foreach (db()->query('SELECT band_id, MAX(n) AS n FROM (
                SELECT band_id, member_id, COUNT(DISTINCT song_id) AS n FROM song_performer GROUP BY band_id, member_id
            ) t GROUP BY band_id') as $r) {
            $max[(int)$r['band_id']] = (int)$r['n'];
        }
    }
    return $max[$bandId] ?? 0;
}

/**
 * そのバンドのセットリストに登録した曲の数（song の行数）。1回だけ全部読んで覚えておく
 */
function band_song_count(int $bandId): int
{
    static $counts = null; // [band_id => 曲数]
    if ($counts === null) {
        $counts = [];
        foreach (db()->query('SELECT band_id, COUNT(*) AS n FROM song GROUP BY band_id') as $r) {
            $counts[(int)$r['band_id']] = (int)$r['n'];
        }
    }
    return $counts[$bandId] ?? 0;
}

/**
 * その人がそのバンドの「サポート」か = セトリが3曲以上あって、その人は1曲だけ演奏した、かつ ほかに2曲以上出た人がいる。
 *   2曲以下のバンドは「1曲だけ」でも半分は出ているのでサポートにしない
 *   「全員が1曲ずつ」のバンド（曲ごとにメンバーが入れ替わる企画バンドなど）は全員サポートにならないように
 *   セトリ未登録のバンドではサポートにしない
 */
function is_support(int $bandId, int $memberId): bool
{
    return band_song_count($bandId) >= 3
        && count(songs_of_member($bandId, $memberId) ?? []) === 1 && band_max_member_songs($bandId) >= 2;
}

/**
 * その人が、そのバンドで「歌いながら $also を弾いた」と言えるか（Vo/Dr などにまとめてよいか）。
 *   セトリにその人の行がある → 同じ1曲で Vo と $also を両方選んだ曲があるときだけ true。
 *     例: 1曲目 Dr・2曲目 Vo → false（ドラムボーカルではない。Dr と Vo は別々に数える）
 *   セトリに行がない（曲が未登録）→ band_member しか手がかりがないので true（バンド編集で Vo/Dr を選んだとみなす）
 *   band_id が分からない → true（今までどおり）
 */
function sings_while_playing(?int $bandId, int $memberId, string $also): bool
{
    if ($bandId === null || songs_of_member($bandId, $memberId) === null) {
        return true;
    }
    return songs_played($bandId, $memberId, ['Vo', $also]) > 0;
}

/**
 * lineup_parts() の結果を、パートごとにまとめる（バンドページ・ライブページの「Vo 鈴木 / Vo/Gt 山田 / Gt 田中…」）。
 * @return array [order => ['short', 'title', 'segments', 'members' => [['member_id', 'name'], ...]], ...]（並び順どおり）
 */
function lineup_by_part(array $rows): array
{
    $lineup = [];
    // $rows は1つのバンドの分だけなので、band_id は先頭の行から取る（lineup_parts の結果には band_id が入っていない）
    $bandId = isset($rows[0]['band_id']) ? (int)$rows[0]['band_id'] : null;
    foreach (lineup_parts($rows) as $p) {
        $lineup[$p['order']] ??= ['short' => $p['short'], 'title' => $p['title'], 'segments' => $p['segments'], 'members' => []];
        $lineup[$p['order']]['members'][] = ['member_id' => $p['member_id'], 'name' => $p['name'],
            'songs' => $bandId !== null ? songs_played($bandId, $p['member_id'], part_play_shorts($p)) : 0,
            'support' => $bandId !== null && is_support($bandId, $p['member_id'])];
    }
    ksort($lineup);
    // 同じパートに何人もいたら、そのパートで演奏した曲が多い人から（同じ曲数なら今までどおり名前順。usort は順番を保つ）
    foreach ($lineup as &$part) {
        usort($part['members'], static fn($a, $b) => $b['songs'] <=> $a['songs']);
    }
    unset($part);
    return $lineup;
}

/**
 * lineup_by_part() の結果を HTML の「Vo 鈴木 / Gt 田中 … | サポート Key 佐藤」に（バンドページ・ライブページ）。
 *   1人だけのパートを2つ以上やった人は「Vn Cho 田中」の1行にまとめる
 *   サポート（is_support）は最後の「サポート」枠にまとめる。通常メンバーがいなくなったパートは出さない
 */
function lineup_html(array $lineup, ?int $meId): string
{
    $chip = static fn(array $m, string $class = ''): string => '<a class="chip' . $class . ((int)$m['member_id'] === $meId ? ' chip--me' : '')
        . '" href="member?id=' . (int)$m['member_id'] . '">' . h($m['name']) . '</a>';
    // その人1人だけのパートが2つ以上ある人は、楽器のマークをまとめて1行にする（「Vn Cho 田中」）
    //   1人だけのパート = 通常メンバー（サポートでない人）が1人だけ
    $mainOf = static fn(array $part): array => array_values(array_filter($part['members'], static fn($m) => !$m['support']));
    $soloParts = []; // member_id => [パート, ...]
    foreach ($lineup as $part) {
        $main = $mainOf($part);
        if (count($main) === 1) {
            $soloParts[(int)$main[0]['member_id']][] = $part;
        }
    }
    $html = '';
    $done = [];       // member_id => true（まとめた行をもう出した人）
    $support = [];    // member_id => ['m' => 人, 'badges' => 楽器のマーク]（サポートも1人ぶんにまとめる）
    foreach ($lineup as $part) {
        foreach ($part['members'] as $m) {
            if ($m['support']) {
                $support[(int)$m['member_id']]['m'] = $m;
                $support[(int)$m['member_id']]['badges'] = ($support[(int)$m['member_id']]['badges'] ?? '') . part_badge($part);
            }
        }
        $main = $mainOf($part);
        if (!$main) {
            continue; // 通常メンバーがいなくなったパートは出さない
        }
        $id = (int)$main[0]['member_id'];
        if (count($main) === 1 && count($soloParts[$id]) > 1) {
            if (!isset($done[$id])) {
                $done[$id] = true; // 最初のパートの位置に、その人のパートを全部並べる
                $html .= '<li>' . implode('', array_map('part_badge', $soloParts[$id])) . $chip($main[0]) . '</li>';
            }
            continue;
        }
        $html .= '<li>' . part_badge($part) . implode('', array_map($chip, $main)) . '</li>';
    }
    if ($support) {
        $html .= '<li class="lineup__support"><span class="support-label">サポート</span>'
            . implode('', array_map(static fn($s) => $s['badges'] . $chip($s['m'], ' chip--support'), $support)) . '</li>';
    }
    return '<ul class="lineup">' . $html . '</ul>';
}

/**
 * いくつものバンドの行 → lineup_parts() の結果を1つの配列に（各パートに band_id を付ける）。
 * 「Vo/Gt」のまとめはバンドの中だけで考える（別のバンドで Vo と Gt をやった人をまとめない）。
 * 個人ページ・メンバー一覧・統計の集計で使う。
 * @param iterable $rows [['band_id', 'member_id', 'name', 'short_name', 'instrument_name', 'sort_order'], ...]（sort_order 順）
 * @param bool $mergeVocal false なら Vo/Gt にまとめない（lineup_parts と同じ）
 * @param string $chorus Gt/Cho の扱い（lineup_parts と同じ。'merge' | 'drop' | 'split'）
 */
function lineup_parts_by_band(iterable $rows, bool $mergeVocal = true, string $chorus = 'merge'): array
{
    $rowsByBand = [];
    foreach ($rows as $r) {
        $rowsByBand[(int)$r['band_id']][] = $r;
    }
    $out = [];
    foreach ($rowsByBand as $bandId => $bandRows) {
        foreach (lineup_parts($bandRows, $mergeVocal, $chorus) as $p) {
            $out[] = $p + ['band_id' => $bandId];
        }
    }
    return $out;
}

/**
 * いくつものバンドの行 → バンドごとに「楽器ラベル + 名前」を1人1行（アーティストページ・検索結果）。
 *   Vo と Gt を持つ人は「Vo/Gt」、Gt と Cho を持つ人は「Gt/Cho」の1行（lineup_parts）。それ以外の兼任（Gt + Key など）も「Gt/Key」のように1行にまとめる
 * @param iterable $rows [['band_id', 'member_id', 'name', 'short_name', 'instrument_name', 'sort_order'], ...]（sort_order, name 順）
 * @return array [band_id][member_id] = ['member_id', 'name', 'title', 'segments' => [['short' => 'Vo', 'class' => 'vo'], ...]]
 */
function member_lineups_by_band(iterable $rows): array
{
    $rowsByBand = [];
    foreach ($rows as $r) {
        $rowsByBand[(int)$r['band_id']][] = $r;
    }
    // 歌うパート（Vo / Cho）を先に（Vo/Gt など）。usort は同じ値の順番を保つ（PHP 8）
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
    // ✓ が「登録済」の意味。押すと「セットリスト登録済（◯曲）」のポップアップが出る（app.js の setupSetlistTip）。
    // スマホでは「セットリスト」の文字を隠して ✓ だけ。押せる部品なので <span> ではなく <button>
    $label = 'セットリスト登録済（' . $registered . '曲）';
    return '<button type="button" class="setlist-badge" data-setlist-tip="' . h($label) . '" aria-label="' . h($label) . '">'
        . icon('check') . '<span class="setlist-badge__text">セットリスト</span></button>';
}

/**
 * オムニバスのバンド（band.is_omnibus = 1）の、セットリストの曲に紐付いたアーティスト。
 *   曲にアーティストが付いていない（song.artist_id が NULL）曲は数えない。
 *   並びは初めて出てくる曲順（1曲目のアーティストが先頭）。同じアーティストは1回だけ（GROUP BY）。
 * @param int[] $bandIds
 * @return array<int, list<array{artist_id: int, name: string}>> band_id => アーティストの一覧
 */
function omnibus_artists_by_band(PDO $pdo, array $bandIds): array
{
    if ($bandIds === []) {
        return [];
    }
    // IN (?, ?, ?) の ? をバンドの数だけ作る。値はプリペアドステートメントで渡す
    $in = implode(',', array_fill(0, count($bandIds), '?'));
    $st = $pdo->prepare("SELECT s.band_id, a.artist_id, a.name, MIN(s.track_no) AS first_no
        FROM song s
        JOIN band b ON b.band_id = s.band_id
        JOIN artist a ON a.artist_id = s.artist_id
        WHERE b.is_omnibus = 1 AND s.band_id IN ($in)
        GROUP BY s.band_id, a.artist_id, a.name
        ORDER BY s.band_id, first_no");
    $st->execute(array_values(array_map('intval', $bandIds)));
    $out = [];
    foreach ($st as $r) {
        $out[(int)$r['band_id']][] = ['artist_id' => (int)$r['artist_id'], 'name' => $r['name']];
    }
    return $out;
}

/**
 * アーティストページへのリンク（🔍 名前）を並べた HTML。
 *   オムニバス: 曲に紐付いたアーティストを全部（1つも無ければ何も出さない）
 *   それ以外  : バンドのアーティスト1つ（未設定なら何も出さない）
 * @param array $band  band の行（band_id, is_omnibus, artist_id, artist_name を使う）
 * @param array $omnibusArtists omnibus_artists_by_band() の結果
 */
function band_artist_links(array $band, array $omnibusArtists): string
{
    if ($band['is_omnibus']) {
        $artists = $omnibusArtists[(int)$band['band_id']] ?? [];
    } else {
        $artists = $band['artist_id'] ? [['artist_id' => (int)$band['artist_id'], 'name' => $band['artist_name']]] : [];
    }
    $html = '';
    foreach ($artists as $a) {
        $html .= '<a href="artist?id=' . $a['artist_id'] . '">' . icon('search') . ' ' . h($a['name']) . '</a>';
    }
    return $html;
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
    // 組み合わせのクラス（part--key-cho など）。CSS で組み合わせごとに幅を変えるため
    $combo = 'part--' . implode('-', array_column($segs, 'class'));
    return '<span class="part part--split ' . h($combo) . ($suffix !== '' ? ' part--auto' : '') . '" title="' . h($part['title'])
        . '" style="background: linear-gradient(90deg, ' . h(implode(', ', $stops)) . ')">' . $text . '</span>';
}

/**
 * 担当楽器を色付きのマーク（part_badge）で並べる。メンバー一覧と個人ページで共通。
 *   $tally: tally_parts() の結果（Vo/Gt の人は「Vo/Gt」1つ）
 *   $class: .partbar に足すクラス（表のセルの中なら 'partbar--cell'）
 *   楽器が無ければ「—」
 */
function part_marks(array $tally, bool $withCount = false, string $class = '', int $limit = 0): string
{
    if (!$tally) {
        return '<span class="muted small">—</span>';
    }
    $badge = static fn(array $t): string => part_badge($t, $withCount ? ' × ' . (int)$t['n'] : '');
    // $limit 個より多いときは、残りを「ほか」ボタンにしまう（メンバー一覧の担当楽器。スマホで横にはみ出さないように）
    $rest = $limit > 0 && count($tally) > $limit ? array_slice($tally, $limit) : [];
    $html = '<div class="' . h(trim('partbar ' . $class)) . '">';
    foreach ($rest ? array_slice($tally, 0, $limit) : $tally as $t) {
        $html .= $badge($t);
    }
    if ($rest) {
        // 押す（PC は乗せる）と、中の [data-tip-body] のバッジをポップアップに写して出す（app.js の setupSetlistTip）
        $names = implode(' ', array_column($rest, 'short'));
        $html .= '<button type="button" class="more-names" data-setlist-tip="' . h($names) . '" aria-label="' . h('ほか: ' . $names) . '">ほか'
            . '<span class="partbar" data-tip-body hidden>' . implode('', array_map($badge, $rest)) . '</span></button>';
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
 *
 * セトリに出ている人（song_performer に行がある人）は、楽器を「セトリの実績」で決める（save_songs）。
 *   → ここではその人の行を足しも消しもしない。フォームから消しても・楽器を変えても無視する
 *     （消すと曲ごとの記録が CASCADE で消えてしまうため。変えたいときはセトリを編集する）
 */
function sync_band_members(PDO $pdo, array &$index, int $bandId, array $assignments): void
{
    $st = $pdo->prepare('SELECT DISTINCT member_id FROM song_performer WHERE band_id = ?');
    $st->execute([$bandId]);
    $locked = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))); // member_id => 番号
    $unlocked = static fn(string $key): bool => !isset($locked[(int)explode('-', $key)[0]]);

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
    // array_filter(..., ARRAY_FILTER_USE_KEY): キー（"member_id-instrument_id"）を見て、セトリに出ている人を外す
    foreach (array_filter(array_diff_key($have, $want), $unlocked, ARRAY_FILTER_USE_KEY) as $key => $_) { // 今あるけど、ほしくないもの
        [$m, $i] = explode('-', $key);
        $del->execute([$bandId, $m, $i]);
    }
    $ins = $pdo->prepare('INSERT IGNORE INTO band_member (band_id, member_id, instrument_id) VALUES (?, ?, ?)');
    foreach (array_filter(array_diff_key($want, $have), $unlocked, ARRAY_FILTER_USE_KEY) as $key => $_) { // ほしいけど、まだ無いもの
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
 *  4. 曲に出ている人の、どの曲でも弾いていない楽器を band_member から消す（担当楽器をセトリの実績にそろえる）
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
    // 4. バンドの担当楽器をセトリの実績にそろえる（1曲目 Gt・2曲目 Vo → Vo と Gt = 「Vo/Gt」）。
    //    足すのは上の $addRole。ここでは「曲に出ているのに、どの曲でもその楽器を弾いていない」行を消す。
    //    どの曲にも出ていない人は触らない（全部消すとバンドのメンバーから外れてしまうため）。
    //    消す行には song_performer がぶら下がっていないので、CASCADE で曲の記録が消えることはない
    $pdo->prepare('DELETE bm FROM band_member bm
        WHERE bm.band_id = ?
          AND EXISTS (SELECT 1 FROM song_performer sp
                      WHERE sp.band_id = bm.band_id AND sp.member_id = bm.member_id)
          AND NOT EXISTS (SELECT 1 FROM song_performer sp
                      WHERE sp.band_id = bm.band_id AND sp.member_id = bm.member_id
                        AND sp.instrument_id = bm.instrument_id)')->execute([$bandId]);
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

/**
 * timetable_rows() の結果で、同じ名前の休憩が続いていたら1つにまとめる（ライブページの表示用）。
 *   例: 「休憩」「休憩」と2つ続けて登録されていたら、1つ目の開始〜2つ目の終了の「休憩」1つにする。
 *   間にバンドが入っていれば別の休憩のまま。DB はそのまま（編集画面では別々に直せるように）。
 */
function merge_same_breaks(array $rows): array
{
    $merged = [];
    foreach ($rows as $r) {
        $last = $merged ? $merged[array_key_last($merged)] : null;
        if ($r['type'] === 'break' && $last && $last['type'] === 'break'
            && trim((string)$last['row']['name']) === trim((string)$r['row']['name'])) {
            // 時刻は「最初の開始」と「最後の終了」。片方が空なら、ある方を使う
            $prev = &$merged[array_key_last($merged)]['row'];
            $prev['start_time'] = $prev['start_time'] ?: $r['row']['start_time'];
            $prev['end_time'] = $r['row']['end_time'] ?: $prev['end_time'];
            unset($prev); // 参照を切る（切らないと次の代入で書き換わってしまう）
            continue;
        }
        $merged[] = $r;
    }
    return $merged;
}

/**
 * バンド $fromId を、バンド $toId にまとめる（ライブの統合で「統合する」を選んだバンド。live_edit.php）。
 * 統合先を優先し、統合先が空のところだけ統合元の値で埋める。
 *   ・曲数・メモ・YouTube・コピー元アーティスト … 統合先が空なら統合元の値
 *   ・開始/終了時刻 … 2つで1組（片方だけ入れると「終了 > 開始」の CHECK に引っかかることがある）。統合先が両方空なら統合元の組
 *   ・🚩（楽器の確認） … どちらかに付いていれば付ける
 *   ・メンバー … 両方を合わせる（同じ人・同じ楽器は主キーで1つになる）
 *   ・セットリスト … 統合先に1曲も無いときだけ、統合元の曲（と曲ごとの演奏者）をコピーする
 * 最後に統合元のバンドを消す（band_member・song は CASCADE で一緒に消える）。
 * トランザクションの中で呼ぶこと。
 */
function merge_band_into(PDO $pdo, int $fromId, int $toId): void
{
    $st = $pdo->prepare('SELECT * FROM band WHERE band_id = ?');
    $st->execute([$fromId]);
    $from = $st->fetch();
    $st->execute([$toId]);
    $to = $st->fetch();
    if (!$from || !$to || $fromId === $toId) {
        throw new RuntimeException('統合するバンドが見つかりません');
    }
    $empty = static fn($v) => $v === null || $v === '';

    // 1. メンバー（先に入れる。曲ごとの演奏者は band_member を外部キーで参照しているため）
    $pdo->prepare('INSERT IGNORE INTO band_member (band_id, member_id, instrument_id)
        SELECT ?, member_id, instrument_id FROM band_member WHERE band_id = ?')->execute([$toId, $fromId]);

    // 2. セットリスト: 統合先に曲が無いときだけコピー
    $count = $pdo->prepare('SELECT COUNT(*) FROM song WHERE band_id = ?');
    $count->execute([$toId]);
    $copySongs = (int)$count->fetchColumn() === 0;
    if ($copySongs) {
        $songs = $pdo->prepare('SELECT * FROM song WHERE band_id = ? ORDER BY track_no');
        $songs->execute([$fromId]);
        $insertSong = $pdo->prepare('INSERT INTO song (band_id, track_no, title, artist_id, track_source, track_id) VALUES (?, ?, ?, ?, ?, ?)');
        $copyPerformers = $pdo->prepare('INSERT INTO song_performer (song_id, band_id, member_id, instrument_id)
            SELECT ?, ?, member_id, instrument_id FROM song_performer WHERE song_id = ?');
        foreach ($songs->fetchAll() as $s) {
            $insertSong->execute([$toId, $s['track_no'], $s['title'], $s['artist_id'], $s['track_source'], $s['track_id']]);
            $copyPerformers->execute([(int)$pdo->lastInsertId(), $toId, $s['song_id']]);
        }
    }

    // 3. バンドの情報: 統合先が空のところだけ埋める
    $set = [];
    foreach (['song_count', 'note', 'youtube_url'] as $col) {
        if ($empty($to[$col]) && !$empty($from[$col])) {
            $set[$col] = $from[$col];
        }
    }
    if ($empty($to['start_time']) && $empty($to['end_time'])) {
        $set['start_time'] = $from['start_time'];
        $set['end_time'] = $from['end_time'];
    }
    if ($copySongs && $from['is_omnibus']) {
        // オムニバスの曲（曲ごとにアーティストを持つ）を持ってきたので、統合先もオムニバスにする
        $set['is_omnibus'] = 1;
        $set['artist_id'] = null;
    } elseif (!$to['is_omnibus'] && $empty($to['artist_id']) && !$from['is_omnibus'] && !$empty($from['artist_id'])) {
        $set['artist_id'] = $from['artist_id'];
    }
    if ($from['needs_check'] && !$to['needs_check']) {
        $set['needs_check'] = 1;
    }
    if ($set) {
        // 列名は上で決めた固定の名前だけ（入力から来た文字は SQL に入らない）
        $sql = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($set)));
        $pdo->prepare("UPDATE band SET $sql WHERE band_id = ?")->execute([...array_values($set), $toId]);
    }

    // 4. 統合元を消す
    delete_band($pdo, $fromId);
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
            t.music_app = COALESCE(t.music_app, f.music_app), t.faculty = COALESCE(t.faculty, f.faculty)
        WHERE t.member_id = ?')->execute([$fromId, $toId]);
    // 係も統合先へ（両方に同じ係があれば主キー重複を INSERT IGNORE で飛ばす。統合元の行は member 削除の CASCADE で消える）
    $pdo->prepare('INSERT IGNORE INTO member_role (member_id, role_id)
        SELECT ?, role_id FROM member_role WHERE member_id = ?')->execute([$toId, $fromId]);

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

/**
 * 日程の総バンド数（live_day.total_bands）を、登録済みのバンド数に追いつかせる。
 * バンドを追加したあと（band_edit.php）に呼ぶ。
 *   総バンド数が登録済みより少ない、という矛盾した状態を DB に残さないため。
 *   NULL（未入力）の日程は触らない（NULL = 登録済みの数を使う、なので矛盾しない）。
 *   登録済みより多いときもそのまま（「本当は14組出た」の手入力を消さない）。
 *   取り込みは日程を作り直す（total_bands は NULL）、統合は日程ごと移すので数が変わらない → どちらも呼ばなくてよい
 */
function sync_day_total_bands(PDO $pdo, int $liveDayId): void
{
    $pdo->prepare('UPDATE live_day SET total_bands = (SELECT COUNT(*) FROM band WHERE live_day_id = ?)
        WHERE live_day_id = ? AND total_bands < (SELECT COUNT(*) FROM band WHERE live_day_id = ?)')
        ->execute([$liveDayId, $liveDayId, $liveDayId]);
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

/* =====================================================================
 *  入力候補（assets/app.js の setupSuggest）用のデータ
 *    <input data-suggest-list="datalist の id"> が、同じ id の <datalist> の option を候補にする
 * ===================================================================== */

/**
 * メンバー名の候補: [名前 => ふりがな（無ければ NULL）]。ふりがなでも探せるように一緒に読む
 */
function member_name_choices(PDO $pdo): array
{
    return $pdo->query('SELECT name, name_kana FROM member ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * バンド名の候補: 今までに使われたバンド名を新しい順（年度 → 開催日 → 登録順）に並べ、
 * そのあとに、まだバンド名として使われていないアーティスト名を名前順に足す。
 * 候補は最大8件しか出さないので、何年分も「ヨルシカ(〇〇)」がたまっても最近の代が先に出るようにする
 */
function band_name_choices(PDO $pdo): array
{
    return $pdo->query('SELECT name FROM (
            SELECT b.name, 0 AS grp, MAX(l.fiscal_year) AS y, MAX(d.held_on) AS held, MAX(b.band_id) AS id
            FROM band b
            JOIN live_day d ON d.live_day_id = b.live_day_id
            JOIN live l ON l.live_id = d.live_id
            GROUP BY b.name
            UNION ALL
            SELECT a.name, 1, NULL, NULL, NULL FROM artist a WHERE a.name NOT IN (SELECT name FROM band)
        ) t
        ORDER BY grp, y DESC, held DESC, id DESC, name')->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * <datalist> を作る。$values は候補の文字の配列。
 * $kana（[値 => ふりがな]、member_name_choices の形）を渡すと data-kana に入れる（JS がふりがなでも探す）
 *   例: render_suggest_datalist('member-names', array_keys($names), $names)
 */
function render_suggest_datalist(string $id, array $values, array $kana = []): string
{
    $html = '<datalist id="' . h($id) . '">';
    foreach ($values as $value) {
        $value = (string)$value; // 数字だけの名前は配列のキーにすると int になるので文字に戻す
        $k = (string)($kana[$value] ?? '');
        $html .= '<option value="' . h($value) . '"' . ($k !== '' ? ' data-kana="' . h($k) . '"' : '') . '>';
    }
    return $html . '</datalist>';
}
