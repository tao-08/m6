<?php
/**
 * =====================================================================
 *  repository.php — 「探して、無ければ作る」系の DB 操作をまとめたファイル
 * =====================================================================
 *  取り込み（import）でもバンド編集（band_edit）でも同じ処理が必要になるので、
 *  ページごとに SQL をコピペせず、ここに1つだけ書いて使い回す。
 *  （同じ SQL が何か所にもあると、直すときに1か所直し忘れてバグる）
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/import/text.php';

/**
 * 会場名 → venue_id。無ければ venue に INSERT して新しい id を返す。
 */
function find_or_create_venue(PDO $pdo, string $name): int
{
    $st = $pdo->prepare('SELECT venue_id FROM venue WHERE name = ?');
    $st->execute([$name]);
    $id = $st->fetchColumn(); // 見つからなければ false
    if ($id !== false) {
        return (int)$id;
    }
    // address / website_url は NULL 可なので省略できる
    $pdo->prepare('INSERT INTO venue (name) VALUES (?)')->execute([$name]);
    return (int)$pdo->lastInsertId(); // 今 INSERT した行の AUTO_INCREMENT の値
}

/**
 * (年度, ライブ名) → live_id。無ければ live_master に作る。
 */
function find_or_create_live(PDO $pdo, int $year, string $name): int
{
    $st = $pdo->prepare('SELECT live_id FROM live_master WHERE year = ? AND name = ?');
    $st->execute([$year, $name]);
    $id = $st->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }
    $pdo->prepare('INSERT INTO live_master (year, name) VALUES (?, ?)')->execute([$year, $name]);
    return (int)$pdo->lastInsertId();
}

/**
 * 既存メンバーを「比較用キー → [id, name]」の配列にして返す。
 *
 * なぜキーで引く？
 *   名簿には「岩﨑太一」「岩崎 太一」のように同じ人が違う表記で書かれる。
 *   member_key() で空白除去・全角半角統一・異体字（﨑→崎）をそろえてから比べると、
 *   同じ人を同じ人として見つけられる。
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
 * 名前 → member_id。無ければ member に作る。
 *
 * @param array $index load_member_index() の結果。新しく作った人もここに追加していく（参照渡し &）
 *                     → 同じ取り込みの中で同じ新人が2回出てきても、2回 INSERT しない。
 */
function find_or_create_member(PDO $pdo, array &$index, string $name): int
{
    $key = member_key($name);
    if (isset($index[$key])) {
        return $index[$key]['id'];
    }
    $display = mb_substr(member_display($name), 0, 50); // varchar(50) に収める

    // 照合順序 utf8mb4_general_ci は大文字小文字を区別しないので、
    // キーでは別人扱いでも DB 的には「同じ名前」の人がいるかもしれない → UNIQUE 違反になる前に確認
    $st = $pdo->prepare('SELECT member_id FROM member WHERE name = ?');
    $st->execute([$display]);
    $found = $st->fetchColumn();
    if ($found !== false) {
        $index[$key] = ['id' => (int)$found, 'name' => $display];
        return (int)$found;
    }

    // name_kana は NOT NULL かつ初期値なし → 省略すると STRICT モードでエラーになるので '' を入れる
    $pdo->prepare("INSERT INTO member (name, name_kana) VALUES (?, '')")->execute([$display]);
    $id = (int)$pdo->lastInsertId();
    $index[$key] = ['id' => $id, 'name' => $display];
    return $id;
}

/**
 * バンドにメンバーを登録する（band_member と band_member_instrument の両方に書く）。
 *
 * @param array $assignments [[名前, instrument_id], ...]
 *                           同じ人が Vo と Ba を兼ねる場合は2要素になる
 *
 * 今のテーブルには主キーが無く、同じ行を2回入れても DB は止めてくれない。
 * なので PHP 側で「もう入れたか」を $seen で管理して重複を防ぐ。
 * （migrations/001_recommended_keys.sql を流せば DB 側でも防げるようになる）
 */
function attach_members(PDO $pdo, array &$index, int $bandId, array $assignments): void
{
    $insMember = $pdo->prepare('INSERT INTO band_member (member_id, band_id) VALUES (?, ?)');
    $insInstrument = $pdo->prepare('INSERT INTO band_member_instrument (band_id, member_id, instrument_id) VALUES (?, ?, ?)');
    $seenMember = [];
    $seenInstrument = [];

    foreach ($assignments as [$name, $instrumentId]) {
        $memberId = find_or_create_member($pdo, $index, $name);
        if (!isset($seenMember[$memberId])) {
            $insMember->execute([$memberId, $bandId]);
            $seenMember[$memberId] = true;
        }
        if ($instrumentId !== null && !isset($seenInstrument["$memberId-$instrumentId"])) {
            $insInstrument->execute([$bandId, $memberId, $instrumentId]);
            $seenInstrument["$memberId-$instrumentId"] = true;
        }
    }
}

/**
 * バンドのメンバー情報をすべて消す（編集で入れ直す前に使う）。
 */
function detach_members(PDO $pdo, int $bandId): void
{
    $pdo->prepare('DELETE FROM band_member_instrument WHERE band_id = ?')->execute([$bandId]);
    $pdo->prepare('DELETE FROM band_member WHERE band_id = ?')->execute([$bandId]);
}

/**
 * ライブ1日分（live_detail）を、ぶら下がっているデータごと削除する。
 *
 * 外部キーに ON DELETE CASCADE が付いていないので、
 * 「子 → 親」の順に自分で消さないと外部キー制約エラーになる。
 *   band_member_instrument / band_member（孫） → band（子） → live_detail（親）
 *
 * 日程が1つも無くなった live_master も消す。
 * ※ トランザクションは呼び出し側で張ること。
 */
function delete_live_detail(PDO $pdo, int $liveDetailId): void
{
    $st = $pdo->prepare('SELECT live_id FROM live_detail WHERE live_detail_id = ?');
    $st->execute([$liveDetailId]);
    $liveId = $st->fetchColumn();
    if ($liveId === false) {
        return;
    }

    // サブクエリで「この日のバンド」をまとめて指定している
    $bandsOfDay = 'SELECT band_id FROM band WHERE live_detail_id = ?';
    $pdo->prepare("DELETE FROM band_member_instrument WHERE band_id IN ($bandsOfDay)")->execute([$liveDetailId]);
    $pdo->prepare("DELETE FROM band_member WHERE band_id IN ($bandsOfDay)")->execute([$liveDetailId]);
    $pdo->prepare('DELETE FROM band WHERE live_detail_id = ?')->execute([$liveDetailId]);
    $pdo->prepare('DELETE FROM live_detail WHERE live_detail_id = ?')->execute([$liveDetailId]);

    $st = $pdo->prepare('SELECT COUNT(*) FROM live_detail WHERE live_id = ?');
    $st->execute([$liveId]);
    if ((int)$st->fetchColumn() === 0) {
        $pdo->prepare('DELETE FROM live_master WHERE live_id = ?')->execute([$liveId]);
    }
}

/**
 * 2人のメンバーを1人にまとめる（表記ゆれで同じ人が2人登録されてしまったとき用）。
 *
 *   $fromId の出演記録をすべて $toId に付け替えてから、$fromId を消す。
 *
 * 注意点:
 *   - 同じバンドに2人とも入っていた場合、そのまま UPDATE すると同じ行が2つになる。
 *     → 「まだ $toId の行が無いものだけ」付け替えて、残った $fromId の行は消す。
 *   - ふりがな・入部年度は、統合先が空なら統合元の値を引き継ぐ。
 *   - アカウントの紐付けも引き継ぐ（統合先にまだ誰も紐付いていなければ）。
 * ※ トランザクションは呼び出し側で張ること。
 */
function merge_members(PDO $pdo, int $fromId, int $toId): void
{
    if ($fromId === $toId) {
        throw new RuntimeException('同じメンバー同士は統合できません');
    }

    // ---- band_member: 統合先がまだいないバンドだけ付け替え ----
    $pdo->prepare('UPDATE band_member SET member_id = :to
        WHERE member_id = :from
          AND band_id NOT IN (SELECT band_id FROM (SELECT band_id FROM band_member WHERE member_id = :to2) t)')
        ->execute(['to' => $toId, 'from' => $fromId, 'to2' => $toId]);
    // ↑ MySQL は「UPDATE する表を同じ文のサブクエリで直接読む」のを禁止しているので、
    //   もう1段サブクエリ（t）で包んで一時表にしている
    $pdo->prepare('DELETE FROM band_member WHERE member_id = ?')->execute([$fromId]);

    // ---- band_member_instrument: (バンド, 楽器) が重複しないものだけ付け替え ----
    $pdo->prepare('UPDATE band_member_instrument SET member_id = :to
        WHERE member_id = :from
          AND (band_id, instrument_id) NOT IN (
              SELECT band_id, instrument_id FROM (SELECT band_id, instrument_id FROM band_member_instrument WHERE member_id = :to2) t)')
        ->execute(['to' => $toId, 'from' => $fromId, 'to2' => $toId]);
    $pdo->prepare('DELETE FROM band_member_instrument WHERE member_id = ?')->execute([$fromId]);

    // ---- プロフィールの引き継ぎ ----
    $st = $pdo->prepare('SELECT * FROM member WHERE member_id = ?');
    $st->execute([$fromId]);
    $from = $st->fetch();
    if ($from) {
        $pdo->prepare("UPDATE member SET
                name_kana  = IF(name_kana = '', ?, name_kana),
                entry_year = IF(entry_year IS NULL OR entry_year = 0, ?, entry_year)
            WHERE member_id = ?")
            ->execute([$from['name_kana'], $from['entry_year'], $toId]);
    }

    // ---- アカウントの紐付け ----
    $st = $pdo->prepare('SELECT COUNT(*) FROM user_index WHERE member_id = ?');
    $st->execute([$toId]);
    if ((int)$st->fetchColumn() === 0) {
        $pdo->prepare('UPDATE user_index SET member_id = ? WHERE member_id = ?')->execute([$toId, $fromId]);
    } else {
        $pdo->prepare('UPDATE user_index SET member_id = NULL WHERE member_id = ?')->execute([$fromId]);
    }

    $pdo->prepare('DELETE FROM member WHERE member_id = ?')->execute([$fromId]);
}

/**
 * ある日程のバンドの出演順を 1, 2, 3... に振り直す。
 *
 * @param int|null $movedBandId 並べ替えたバンド（そのバンドを $position 番目に差し込む）
 */
function renumber_bands(PDO $pdo, int $liveDetailId, ?int $movedBandId = null, ?int $position = null): void
{
    $st = $pdo->prepare('SELECT band_id FROM band WHERE live_detail_id = ? ORDER BY play_order, band_id');
    $st->execute([$liveDetailId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    if ($movedBandId !== null && $position !== null) {
        $ids = array_values(array_diff($ids, [$movedBandId]));        // いったん抜いて
        $position = max(1, min($position, count($ids) + 1));
        array_splice($ids, $position - 1, 0, [$movedBandId]);        // 指定の位置に差し込む
    }
    $update = $pdo->prepare('UPDATE band SET play_order = ? WHERE band_id = ?');
    foreach ($ids as $i => $id) {
        $update->execute([$i + 1, $id]);
    }
}

/** バンドを1組削除する（メンバー情報 → バンドの順に消す）。 */
function delete_band(PDO $pdo, int $bandId): void
{
    detach_members($pdo, $bandId);
    $pdo->prepare('DELETE FROM band WHERE band_id = ?')->execute([$bandId]);
}
