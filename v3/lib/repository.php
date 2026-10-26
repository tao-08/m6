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
        . '<input autocomplete="off" name="' . $prefix . '[label_new]" value="' . h($newName) . '" maxlength="50" placeholder="例: 野外ライブ"'
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
    $html .= '<input autocomplete="off" name="new_role" maxlength="30" placeholder="新しい係を追加" aria-label="新しい係">';
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
/**
 * アーティスト $fromId を $toId に統合する（表記ゆれの片付け）。呼ぶ側でトランザクションにすること。
 *   バンド（band.artist_id）・オムニバスの曲（song.artist_id）・別称を統合先に付け替えてから消す。
 *   先に付け替える: 外部キーが ON DELETE SET NULL / CASCADE なので、先に消すと紐付けや別称が消えてしまう
 *   消える側の名前は統合先の別称にしておく（その表記のマイアルバムを拾い続けるため。既にあれば INSERT IGNORE で何もしない）
 */
function merge_artist(PDO $pdo, int $fromId, int $toId): void
{
    $st = $pdo->prepare('SELECT name FROM artist WHERE artist_id = ?');
    $st->execute([$fromId]);
    $fromName = $st->fetchColumn();
    if ($fromName === false || $fromId === $toId) {
        return;
    }
    $pdo->prepare('UPDATE band SET artist_id = ? WHERE artist_id = ?')->execute([$toId, $fromId]);
    $pdo->prepare('UPDATE song SET artist_id = ? WHERE artist_id = ?')->execute([$toId, $fromId]);
    $pdo->prepare('UPDATE artist_alias SET artist_id = ? WHERE artist_id = ?')->execute([$toId, $fromId]);
    $pdo->prepare('INSERT IGNORE INTO artist_alias (name, artist_id) VALUES (?, ?)')->execute([$fromName, $toId]);
    // ふりがなは、統合先が空なら統合元のものを引き継ぐ（merge_members と同じ）
    $pdo->prepare('UPDATE artist t JOIN artist f ON f.artist_id = ? SET t.name_kana = COALESCE(t.name_kana, f.name_kana) WHERE t.artist_id = ?')
        ->execute([$fromId, $toId]);
    $pdo->prepare('DELETE FROM artist WHERE artist_id = ?')->execute([$fromId]);
}

/**
 * $artistIds のアーティストをコピー元にしているバンドを、まとめてオムニバスにする。呼ぶ側でトランザクションにすること。
 *   曲の song.artist_id が NULL（= バンドと同じ）だと、オムニバスにした途端に曲のアーティストが分からなくなる。
 *   なので先に、NULL の曲へ元のバンドのアーティストを書き込んでから band.artist_id を外す（songs_edit と同じ形）
 * @return int オムニバスにしたバンドの数
 */
function make_bands_omnibus(PDO $pdo, array $artistIds): int
{
    $artistIds = array_values(array_unique(array_map('intval', $artistIds)));
    if (!$artistIds) {
        return 0;
    }
    $in = implode(',', array_fill(0, count($artistIds), '?'));
    $pdo->prepare("UPDATE song s JOIN band b ON b.band_id = s.band_id SET s.artist_id = b.artist_id
        WHERE b.is_omnibus = 0 AND b.artist_id IN ($in) AND s.artist_id IS NULL")->execute($artistIds);
    $st = $pdo->prepare("UPDATE band SET is_omnibus = 1, artist_id = NULL WHERE is_omnibus = 0 AND artist_id IN ($in)");
    $st->execute($artistIds);
    return $st->rowCount();
}

/**
 * アーティストを、どこからも使われていなければ消す。呼ぶ側でトランザクションにすること。
 *   オムニバスにしてコピー元を外したバンドの「ボカロバンド」のような名前を、アーティストの表そのものから片付けるため。
 *   消さないとき: 他のバンドのコピー元（= 同じ名前の非オムニバスのバンドがある）・どこかの曲のアーティスト・別称がある
 *   （別称があるのは統合で手入れしたアーティストなので残す）
 * @return bool 消したら true
 */
function delete_artist_if_unused(PDO $pdo, int $artistId): bool
{
    $st = $pdo->prepare('DELETE FROM artist WHERE artist_id = ?
        AND NOT EXISTS (SELECT 1 FROM band WHERE artist_id = ?)
        AND NOT EXISTS (SELECT 1 FROM song WHERE artist_id = ?)
        AND NOT EXISTS (SELECT 1 FROM artist_alias WHERE artist_id = ?)');
    $st->execute([$artistId, $artistId, $artistId, $artistId]);
    return $st->rowCount() > 0;
}

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
const BUILTIN_INSTRUMENTS = ['Vo', 'Gt', 'Ba', 'Dr', 'Key', 'Cho', 'Perc', 'Vn', 'Sax', 'Prog', 'Harm', 'etc'];

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
 *     Vo を持つ人も、Vo をやっていない曲のコーラスは見る（1曲目 Vo・2曲目 Ba/Cho → Ba/Cho）
 *   セトリ未登録・band_id が分からない → band_member しか手がかりがないので chorus_host() のまま
 *   （sings_while_playing() の Vo/Gt と同じ考え方）
 */
function chorus_host_in_band(?int $bandId, int $memberId, array $shorts): ?string
{
    $songs = $bandId === null ? null : songs_of_member($bandId, $memberId);
    if ($songs === null) {
        return chorus_host($shorts);
    }
    // セトリがあれば1曲ずつ見る。Vo を持つ人でも、Vo をやっていない曲で弾きながらコーラスしていれば Gt/Cho
    //   例: 1曲目 Vo・2曲目 Ba/Cho → Vo と Ba/Cho
    foreach ($shorts as $short) { // 楽器の並び順で最初のもの
        if ($short === 'Cho' || $short === 'Vo') {
            continue;
        }
        foreach ($songs as $songShorts) {
            if (isset($songShorts[$short], $songShorts['Cho']) && !isset($songShorts['Vo'])) {
                return $short;
            }
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
        // 曲順（track_no）で読む。main_part_shorts() の「同じ曲数なら最初の曲」に使う
        foreach (db()->query('SELECT sp.band_id, sp.member_id, sp.song_id, i.short_name FROM song_performer sp
            JOIN song s ON s.song_id = sp.song_id
            JOIN instrument i ON i.instrument_id = sp.instrument_id
            ORDER BY s.track_no') as $r) {
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
 * その人がそのバンドで一番たくさんの曲でやった楽器の組み合わせ（1曲目 Vo/Gt・2曲目 Gt・3曲目 Gt → ['Gt']）。
 *   同じ曲数なら、先にやった曲（曲順で最初）の組み合わせ。セトリ未登録なら null
 * @return string[]|null short_name の一覧
 */
function main_part_shorts(int $bandId, int $memberId): ?array
{
    $songs = songs_of_member($bandId, $memberId);
    if ($songs === null) {
        return null;
    }
    $count = []; // [組み合わせのキー => 曲数]。曲順に入るので、同じ曲数なら先に入った方が勝つ
    $shortsByKey = [];
    foreach ($songs as $songShorts) {
        $shorts = array_keys($songShorts);
        sort($shorts);
        $key = implode('+', $shorts);
        $count[$key] = ($count[$key] ?? 0) + 1;
        $shortsByKey[$key] = $shorts;
    }
    $best = null;
    foreach ($count as $key => $n) {
        if ($best === null || $n > $count[$best]) {
            $best = $key;
        }
    }
    return $shortsByKey[$best];
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
        foreach (db()->query('SELECT band_id, COUNT(*) AS n FROM song WHERE is_se = 0 GROUP BY band_id') as $r) {
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
 *   楽器は、セトリがある人なら一番たくさんの曲でやった組み合わせだけ（同じ曲数なら曲順で最初の曲。main_part_shorts）
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
        // セトリがある人は「一番たくさんの曲でやった楽器」だけ見せる（main_part_shorts）。
        //   残した行から band_id を外して、lineup_parts() にはその組み合わせを「全曲でやった」ものとして渡す
        //   （外さないと、Vo/Gt の人の「Gt だけの曲」までセトリから拾って Gt の行を足してしまう）
        $main = [];
        foreach ($bandRows as $i => $r) {
            $memberId = (int)$r['member_id'];
            $main[$memberId] ??= main_part_shorts($bandId, $memberId) ?? false;
            if ($main[$memberId] === false) {
                continue; // セトリ未登録 → band_member の楽器を全部（今までどおり）
            }
            if (!in_array($r['short_name'], $main[$memberId], true)) {
                unset($bandRows[$i]);
                continue;
            }
            unset($bandRows[$i]['band_id']);
        }
        $byMember = [];
        foreach (lineup_parts(array_values($bandRows)) as $p) {
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
 *   登録した曲数（song の行数）がタイムテーブルの曲数（band.song_count）以上のときに出す（曲数が未入力のバンドは出さない）。
 *   途中まで（3/5）は何も出さない。多め（6/5）は出す。
 */
function setlist_badge(int $registered, int $planned, int $linked = 0): string
{
    if ($registered <= 0 || $planned <= 0 || $registered < $planned) { // 曲数が未入力（0）のバンドには出さない
        return '';
    }
    // ✓ が「登録済」の意味。押すと「セットリスト登録済（◯曲）」のポップアップが出る（app.js の setupSetlistTip）。
    // スマホでは「セットリスト」の文字を隠して ✓ だけ。押せる部品なので <span> ではなく <button>
    // サブスク連携（song.track_id）済みの曲が半分未満ならくすんだ色（is-dim）
    $label = 'セットリスト登録済（' . $registered . '曲）';
    $dim = $linked * 2 < $registered ? ' is-dim' : '';
    if ($dim !== '') {
        $label .= '・楽曲情報未登録あり';
    }
    return '<button type="button" class="setlist-badge' . $dim . '" data-setlist-tip="' . h($label) . '" aria-label="' . h($label) . '">'
        . icon('check') . '<span class="setlist-badge__text">セットリスト</span></button>';
}

/* =====================================================================
 *  セットリストの「出演まで非公開」（band.setlist_private。migrations/023）
 * =====================================================================
 *  非公開にしたバンドは、公開時刻までは「曲名が出る画面」から曲を隠す。
 *    公開時刻 = 出演日 + 終演時刻 + 30分
 *             （終演時刻が無ければ 開始時刻 + 1時間、どちらも無ければ 出演日の翌日 0:00）
 *    見える人 = 管理者と、そのバンドのメンバー（自分たちのセトリは確認・編集できる）
 *    隠さないもの = 「登録済み ✓」のバッジと曲数（曲名は分からないので）
 *
 *  公開時刻は DB に保存せず、毎回 SQL で計算する。
 *  保存すると、あとでタイムテーブルの時刻を直したときにズレる（同じ事実を2か所に持たない＝正規化の考え方）。
 *
 *  ここでは「SQL の部品（文字列）」を返し、各画面の SELECT の WHERE に足して使う。
 *  曲を読む SQL が何か所もあるので、条件を1か所にまとめておく（どこか1つだけ直し忘れる、を防ぐ）
 * ===================================================================== */

/**
 * セットリストを公開する日時を計算する SQL の式。$b は band の別名（FROM band b なら 'b'）。
 *   TIMESTAMP(日付, 時刻) は「日付 + 時刻」の日時を作る MySQL の関数。+ INTERVAL 30 MINUTE で30分後
 *   出演日は live_day にあるので、( SELECT … ) で横から取ってくる（相関サブクエリ）
 */
function setlist_open_sql(string $b = 'b'): string
{
    $held = "(SELECT hd.held_on FROM live_day hd WHERE hd.live_day_id = $b.live_day_id)";
    return "(CASE
        WHEN $held IS NULL THEN NULL
        WHEN $b.end_time IS NOT NULL THEN TIMESTAMP($held, $b.end_time) + INTERVAL 30 MINUTE
        WHEN $b.start_time IS NOT NULL THEN TIMESTAMP($held, $b.start_time) + INTERVAL 1 HOUR
        ELSE TIMESTAMP($held) + INTERVAL 1 DAY END)";
}

/**
 * 「いま、この人にはセットリストを隠すか」の SQL の条件式（真なら隠す）。
 *   使い方: "... WHERE NOT " . setlist_hidden_sql('b')
 *
 *   ・現在時刻は MySQL の NOW() ではなく PHP の時刻を渡す。
 *     レンタルサーバーの MySQL は時計が UTC（日本より9時間遅い）のことがあり、NOW() だと公開がずれるため。
 *     date() の結果はユーザーが入力した値ではないので、quote()（安全な文字列にする）して埋め込んでよい
 *   ・出演日が未登録（NULL）のときは、非公開のチェックを外すまで隠す
 */
function setlist_hidden_sql(string $b = 'b'): string
{
    if (is_admin()) {
        return '(1 = 0)'; // 管理者には何も隠さない（常に偽の条件）
    }
    $me = (int)(current_user()['member_id'] ?? 0); // メンバーに紐付いていないアカウントは 0（誰とも一致しない）
    $now = db()->quote(date('Y-m-d H:i:s'));
    $open = setlist_open_sql($b);
    return "($b.setlist_private = 1
        AND NOT EXISTS (SELECT 1 FROM band_member hm WHERE hm.band_id = $b.band_id AND hm.member_id = $me)
        AND ($open IS NULL OR $now < $open))";
}

/**
 * 「アーティスト × そのアーティストをコピーしたバンド」の組を表すサブクエリ。
 *   ふつうのバンド    : band.artist_id
 *   オムニバスのバンド: band.artist_id は使わず、曲に付いたアーティスト（song.artist_id）。付いていない曲は数えない
 *   UNION（ALL なし）なので同じ組は1つにまとまる → オムニバスで同じアーティストを2曲やっても1回
 *   使い方: FROM (" . artist_plays_sql() . ") p   … p.artist_id, p.band_id
 *
 *   前は定数（ARTIST_PLAYS_SQL）だったが、「出演まで非公開」のオムニバスのバンドは
 *   曲のアーティストから曲がバレるので数えない、という「見る人によって変わる条件」が入ったので関数にした
 *   （定数は決まった文字しか持てない。見る人・時刻で変わるものは関数で作る）
 */
function artist_plays_sql(): string
{
    return 'SELECT artist_id, band_id FROM band WHERE is_omnibus = 0 AND artist_id IS NOT NULL
    UNION
    SELECT s.artist_id, s.band_id FROM song s JOIN band b ON b.band_id = s.band_id
        WHERE b.is_omnibus = 1 AND s.artist_id IS NOT NULL AND NOT ' . setlist_hidden_sql('b');
}

/**
 * 1つのバンドについて「隠すか」と「公開日時」をまとめて調べる（バンドページ・曲の編集画面用）
 * @return array{hidden: bool, private: bool, open_at: ?string}
 */
function band_setlist_visibility(PDO $pdo, int $bandId): array
{
    $st = $pdo->prepare('SELECT b.setlist_private, ' . setlist_hidden_sql('b') . ' AS hidden, ' . setlist_open_sql('b') . ' AS open_at
        FROM band b WHERE b.band_id = ?');
    $st->execute([$bandId]);
    $r = $st->fetch() ?: ['setlist_private' => 0, 'hidden' => 0, 'open_at' => null];
    return ['hidden' => (bool)$r['hidden'], 'private' => (bool)$r['setlist_private'], 'open_at' => $r['open_at']];
}

/** 「10/25（土）21:30」の形。公開日時の案内に使う */
function fmt_datetime(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    return fmt_date($dt) . ' ' . date('G:i', strtotime($dt));
}

/**
 * バンドのお気に入り（❤）の数と、自分が付けているか。
 *   誰が付けたかは返さない（画面に出すのは数だけ = 匿名）。
 *   band_like テーブルがまだ無い DB（migrations/022 を流していない）では空の配列を返す（ページは止めない）。
 * @param int[] $bandIds
 * @return array<int, array{count: int, liked: bool}> band_id => 数と自分が付けたか（0件のバンドは入らない）
 */
function band_likes(PDO $pdo, array $bandIds, int $userId): array
{
    if (!$bandIds) {
        return [];
    }
    // IN (?, ?, ?) の ? をバンドの数だけ作る（値はプリペアドステートメントで渡す）
    $marks = implode(',', array_fill(0, count($bandIds), '?'));
    try {
        // SUM(user_id = ?): 自分の行なら 1、それ以外は 0 を足す → 1 以上なら自分が付けている
        $st = $pdo->prepare("SELECT band_id, COUNT(*) AS cnt, SUM(user_id = ?) AS mine
            FROM band_like WHERE band_id IN ($marks) GROUP BY band_id");
        $st->execute([$userId, ...array_map('intval', $bandIds)]);
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            return [];
        }
        throw $e;
    }
    $likes = [];
    foreach ($st as $r) {
        $likes[(int)$r['band_id']] = ['count' => (int)$r['cnt'], 'liked' => (int)$r['mine'] > 0];
    }
    return $likes;
}

/**
 * お気に入り（❤）のボタン。吹き出しに数、その右にハート。押すと api_band_like.php に送る（app.js の setupLikes）
 * @param array{count: int, liked: bool}|null $like band_likes() の1件（無ければ 0件）
 */
function like_button(int $bandId, ?array $like, string $class = '', string $api = ''): string
{
    $count = $like['count'] ?? 0;
    $liked = $like['liked'] ?? false;
    // $api: 送り先の API 名（掲示板の書き込みは 'api_board_like'）。空ならバンド用。assets/app.js の setupLikes が読む
    return '<button type="button" class="like' . ($class !== '' ? ' ' . h($class) : '') . ($liked ? ' is-liked' : '') . '"'
        . ' data-like="' . $bandId . '"' . ($api !== '' ? ' data-like-api="' . h($api) . '"' : '') . ' aria-pressed="' . ($liked ? 'true' : 'false') . '"'
        . ' aria-label="お気に入り（' . $count . '）" title="お気に入り（匿名）">'
        . '<span class="like__count" data-like-count>' . $count . '</span>'
        . icon('favorite', 'like__heart') // Google の Material Symbols の favorite。押すと塗りつぶし（CSS の FILL 1）
        . '</button>';
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
          AND NOT " . setlist_hidden_sql('b') . " -- 出演まで非公開のバンドは、曲のアーティストからも曲がバレるので出さない
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
/**
 * 楽器フィルター（漏斗 → 押すと楽器ラベル → 選ぶと漏斗がその楽器に変わって絞り込む）。app.js の setupInstFilter
 *   $parts: パート（segments・title・order）の一覧。兼任（Vo/Gt など）がある楽器は「単体・兼任・All」に分ける
 *   $target: 絞り込む範囲 / $tori: 🐔（トリのバンドだけ）も出す / $fav: ❤（見ている人がお気に入りにしたバンドだけ。🐔の右）も出す
 *   $items: 行ごとに隠すとき（メンバー一覧の .member-row）。空なら出演履歴の バンド → 日 → 年度 で隠す
 *   行の data-insts は inst_tokens() で作る
 */
function inst_filter(array $parts, string $target, bool $tori = false, string $items = '', bool $fav = false): string
{
    // 楽器ごとに、単体があるか・兼任の組み合わせ（key => パート）を集める
    $groups = [];
    foreach ($parts as $p) {
        $segs = $p['segments'];
        foreach ($segs as $sg) {
            $g = &$groups[$sg['class']];
            $g['short'] ??= $sg['short'];
            $g['order'] = min($g['order'] ?? PHP_INT_MAX, $p['order'] ?? 0);
            if (count($segs) === 1) {
                $g['solo'] = $p;
            } else {
                $g['combos'][implode('-', array_column($segs, 'class'))] = $p;
            }
            unset($g);
        }
    }
    if (count($groups) + ($tori ? 1 : 0) + ($fav ? 1 : 0) < 2) {
        return '';
    }
    // Cho の行は Dr の下（兼任のある楽器の最後）
    uasort($groups, static fn($a, $b) => [$a['short'] === 'Cho', $a['order']] <=> [$b['short'] === 'Cho', $b['order']]);
    $opt = static fn(string $inst, string $label, string $aria = ''): string =>
        '<button type="button" data-inst="' . h($inst) . '"' . ($aria !== '' ? ' aria-label="' . h($aria) . '"' : '') . '>' . $label . '</button>';
    // ポップアップは表（grid）。楽器ごとに1行で、単体は左端・兼任は真ん中・All は右端の列に揃える
    $rows = [[$opt('', icon('filter_alt_off', 'icon--fill'), 'すべて') . ($tori ? $opt('tori', '<span class="tori-badge">🐔</span>', 'トリ') : '')
        . ($fav ? $opt('fav', icon('favorite'), 'お気に入り') : '')]];
    $plain = []; // 兼任がない楽器は最後に横4列で並べる（etc は一番最後）
    $etc = '';
    $top = [];       // Vo / Vo All / Cho / Cho All（[data-inst, ラベル]）
    $comboRows = [];
    $width = [0, 0, 0]; // 兼任の種類ごとの列数（Vo の兼任・Cho の兼任・その他の兼任）
    $cols = 2;
    foreach ($groups as $cls => $g) {
        $single = '<span class="part part--' . h($cls) . '">' . h($g['short']) . '</span>';
        if (empty($g['combos'])) {
            if ($cls === 'other') {
                $etc = $opt($cls, $single);
            } else {
                $plain[] = $opt($cls, $single);
            }
            continue;
        }
        // Vo・Cho は兼任（Vo/Gt・Gt/Cho など）が他の楽器の行と重複するので、単体と All だけを解除の下の段に横に並べる
        if (in_array($cls, ['vo', 'cho'], true)) {
            if (isset($g['solo'])) {
                $top[] = [$cls . '!', $single];
            }
            $top[] = [$cls, '<span class="part part--all part--' . h($cls) . '">' . h($g['short']) . ' All</span>'];
            continue;
        }
        $combos = $g['combos'];
        // 兼任は Vo の兼任・Cho の兼任・その他の兼任 に分け、それぞれ決まった列に置く（無い行はその列を空ける）
        $byRank = [[], [], []];
        foreach ($combos as $key => $p) {
            $segs = explode('-', $key);
            $byRank[in_array('vo', $segs, true) ? 0 : (in_array('cho', $segs, true) ? 1 : 2)][$key] = $p;
        }
        foreach ($byRank as $r => $list) {
            $width[$r] = max($width[$r], count($list));
        }
        $comboRows[] = [$cls, $g, $single, $byRank];
    }
    // 列: 単体（1列目）→ Vo の兼任 → Cho の兼任 → その他の兼任 → All（右端）
    $start = [2];
    foreach ([1, 2] as $r) {
        $start[$r] = $start[$r - 1] + $width[$r - 1];
    }
    $cols = max($cols, $start[2] + $width[2]);
    if ($top) {
        $row = '';
        foreach ($top as $c => [$inst, $label]) { // 決まった列に置く（CSS の「All は右端」などより優先）
            $row .= str_replace('<button ', '<button style="grid-column: ' . ($c + 1) . '" ', $opt($inst, $label));
        }
        $rows[] = [$row];
        $cols = max($cols, count($top));
    }
    foreach ($comboRows as [$cls, $g, $single, $byRank]) {
        $row = isset($g['solo']) ? $opt($cls . '!', $single) : '';
        foreach ($byRank as $r => $list) {
            $c = $start[$r];
            foreach ($list as $key => $p) {
                $row .= str_replace('<button ', '<button style="grid-column: ' . $c++ . '" ', $opt($key, part_badge($p)));
            }
        }
        $row .= $opt($cls, '<span class="part part--all part--' . h($cls) . '">' . h($g['short']) . ' All</span>');
        $rows[] = [$row, isset($g['solo'])];
    }
    if ($etc !== '') {
        $plain[] = $etc;
    }
    $cols = max($cols, min(4, count($plain)));
    foreach (array_chunk($plain, 4) as $chunk) {
        $rows[] = [implode('', $chunk)];
    }
    $html = '<span class="inst-filter" data-inst-filter="' . h($target) . '"' . ($items !== '' ? ' data-inst-items="' . h($items) . '"' : '') . '>'
        . '<button type="button" class="btn btn--ghost btn--sm inst-filter__btn" aria-label="楽器で絞り込み" aria-expanded="false">' . icon('filter_alt', 'icon--fill') . '</button>'
        . '<span class="inst-filter__menu" style="--cols: ' . $cols . '" hidden>';
    foreach ($rows as $i => $r) {
        // --row: 行の番号（CSS の grid-row）。単体がない行は、兼任を2列目から始める（単体の列を空ける）
        $html .= '<span class="inst-filter__group' . (isset($r[1]) && !$r[1] ? ' is-no-solo' : '') . '" style="--row: ' . ($i + 1) . '">' . $r[0] . '</span>';
    }
    return $html . '</span></span>';
}

/** 楽器フィルター用の目印（data-insts）。楽器（vo）・単体（vo!）・兼任の組み合わせ（vo-gt） */
function inst_tokens(array $parts): array
{
    $t = [];
    foreach ($parts as $p) {
        $classes = array_column($p['segments'], 'class');
        array_push($t, ...$classes);
        $t[] = count($classes) === 1 ? $classes[0] . '!' : implode('-', $classes);
    }
    return array_values(array_unique($t));
}

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

/**
 * リボン付きメダル（よく組むメンバーの「相手から見て上位3位」の印）。member.php で使う
 *   $rank: 1 = 金 / 2 = 銀 / 3 = 銅。色は CSS の .medal--1〜3 が --m-light / --m-dark を決め、SVG はそれで塗る
 *   （SVG の fill に var() を書くと CSS 変数が使える → ダークモードでも CSS 側だけで色を変えられる）
 *   リボンもメダルと同じ色（明るい色と暗い色の2本）にする
 */
function partner_medal(int $rank, string $name, string $what = 'よく組むメンバー'): string
{
    // 押すと（PC はマウスを乗せると）出すポップアップの文。画面には書かず、ポップアップと読み上げだけで伝える
    $label = $name . 'の' . $what . '上位3位'; // $what = どのランキングか（よく組むメンバー / よく演奏するメンバー）
    // <button> で包む: 指で押せる「部品」にするため（<svg> だけだとキーボードで選べない）。
    //   data-setlist-tip を付けると、セトリの ✓ と同じポップアップ（app.js の setupSetlistTip）がこの文を出す
    //   h() で " や < を無害な文字に置き換えてから属性に入れる（XSS 対策）
    return '<button type="button" class="medal-btn" data-setlist-tip="' . h($label) . '" aria-label="' . h($label) . '">'
        . '<svg class="medal medal--' . $rank . '" viewBox="0 0 16 20" aria-hidden="true">'
        // リボン（左右2本の帯。左は明るい色、右は暗い色）
        . '<path d="M3 0h4l2 7H5z" fill="var(--m-light)"/><path d="M9 0h4l-2 7H7z" fill="var(--m-dark)"/>'
        // メダル本体（外側の暗い縁 + 内側の明るい面）。順位は色（金・銀・銅）で表すので数字は書かない
        . '<circle cx="8" cy="13" r="6" fill="var(--m-dark)"/><circle cx="8" cy="13" r="4.4" fill="var(--m-light)"/>'
        . '</svg></button>';
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
    $update = $pdo->prepare('UPDATE song SET track_no = ?, title = ?, is_se = ?, artist_id = ?, track_source = ?, track_id = ?
        WHERE song_id = ? AND band_id = ?');
    $insert = $pdo->prepare('INSERT INTO song (band_id, track_no, title, is_se, artist_id, track_source, track_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $addRole = $pdo->prepare('INSERT IGNORE INTO band_member (band_id, member_id, instrument_id) VALUES (?, ?, ?)');
    $clear = $pdo->prepare('DELETE FROM song_performer WHERE song_id = ?');
    $addPerformer = $pdo->prepare('INSERT IGNORE INTO song_performer (song_id, band_id, member_id, instrument_id) VALUES (?, ?, ?, ?)');

    foreach (array_values($songs) as $i => $song) {
        [$trackSource, $trackId] = $song['track'] ?? [null, null];
        if ($song['song_id'] && in_array($song['song_id'], $existing, true)) {
            $update->execute([$i + 1, $song['title'], (int)!empty($song['is_se']), $song['artist_id'], $trackSource, $trackId, $song['song_id'], $bandId]);
            $songId = $song['song_id'];
        } else {
            $insert->execute([$bandId, $i + 1, $song['title'], (int)!empty($song['is_se']), $song['artist_id'], $trackSource, $trackId]);
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
    // 「登録した曲数 >= タイムテーブルの曲数」でセトリが揃ったかを判定しているので（setlist_badge）
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

// =====================================================================
//  時刻のずらし・休憩の位置の補正（band_edit.php でバンドを削除・追加したときの、後ろの追随）
//    ・時刻は今までどおり「絶対時刻」で持ったまま、「ある位置から後ろ」をまとめてずらすだけ
//    ・バンドを消す / 差し込むと出演順が 1,2,3… に振り直されるので、休憩の after_order も同じ分だけ直す
//      （renumber_bands は休憩に触らない。live_edit.php などからも呼ばれるので、そちらの挙動は変えない）
// =====================================================================

/**
 * 'HH:MM:SS' に $sec 秒を足す。
 * @return string|false|null NULL/空は NULL のまま / 0:00〜23:59 を出る（日付をまたぐ）なら false
 */
function shift_time_by(?string $time, int $sec): string|false|null
{
    if ($time === null || $time === '') {
        return null; // 時刻が未入力の枠は、ずらしても未入力のまま
    }
    [$h, $m, $s] = sscanf($time, '%d:%d:%d') + [0, 0, 0];
    $total = $h * 3600 + $m * 60 + $s + $sec;
    if ($total < 0 || $total >= 86400) {
        return false;
    }
    return sprintf('%02d:%02d:%02d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60);
}

/** 'HH:MM' / 'HH:MM:SS' を 0 時からの秒数にする。NULL・空は null */
function time_to_sec(?string $time): ?int
{
    if ($time === null || $time === '') {
        return null;
    }
    [$h, $m, $s] = sscanf($time, '%d:%d:%d') + [0, 0, 0];
    return $h * 3600 + $m * 60 + $s;
}

/**
 * 日程の「ある位置から後ろ」のバンドと休憩の時刻を $sec 秒ずらす（マイナスなら前へ）。トランザクションの中で呼ぶこと
 *   $bandFrom: play_order >= これのバンドを動かす / $breakFrom: after_order >= これの休憩を動かす
 *   ADDTIME(時刻, SEC_TO_TIME(秒)) の UPDATE をテーブルごとに1本。NULL の時刻は NULL のまま
 * @return bool false = 動かすと 0:00〜23:59 を出る（日付をまたぐ）ので何もしなかった
 */
function shift_day_times(PDO $pdo, int $dayId, int $bandFrom, int $breakFrom, int $sec): bool
{
    if ($sec === 0) {
        return true; // 動かさないなら何もしない
    }
    // [テーブル, 位置の列, この位置から後ろ]。テーブル名・列名は下の固定の値だけ（入力から来た文字は SQL に入らない）
    $targets = [['band', 'play_order', $bandFrom], ['live_break', 'after_order', $breakFrom]];

    // ① 動かす行の、いちばん早い時刻と遅い時刻（開始・終了の両方を見る）が、ずらしても 0〜86399 秒に収まるか
    $min = null;
    $max = null;
    foreach ($targets as [$table, $col, $from]) {
        $st = $pdo->prepare("SELECT MIN(LEAST(COALESCE(TIME_TO_SEC(start_time), 86400), COALESCE(TIME_TO_SEC(end_time), 86400))),
                MAX(GREATEST(COALESCE(TIME_TO_SEC(start_time), -1), COALESCE(TIME_TO_SEC(end_time), -1)))
            FROM $table WHERE live_day_id = ? AND $col >= ?");
        $st->execute([$dayId, $from]);
        [$lo, $hi] = $st->fetch(PDO::FETCH_NUM);
        // 時刻が1つも無い行だけのとき、lo は 86400・hi は -1 になる（= 動かす時刻が無いので判定に入れない）
        if ($lo !== null && (int)$lo < 86400) {
            $min = $min === null ? (int)$lo : min($min, (int)$lo);
        }
        if ($hi !== null && (int)$hi >= 0) {
            $max = $max === null ? (int)$hi : max($max, (int)$hi);
        }
    }
    if (($min !== null && $min + $sec < 0) || ($max !== null && $max + $sec > 86399)) {
        return false; // 日付をまたぐ
    }

    // ② ずらす（NULL の時刻は ADDTIME も NULL のまま）
    $set = 'SET start_time = ADDTIME(start_time, SEC_TO_TIME(?)), end_time = ADDTIME(end_time, SEC_TO_TIME(?))';
    foreach ($targets as [$table, $col, $from]) {
        $pdo->prepare("UPDATE $table $set WHERE live_day_id = ? AND $col >= ?")->execute([$sec, $sec, $dayId, $from]);
    }
    return true;
}

/**
 * 出演順 $order のバンドを消したあと、休憩の after_order を1つ前へ詰める（renumber_bands の前に呼ぶ）。
 *   消したバンドの直後の休憩（after_order = $order）は、1つ前のバンドの後へ移る。それより後ろの休憩は、それぞれのバンドに付いていく。
 *   移る休憩は、もともとそこにある休憩（after_order = $order - 1）の後ろに並ぶよう seq を足す
 */
function shift_breaks_for_delete(PDO $pdo, int $dayId, int $order): void
{
    $st = $pdo->prepare('SELECT COALESCE(MAX(seq) + 1, 0) FROM live_break WHERE live_day_id = ? AND after_order = ?');
    $st->execute([$dayId, $order - 1]);
    $pdo->prepare('UPDATE live_break SET seq = seq + ? WHERE live_day_id = ? AND after_order = ?')
        ->execute([(int)$st->fetchColumn(), $dayId, $order]);
    $pdo->prepare('UPDATE live_break SET after_order = after_order - 1 WHERE live_day_id = ? AND after_order >= ?')
        ->execute([$dayId, $order]);
}

/**
 * 位置 $position にバンドを差し込む前に、休憩の after_order を1つ後ろへ送る（renumber_bands の前に呼ぶ）。
 *   $position - 1 番の後の休憩はそのまま（新しいバンドの前に出る）。$position 番以降の後ろにあった休憩は、それぞれのバンドに付いていく
 */
function shift_breaks_for_insert(PDO $pdo, int $dayId, int $position): void
{
    $pdo->prepare('UPDATE live_break SET after_order = after_order + 1 WHERE live_day_id = ? AND after_order >= ?')
        ->execute([$dayId, $position]);
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
        $insertSong = $pdo->prepare('INSERT INTO song (band_id, track_no, title, is_se, artist_id, track_source, track_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $copyPerformers = $pdo->prepare('INSERT INTO song_performer (song_id, band_id, member_id, instrument_id)
            SELECT ?, ?, member_id, instrument_id FROM song_performer WHERE song_id = ?');
        foreach ($songs->fetchAll() as $s) {
            $insertSong->execute([$toId, $s['track_no'], $s['title'], $s['is_se'], $s['artist_id'], $s['track_source'], $s['track_id']]);
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
    // 🚩 理由は両方を合わせる（ビットの OR）。「誰について」も統合先へ（同じ人は主キーで飛ばす）
    if ((int)$from['needs_check'] & ~(int)$to['needs_check']) {
        $set['needs_check'] = (int)$from['needs_check'] | (int)$to['needs_check'];
    }
    $pdo->prepare('INSERT IGNORE INTO band_check_member (band_id, member_id)
        SELECT ?, member_id FROM band_check_member WHERE band_id = ?')->execute([$toId, $fromId]);
    if ($set) {
        // 列名は上で決めた固定の名前だけ（入力から来た文字は SQL に入らない）
        $sql = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($set)));
        $pdo->prepare("UPDATE band SET $sql WHERE band_id = ?")->execute([...array_values($set), $toId]);
    }

    // 4. お気に入り（❤）も統合先へ（同じ人が両方に付けていたら主キーで1つになる）
    try {
        $pdo->prepare('INSERT IGNORE INTO band_like (band_id, user_id, created_at)
            SELECT ?, user_id, created_at FROM band_like WHERE band_id = ?')->execute([$toId, $fromId]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') { // 42S02 = テーブルが無い（migrations/022 をまだ流していない）。それ以外は本当のエラー
            throw $e;
        }
    }

    // 5. 統合元を消す
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
    // 🚩「誰について」も統合先へ（統合元の行は member 削除の CASCADE で消える）
    $pdo->prepare('INSERT IGNORE INTO band_check_member (band_id, member_id)
        SELECT band_id, ? FROM band_check_member WHERE member_id = ?')->execute([$toId, $fromId]);
    // 統合元の band_member を消すと、統合元の song_performer も CASCADE で消える
    $pdo->prepare('DELETE FROM band_member WHERE member_id = ?')->execute([$fromId]);

    // COALESCE(a, b): a が NULL なら b を使う
    $pdo->prepare('UPDATE member t JOIN member f ON f.member_id = ?
        SET t.name_kana = COALESCE(t.name_kana, f.name_kana), t.bio = COALESCE(t.bio, f.bio), t.entry_year = COALESCE(t.entry_year, f.entry_year),
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
 *    <input autocomplete="off" data-suggest-list="datalist の id"> が、同じ id の <datalist> の option を候補にする
 * ===================================================================== */

/**
 * メンバー名の候補: [名前 => ふりがな（無ければ NULL）]。ふりがなでも探せるように一緒に読む
 * アカウント設定で「退会した人を候補に出さない」にしている人には、退会済みの人を出さない
 * （require_login() がセッションに入れた hide_retired を見る。候補から消えるだけで、打てば今まで通り使える）
 */
function member_name_choices(PDO $pdo): array
{
    $where = !empty($_SESSION['user']['hide_retired']) ? ' WHERE retired = 0' : '';
    return $pdo->query('SELECT name, name_kana FROM member' . $where . ' ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
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

/**
 * 🚩 バンドの「誰について確認してほしいか」（band_check_member）を入れ直す。
 *   $bits = 0（依頼なし）なら全部消す（お知らせを ✕ で消した記録 check_dismiss も）。
 *   入れるのは「いまそのバンドの band_member にいる人」だけ（INSERT ... SELECT で DB に確かめさせる。
 *   書き換えられた id や、同じ保存でバンドから外した人は入らない）。
 *   履歴を持たない表なので、消して入れ直すだけでよい。
 */
function save_check_members(PDO $pdo, int $bandId, int $bits, array $memberIds): void
{
    $pdo->prepare('DELETE FROM band_check_member WHERE band_id = ?')->execute([$bandId]);
    if ($bits === 0) {
        // 旗を外した = 確認が終わった。✕ で消した記録も消す（また旗を立てたら、みんなに届き直すように）
        $pdo->prepare('DELETE FROM check_dismiss WHERE band_id = ?')->execute([$bandId]);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn($id) => $id > 0)));
    if ($bits === 0 || !$ids) {
        return;
    }
    $in = implode(',', array_fill(0, count($ids), '?')); // 数だけ ? を並べる（値はプリペアドで渡す）
    $pdo->prepare("INSERT IGNORE INTO band_check_member (band_id, member_id)
        SELECT DISTINCT band_id, member_id FROM band_member WHERE band_id = ? AND member_id IN ($in)")->execute([$bandId, ...$ids]);
}

/** 🚩 確認してほしいメンバー: [member_id => 名前] */
function load_check_members(PDO $pdo, int $bandId): array
{
    $st = $pdo->prepare('SELECT m.member_id, m.name FROM band_check_member c JOIN member m ON m.member_id = c.member_id
        WHERE c.band_id = ? ORDER BY m.name');
    $st->execute([$bandId]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

/**
 * 掲示板・コメントの投稿者名（HTML）。匿名なら「匿名」だけ。管理者にはそのあとに本名も出す
 * @param int|null $memberId 投稿者のメンバーID（あれば本名からメンバーページへリンク）
 */
function author_html(bool $anonymous, ?string $displayName, ?string $name, ?int $memberId, bool $isAdmin): string
{
    $real = $memberId !== null ? '<a href="member?id=' . $memberId . '">' . h($name ?? '') . '</a>' : h($name ?? '退会ユーザー');
    $shown = $anonymous ? '匿名' : $displayName; // 匿名が優先。名義が無ければ本名
    if ($shown === null || $shown === '') {
        return $real;
    }
    return h($shown) . ($isAdmin ? ' <span class="muted">（' . $real . '）</span>' : '');
}

/** 掲示板・コメントの送信元の名義（POST の display_name）。空なら null（本名）。匿名のときも null */
function posted_display_name(bool $anonymous): ?string
{
    $n = mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)($_POST['display_name'] ?? ''))), 0, 30);
    return $anonymous || $n === '' ? null : $n;
}

/** 本文欄の上に置く、送信元の名義の入力と匿名チェック（匿名のあいだは名義を使えない。assets/app.js の setupAliasField） */
function alias_field_html(string $userName): string
{
    return '<div class="alias-field" data-alias-field>'
        . '<input type="text" name="display_name" maxlength="30" autocomplete="off" placeholder="' . h($userName) . '" aria-label="名義" title="名義（空欄は ' . h($userName) . '）">'
        . '<label class="check" title="匿名で投稿"><input type="checkbox" name="anonymous" value="1"> 匿名</label>'
        . '<span class="alias-field__info"><span class="icon alias-field__icon" data-alias-info role="button" tabindex="0" aria-label="説明" aria-expanded="false">info</span>'
        . '<span class="alias-field__pop" role="tooltip" hidden>送信者名を入力すると匿名状態になります<br>（管理者を除く）</span></span>'
        . '</div>';
}
