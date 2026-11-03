<?php
/**
 * =====================================================================
 *  studio_info.php?id= — スタジオの詳細（studio_list.php から入る）
 * =====================================================================
 *  表示（ログインしていれば誰でも）:
 *    基本情報（サイト・予約ページ・住所・最寄り駅・電話）・地図
 *    料金シミュレーション（JS: assets/studio_info.js。部屋・日にち・時間・人数・割引区分・レンタルから合計と1人あたりを出す）
 *    部屋と料金表（バンド・個人練習。割引区分ごとの料金・条件も）・パック・レンタル・機材
 *    シミュレーションは「パックの自動適用（安ければ）」と「+1時間延長したらいくら増えるか」も出す
 *  編集（幹部と管理者だけ）: 各カードの「編集」を開くとフォームが出る
 *
 *  ✨AIで入力（幹部と管理者）: api_studio_ai.php が調べた提案をプレビューし、選んだ項目を apply_ai で反映（lib/studio_ai.php）
 *  POST は9種類（action）。どれも 権限確認 → CSRF 検証 → 入力検証 → プリペアドステートメント → リダイレクト。
 *    save_info / save_rooms / save_discounts / save_rates / save_packs / save_rentals / save_equipment / apply_ai / delete_studio
 *    ・料金表とパックには「対象」がある: バンド（people = NULL）か、個人練習 n人（people = n）
 *    ・割引（学割・U20 …）は区分ごと（studio_discount）。区分は「使える曜日」を持ち、料金は区分ごとに中間テーブルへ（migrations/089）
 *    ・料金の編集は「対象・曜日の見出し → 行」に分けて表示する。行ごとに部屋のチェック欄があり、複数の部屋に共通の料金を1行で作れる
 *      （チェックなし・「全部屋」= 全部屋共通。パック・機材と同じ形）
 *    ・行の一覧（部屋・料金・レンタル・機材）は venue.php と同じ「行ごとの入力欄をまとめて保存」方式:
 *        id=0 の行 → 追加 / 主な欄を空にした行 → 削除 / それ以外 → 更新
 *    ・行の id は必ず「このスタジオの行か」を確かめ、WHERE にも studio_id を入れる（他のスタジオの行を書き換えさせない）
 *
 *  ■ PRG パターン（Post/Redirect/Get）
 *    POST で保存したら、画面を直接出さずに redirect() で GET し直す。
 *    こうしないと、保存後にブラウザの「再読み込み」を押したとき、同じ POST がもう一度送られて二重登録になる。
 *    Web 開発ではほぼ必ず使う定番の形。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/studio_info.php';
require_once __DIR__ . '/lib/studio_ai.php';
$user = require_login();
$pdo = db();
$canEdit = is_executive();

$studioId = (int)($_GET['id'] ?? $_POST['studio_id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM studio WHERE studio_id = ?');
$st->execute([$studioId]);
$studio = $st->fetch();
if (!$studio) {
    error_page(404, 'スタジオが見つかりません');
}
$self = 'studio_info?id=' . $studioId;

/**
 * フォームのチェック欄 rooms[行番号][] を、部屋 id の配列にする
 *   [] = 全部屋共通（「全部屋」にチェック / 何も選んでいない）
 *   false = このスタジオの部屋ではない id が混ざっている（不正な送信）
 *   $i は「どの行のチェックか」を表す目印（チェックを外した欄は送られてこないので、名前に目印を入れてある）
 *     ・パック・機材 … id[] などの並行する配列と同じ位置（int）
 *     ・料金表 … 行ごとの固有キー（string。「＋」で行を途中に足しても位置がずれないよう、位置ではなくキーで行を結ぶ）
 *   int|string = 整数か文字列のどちらでもよい（ユニオン型。PHP 8 の機能）。配列のキーとしては "3" も 3 も同じ扱いになる
 */
function studio_post_rooms(int|string $i, array $ownRoomIds): array|false
{
    $picked = array_map('intval', (array)(((array)($_POST['rooms'] ?? []))[$i] ?? []));
    if (in_array(0, $picked, true)) {
        return []; // 「全部屋」にチェックが入っていたら、部屋の指定は無し
    }
    $picked = array_values(array_unique($picked));
    return array_diff($picked, $ownRoomIds) === [] ? $picked : false; // array_diff = 自分のスタジオの部屋に無い id があるか
}

/** 親の行（料金など）に 'room_ids'（部屋 id の配列。空 = 全部屋共通）を足して返す。SQL は1回（N+1 を避ける） */
function studio_attach_rooms(PDO $pdo, string $table, int $studioId, array $rows, array $roomOrder): array
{
    [$link, $pk] = STUDIO_ROOM_LINKS[$table];
    $st = $pdo->prepare("SELECT l.$pk, l.studio_room_id FROM $link l JOIN $table t ON t.$pk = l.$pk WHERE t.studio_id = ?");
    $st->execute([$studioId]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$parent, $room]) {
        $map[(int)$parent][] = (int)$room;
    }
    foreach ($rows as &$r) { // & = 参照。ループの中で $r を書き換えると、元の配列の行が変わる
        // 部屋の並び順（画面で上に出る順）にそろえる。array_intersect は $roomOrder の順を保つ
        $r['room_ids'] = array_values(array_intersect($roomOrder, $map[(int)$r[$pk]] ?? []));
    }
    unset($r); // 参照は使い終わったら必ず解除する（残すと次のループで事故る PHP の定番の落とし穴）
    return $rows;
}

/**
 * 料金・パックの行に 'disc'（[割引区分 id => その区分の料金]。無い区分はキーごと無い）を足して返す
 *   studio_attach_rooms と同じく、SQL は1回だけ（行の数だけ SQL を投げる N+1 問題を避ける）
 */
function studio_attach_discounts(PDO $pdo, string $table, int $studioId, array $rows): array
{
    [$link, $pk] = STUDIO_DISCOUNT_LINKS[$table];
    $st = $pdo->prepare("SELECT l.$pk, l.studio_discount_id, l.price, l.kind FROM $link l JOIN $table t ON t.$pk = l.$pk WHERE t.studio_id = ?");
    $st->execute([$studioId]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$parent, $disc, $price, $kind]) {
        $map[(int)$parent][(int)$disc] = [(int)$kind, (int)$price];
    }
    foreach ($rows as &$r) {
        // disc_raw = 入力した値そのまま [区分id => [種類, 値]]（編集画面用）/ disc = 割引後の料金 [区分id => 料金]（表示・シミュレーション用）
        $r['disc_raw'] = $map[(int)$r[$pk]] ?? [];
        $r['disc'] = array_map(fn($d) => studio_disc_apply((int)$r['price'], $d[0], $d[1]), $r['disc_raw']);
    }
    unset($r);
    return $rows;
}

/**
 * フォームの割引区分ごとの料金 disc[区分id][行番号] を読む → [区分 id => 料金]（空欄の区分は入れない）
 *   false = 数字ではない値が入っている
 *   区分 id は「このスタジオの区分」だけを見る（送られてきた区分 id は信用しない）
 */
function studio_post_discounts(int $i, array $discounts): array|false
{
    $out = [];
    foreach ($discounts as $d) {
        $id = $d['studio_discount_id'];
        $v = trim((string)(((array)($_POST['disc'][$id] ?? []))[$i] ?? ''));
        if ($v === '') {
            continue; // 空欄 = その区分の割引は無い
        }
        if (!studio_uint($v, 999999)) {
            return false;
        }
        $out[$id] = (int)$v;
    }
    return $out;
}

/**
 * 部屋を削除するとき、「その部屋だけ」に紐づく料金・パック・機材を先に削除する
 *   中間テーブルの行は CASCADE で消えるが、そのままだと部屋ゼロ = 「全部屋共通」に化けてしまうため
 *   NOT EXISTS (… 別の部屋 …) = 他の部屋にも紐づいているものは残す（共通の料金は残る）
 */
function studio_delete_room_only_rows(PDO $pdo, int $studioId, int $roomId): void
{
    foreach (STUDIO_ROOM_LINKS as $table => [$link, $pk]) {
        $pdo->prepare("DELETE FROM $table WHERE studio_id = ?
                AND $pk IN (SELECT $pk FROM $link WHERE studio_room_id = ?)
                AND NOT EXISTS (SELECT 1 FROM $link o WHERE o.$pk = $table.$pk AND o.studio_room_id <> ?)")
            ->execute([$studioId, $roomId, $roomId]);
    }
}

/**
 * このスタジオの部屋の id 一覧（入力された部屋 id が「このスタジオの部屋か」を確かめるのに使う）
 *   PDO::FETCH_COLUMN = 1列目だけを配列で取る → [3, 5, 8]
 *   array_map('intval', …) = 全部を整数にする（DB からは文字列で返ることがあるため。in_array の厳密比較用）
 */
function studio_room_ids(PDO $pdo, int $studioId): array
{
    $st = $pdo->prepare('SELECT studio_room_id FROM studio_room WHERE studio_id = ?');
    $st->execute([$studioId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 行の一覧をまとめて保存する（部屋・料金・レンタル・機材で共通）
 *   $table / $pk … テーブル名と主キーの列名。★ プログラムに書いた固定の文字だけを渡すこと（利用者の入力は絶対に渡さない）
 *                  SQL の「値」は ? で安全に渡せるが、テーブル名・列名は ? にできないので、文字列でつなぐしかないため
 *   $rows … [[id, ['列名' => 値, …]], …]。値の配列が null の行は削除（id=0 なら何もしない）
 *   並び順（sort_order）は画面の上から 1, 2, 3 … と振り直す
 *
 *  ■ トランザクション
 *    beginTransaction() 〜 commit() の間の SQL は「全部成功」か「全部取り消し（rollBack）」のどちらかになる。
 *    途中でエラーが起きて「半分だけ保存された」状態を防ぐ。
 */
function studio_save_rows(PDO $pdo, string $table, string $pk, int $studioId, array $rows): void
{
    // このスタジオの行の id だけを集める（他のスタジオの行の id が送られてきても無視するため）
    $st = $pdo->prepare("SELECT $pk FROM $table WHERE studio_id = ?");
    $st->execute([$studioId]);
    $own = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    $pdo->beginTransaction();
    try {
        $order = 0;
        foreach ($rows as $row) {
            [$id, $data] = $row;
            $links = $row[2] ?? null; // 3つ目 = 部屋 id の配列（料金・パック・機材だけ。空 = 全部屋共通）
            $discs = $row[3] ?? null; // 4つ目 = [割引区分 id => 料金]（料金・パックだけ）
            if ($id !== 0 && !in_array($id, $own, true)) {
                continue; // 他のスタジオの行は触らない
            }
            if ($data === null) {
                if ($id !== 0) {
                    if ($table === 'studio_room') {
                        studio_delete_room_only_rows($pdo, $studioId, $id); // その部屋だけの料金などは先に消す
                    }
                    $pdo->prepare("DELETE FROM $table WHERE $pk = ? AND studio_id = ?")->execute([$id, $studioId]);
                }
                continue;
            }
            $data['sort_order'] = ++$order;
            $cols = array_keys($data);
            if ($id !== 0) {
                // "name = ?, price = ?, sort_order = ?" のような SET 句を列名から組み立てる
                $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
                $pdo->prepare("UPDATE $table SET $set WHERE $pk = ? AND studio_id = ?")
                    ->execute([...array_values($data), $id, $studioId]); // ... = 配列を展開して引数に並べる（スプレッド構文）
            } else {
                $data = ['studio_id' => $studioId] + $data; // + = 配列の結合（左にあるキーが優先）
                $cols = array_keys($data);
                $marks = implode(', ', array_fill(0, count($cols), '?')); // "?, ?, ?"
                $pdo->prepare("INSERT INTO $table (" . implode(', ', $cols) . ") VALUES ($marks)")
                    ->execute(array_values($data));
                $id = (int)$pdo->lastInsertId(); // 新しい行の番号（中間テーブルに入れるのに使う）
            }
            // 部屋との紐づけを「全部消して入れ直す」（件数が少ないので、差分を取るより単純で間違いにくい）
            if ($links !== null && isset(STUDIO_ROOM_LINKS[$table])) {
                [$link, $linkPk] = STUDIO_ROOM_LINKS[$table];
                $pdo->prepare("DELETE FROM $link WHERE $linkPk = ?")->execute([$id]);
                foreach ($links as $roomId) {
                    $pdo->prepare("INSERT INTO $link ($linkPk, studio_room_id) VALUES (?, ?)")->execute([$id, (int)$roomId]);
                }
            }
            // 割引区分ごとの料金も同じく「全部消して入れ直す」
            if ($discs !== null && isset(STUDIO_DISCOUNT_LINKS[$table])) {
                [$link, $linkPk] = STUDIO_DISCOUNT_LINKS[$table];
                $pdo->prepare("DELETE FROM $link WHERE $linkPk = ?")->execute([$id]);
                foreach ($discs as $discId => $disc) {
                    [$kind, $value] = is_array($disc) ? $disc : [0, $disc]; // 値だけ（パック・AI）= 金額指定 / [種類, 値]（料金表）
                    $pdo->prepare("INSERT INTO $link ($linkPk, studio_discount_id, price, kind) VALUES (?, ?, ?, ?)")->execute([$id, (int)$discId, (int)$value, (int)$kind]);
                }
            }
        }
        $pdo->prepare('UPDATE studio SET updated_by = ? WHERE studio_id = ?')->execute([(int)current_user()['user_id'], $studioId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e; // 取り消したうえで、エラーはそのまま上に投げる（エラーページが出る）
    }
}

/**
 * 行の削除ボタン（既存の行だけ。id=0 の新しい行には出さない）
 *   押すと assets/studio_info.js の setupRowDelete が、その行の主な欄（料金・名前・機種名）を空にして行を隠す
 *   → 「保存」で、主な欄が空の行として削除される（サーバー側は今までの「空にしたら削除」のまま）
 */
function studio_row_del(int $id): string
{
    return $id === 0 ? '' : '<button type="button" class="icon-btn sinfo-row-del" data-row-del title="削除" aria-label="削除">' . icon('delete') . '</button>';
}

/** 0 以上の整数の文字列か（料金・台数の入力チェック用）。ctype_digit = 全部が 0〜9 の文字か */
function studio_uint(string $s, int $max): bool
{
    return ctype_digit($s) && (int)$s <= $max;
}

// =====================================================================
//  POST
// =====================================================================
if (is_post()) {
    require_executive();
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $uid = (int)$user['user_id'];

    // ---- 基本情報 -------------------------------------------------------
    if ($action === 'save_info') {
        // ?? '' = 送られてこなかったら空文字。trim = 前後の空白を取る
        $f = [];
        foreach (['name', 'website_url', 'reserve_url', 'address', 'access', 'phone', 'student_note', 'note'] as $k) {
            $f[$k] = trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? '')));
        }
        $coords = studio_parse_coords((string)($_POST['coords'] ?? ''));
        $error = match (true) { // match(true) = 上から順に条件を見て、最初に true になった値を返す
            $f['name'] === '' || mb_strlen($f['name']) > 100 => 'スタジオ名を100文字以内で入力してください',
            !studio_url_ok($f['website_url']) => 'サイトの URL が正しくありません',
            !studio_url_ok($f['reserve_url']) => '予約ページの URL が正しくありません',
            mb_strlen($f['address']) > 255 => '住所が長すぎます',
            mb_strlen($f['access']) > 100 => '最寄り駅が長すぎます',
            $f['phone'] !== '' && !preg_match('/^[0-9０-９+\-() ]{1,30}$/u', $f['phone']) => '電話番号が正しくありません',
            $coords === false => '座標は「35.6812, 139.7671」の形で入力してください',
            mb_strlen($f['student_note']) > 500 => '割引の条件が長すぎます',
            mb_strlen($f['note']) > 1000 => 'メモが長すぎます',
            default => null,
        };
        if ($error !== null) {
            flash($error, 'error');
            redirect($self);
        }
        // 空の欄は NULL で保存（「未登録」を空文字ではなく NULL で表すのが DB の定番。IS NULL で探せる）
        $nul = fn(string $v) => $v === '' ? null : $v;
        $pdo->prepare('UPDATE studio SET name = ?, website_url = ?, reserve_url = ?, address = ?, access = ?, phone = ?,
                lat = ?, lng = ?, student_note = ?, note = ?, updated_by = ? WHERE studio_id = ?')
            ->execute([$f['name'], $nul($f['website_url']), $nul($f['reserve_url']), $nul($f['address']), $nul($f['access']),
                $nul(mb_convert_kana($f['phone'], 'n')), $coords[0], $coords[1], $nul($f['student_note']), $nul($f['note']), $uid, $studioId]);
        flash('保存しました');
        redirect($self);
    }

    // ---- 部屋 -----------------------------------------------------------
    if ($action === 'save_rooms') {
        $rows = [];
        foreach ((array)($_POST['id'] ?? []) as $i => $rawId) {
            $name = trim((string)($_POST['name'][$i] ?? ''));
            $size = trim((string)($_POST['size_note'][$i] ?? ''));
            $jo = studio_parse_jo((string)($_POST['area_jo'][$i] ?? '')); // 広さ（帖）: null = 空欄 / false = 不正
            if ($name === '') {
                $rows[] = [(int)$rawId, null]; // 部屋名が空 = 削除（その部屋だけの料金・機材も CASCADE で消える）
                continue;
            }
            if (mb_strlen($name) > 50 || mb_strlen($size) > 50) {
                flash('部屋名・備考は50文字以内で入力してください', 'error');
                redirect($self);
            }
            if ($jo === false) {
                flash('広さは 0.5〜999.9 の数字（帖）で入力してください', 'error');
                redirect($self);
            }
            $rows[] = [(int)$rawId, ['name' => $name, 'area_jo' => $jo, 'size_note' => $size === '' ? null : $size]];
        }
        studio_save_rows($pdo, 'studio_room', 'studio_room_id', $studioId, $rows);
        flash('保存しました');
        redirect($self . '#rates'); // 部屋と料金は同じカード（#rates）
    }

    // ---- 割引区分（学割・U20 …）---------------------------------------
    //   名前を空にした区分は削除 → その区分の料金（中間テーブル）も CASCADE で消える
    if ($action === 'save_discounts') {
        $rows = [];
        $names = [];
        foreach ((array)($_POST['id'] ?? []) as $i => $rawId) {
            $name = trim((string)($_POST['name'][$i] ?? ''));
            $day = (int)($_POST['day'][$i] ?? 0);
            if ($name === '') {
                $rows[] = [(int)$rawId, null];
                continue;
            }
            if (mb_strlen($name) > 30 || !array_key_exists($day, STUDIO_DAY_TYPES) || isset($names[$name])) {
                flash('区分名（30文字以内・重複なし）と曜日を確認してください', 'error');
                redirect($self . '#rates');
            }
            $names[$name] = true;
            $rows[] = [(int)$rawId, ['name' => $name, 'day_type' => $day]];
        }
        if (count($names) > STUDIO_DISCOUNT_MAX) {
            flash('割引区分は' . STUDIO_DISCOUNT_MAX . 'つまでです', 'error');
            redirect($self . '#rates');
        }
        studio_save_rows($pdo, 'studio_discount', 'studio_discount_id', $studioId, $rows);
        flash('保存しました');
        redirect($self . '#rates');
    }

    // ---- 料金表 ---------------------------------------------------------
    //   入力は「時間帯（slot[キー]）× 部屋」。時間帯ごとに 対象・曜日・開始・終了、その下に部屋ごとの 料金・割引区分ごとの割引
    //     slot[キー][people|day|start|end]
    //     slot[キー][room][部屋id][id|price]    … 部屋id 0 = 部屋が未登録のスタジオの「全部屋」。料金が空 = その部屋の行は無し（既存なら削除）
    //     slot[キー][room][部屋id][dk|dv][区分id] … 割引の種類（0 金額指定 / 1 円引き / 2 %引き）と値
    //   DB の行は「1行 = 1つの時間帯 × 1つの部屋」。新しい時間帯だけ x[キー][n][day|start|end] で同じ料金の時間帯を足せる
    if ($action === 'save_rates') {
        $anchor = '#rates';
        $roomIds = studio_room_ids($pdo, $studioId);
        $discounts = studio_discounts($pdo, $studioId);
        $fail = function (string $msg) use ($self, $anchor): never {
            flash($msg, 'error');
            redirect($self . $anchor);
        };
        $rows = [];
        foreach ((array)($_POST['slot'] ?? []) as $key => $s) {
            $key = (string)$key;
            if (!is_array($s) || preg_match('/^\w{1,20}$/', $key) !== 1) {
                continue; // 形の違う値（改ざん）は無視
            }
            $people = (int)($s['people'] ?? 0);   // 0 = バンド練習
            $day = (int)($s['day'] ?? 0);
            $start = (string)($s['start'] ?? '');
            $end = (string)($s['end'] ?? '');
            $timeOk = $people >= 0 && $people <= STUDIO_SOLO_MAX && array_key_exists($day, STUDIO_DAY_TYPES)
                && studio_uint($start, STUDIO_MAX_MIN) && studio_uint($end, STUDIO_MAX_MIN) && (int)$start < (int)$end;
            $slotRows = []; // この時間帯の、料金が入った部屋の行
            $hasExisting = false;
            foreach ((array)($s['room'] ?? []) as $rid => $line) {
                $rid = (string)$rid;
                if (!is_array($line) || !ctype_digit($rid)) {
                    continue;
                }
                $rid = (int)$rid;
                $id = (int)($line['id'] ?? 0);
                $price = trim((string)($line['price'] ?? ''));
                if ($price === '') {
                    if ($id !== 0) {
                        $rows[] = [$id, null]; // 料金が空 = 削除
                    }
                    continue;
                }
                // 部屋は「このスタジオの部屋」だけ（0 は部屋が未登録のときだけ）。送られてきた id は信用しない
                if (!$timeOk || !studio_uint($price, 999999) || !($rid === 0 ? !$roomIds : in_array($rid, $roomIds, true))) {
                    $fail('料金表の入力が正しくありません（終了は開始より後に）');
                }
                $disc = [];
                foreach ($discounts as $d) {
                    $did = $d['studio_discount_id'];
                    $v = trim((string)($line['dv'][$did] ?? ''));
                    if ($v === '') {
                        continue; // 空欄 = その区分の割引は無い
                    }
                    $kind = (string)($line['dk'][$did] ?? '0');
                    if (!ctype_digit($kind) || !isset(STUDIO_DISC_KINDS[(int)$kind]) || !studio_uint($v, (int)$kind === 2 ? 100 : 999999)) {
                        $fail('割引の入力が正しくありません（％引きは100まで）');
                    }
                    $disc[$did] = [(int)$kind, (int)$v];
                }
                $hasExisting = $hasExisting || $id !== 0;
                $slotRows[] = [$id, [
                    'people' => $people === 0 ? null : $people, // NULL = バンド練習
                    'day_type' => $day, 'start_min' => (int)$start, 'end_min' => (int)$end, 'price' => (int)$price,
                ], $rid === 0 ? [] : [$rid], $disc];
            }
            foreach ($slotRows as $row) {
                $rows[] = $row;
            }
            // 新しい時間帯だけ「追加の時間帯」（曜日 + 開始〜終了）を足せる。同じ料金・割引を時間帯ごとに作る
            //   例: 平日 18〜23時 + 土日祝 終日 を同じ料金で1回の入力にする
            if ($slotRows && !$hasExisting) {
                foreach ((array)($_POST['x'][$key] ?? []) as $x) {
                    if (!is_array($x) || (string)($x['day'] ?? '') === '') {
                        continue; // 未入力の欄
                    }
                    $xd = (string)$x['day'];
                    $xs = (string)($x['start'] ?? '');
                    $xe = (string)($x['end'] ?? '');
                    if (!ctype_digit($xd) || !array_key_exists((int)$xd, STUDIO_DAY_TYPES)
                        || !studio_uint($xs, STUDIO_MAX_MIN) || !studio_uint($xe, STUDIO_MAX_MIN) || (int)$xs >= (int)$xe) {
                        $fail('追加の時間帯が正しくありません（終了は開始より後に）');
                    }
                    foreach ($slotRows as [, $data, $rowRooms, $disc]) {
                        $rows[] = [0, ['day_type' => (int)$xd, 'start_min' => (int)$xs, 'end_min' => (int)$xe] + $data, $rowRooms, $disc];
                    }
                }
            }
        }
        studio_save_rows($pdo, 'studio_rate', 'studio_rate_id', $studioId, $rows);
        flash('保存しました');
        redirect($self . $anchor);
    }

    // ---- パック ---------------------------------------------------------
    //   対象（バンド練習 / 個人n人）・曜日・時間帯・料金・割引区分ごとの料金
    //   部屋: 複数の部屋に共通にできる（空 = 全部屋共通）。rooms[行番号][]
    //   名前・使える時間（minutes。空 = 時間帯まるごと）
    if ($action === 'save_packs') {
        $anchor = '#packs';
        $roomIds = studio_room_ids($pdo, $studioId);
        $discounts = studio_discounts($pdo, $studioId);
        $rows = [];
        foreach ((array)($_POST['id'] ?? []) as $i => $rawId) {
            $price = trim((string)($_POST['price'][$i] ?? ''));
            if ($price === '') {
                $rows[] = [(int)$rawId, null]; // 料金が空 = 削除
                continue;
            }
            $rowRooms = studio_post_rooms($i, $roomIds); // [] = 全部屋共通 / false = 不正
            $people = (int)($_POST['people'][$i] ?? 0);   // 0 = バンド
            $day = (int)($_POST['day'][$i] ?? 0);
            $start = (string)($_POST['start'][$i] ?? '');
            $end = (string)($_POST['end'][$i] ?? '');
            $disc = studio_post_discounts($i, $discounts); // [区分id => 料金] / false = 不正
            $ok = $rowRooms !== false && $disc !== false
                && $people >= 0 && $people <= STUDIO_SOLO_MAX
                && array_key_exists($day, STUDIO_DAY_TYPES)
                && studio_uint($start, STUDIO_MAX_MIN) && studio_uint($end, STUDIO_MAX_MIN) && (int)$start < (int)$end
                && studio_uint($price, 999999);
            $data = [
                'people' => $people === 0 ? null : $people, // NULL = バンド
                'day_type' => $day,
                'start_min' => (int)$start,
                'end_min' => (int)$end,
                'price' => (int)$price,
            ];
            $name = trim((string)($_POST['name'][$i] ?? ''));
            $minutes = trim((string)($_POST['minutes'][$i] ?? ''));
            // 使える時間は 30分きざみで、時間帯の長さ以内（時間帯より長いパックはありえない）
            $ok = $ok && $name !== '' && mb_strlen($name) <= 50
                && ($minutes === '' || (studio_uint($minutes, STUDIO_MAX_MIN) && (int)$minutes > 0 && (int)$minutes <= (int)$end - (int)$start));
            $data = ['name' => $name] + $data + ['minutes' => $minutes === '' ? null : (int)$minutes];
            if (!$ok) {
                flash('パックの入力が正しくありません（名前・時間帯・使える時間を確認）', 'error');
                redirect($self . $anchor);
            }
            $rows[] = [(int)$rawId, $data, $rowRooms, $disc];
        }
        studio_save_rows($pdo, 'studio_pack', 'studio_pack_id', $studioId, $rows);
        flash('保存しました');
        redirect($self . $anchor);
    }

    // ---- レンタル -------------------------------------------------------
    if ($action === 'save_rentals') {
        $rows = [];
        foreach ((array)($_POST['id'] ?? []) as $i => $rawId) {
            $name = trim((string)($_POST['name'][$i] ?? ''));
            $price = trim((string)($_POST['price'][$i] ?? ''));
            if ($name === '') {
                $rows[] = [(int)$rawId, null];
                continue;
            }
            if (mb_strlen($name) > 100 || !studio_uint($price, 999999)) {
                flash('レンタルの名前・料金が正しくありません', 'error');
                redirect($self . '#rentals');
            }
            $rows[] = [(int)$rawId, [
                'name' => $name,
                'price' => (int)$price,
                'per_hour' => ($_POST['per_hour'][$i] ?? '0') === '1' ? 1 : 0,
            ]];
        }
        studio_save_rows($pdo, 'studio_rental', 'studio_rental_id', $studioId, $rows);
        flash('保存しました');
        redirect($self . '#rentals');
    }

    // ---- 機材 -----------------------------------------------------------
    if ($action === 'save_equipment') {
        $roomIds = studio_room_ids($pdo, $studioId);
        $rows = [];
        foreach ((array)($_POST['id'] ?? []) as $i => $rawId) {
            $model = trim((string)($_POST['model'][$i] ?? ''));
            if ($model === '') {
                $rows[] = [(int)$rawId, null];
                continue;
            }
            $rowRooms = studio_post_rooms($i, $roomIds);
            $cat = (string)($_POST['category'][$i] ?? '');
            $q = trim((string)($_POST['quantity'][$i] ?? ''));
            if ($rowRooms === false || !in_array($cat, STUDIO_EQUIP_CATEGORIES, true)
                || mb_strlen($model) > 100 || !studio_uint($q, 99)) {
                flash('区分・機種名・台数が正しくありません', 'error');
                redirect($self . '#equipment');
            }
            $rows[] = [(int)$rawId, [
                'category' => $cat,
                'model_name' => $model,
                'quantity' => (int)$q,
            ], $rowRooms];
        }
        studio_save_rows($pdo, 'studio_equipment', 'studio_equipment_id', $studioId, $rows);
        flash('保存しました');
        redirect($self . '#equipment');
    }

    // ---- AI の提案を反映（提案は api_studio_ai.php がセッションに置いたもの。画面から来るのは「どの項目を使うか」だけ）----
    if ($action === 'apply_ai') {
        $prop = $_SESSION['studio_ai'][$studioId] ?? null;
        // array_intersect = 2つの配列の両方にある値だけ残す → 決められた項目名以外は捨てる
        $sections = array_values(array_intersect(array_keys(STUDIO_AI_SECTIONS), (array)($_POST['sections'] ?? [])));
        if (!is_array($prop)) {
            flash('AI の結果が見つかりません。もう一度「AIで入力」してください', 'error');
        } elseif (!$sections) {
            flash('反映する項目を選んでください', 'error');
        } else {
            studio_ai_apply($pdo, $studioId, $prop, $sections, $uid);
            unset($_SESSION['studio_ai'][$studioId]); // 使い終わった提案は捨てる（二重に反映しないように）
            flash('反映しました');
        }
        redirect($self);
    }

    // ---- スタジオの削除（部屋・料金・レンタル・機材は外部キーの CASCADE で一緒に消える）----
    if ($action === 'delete_studio') {
        $pdo->prepare('DELETE FROM studio WHERE studio_id = ?')->execute([$studioId]);
        flash("「{$studio['name']}」を削除しました");
        redirect('studio_list');
    }

    error_page(400, '不正なリクエストです');
}

// =====================================================================
//  表示用のデータ
// =====================================================================
$st = $pdo->prepare('SELECT studio_room_id, name, area_jo, size_note FROM studio_room WHERE studio_id = ? ORDER BY sort_order, studio_room_id');
$st->execute([$studioId]);
$rooms = $st->fetchAll();
$roomName = array_column($rooms, 'name', 'studio_room_id'); // [部屋id => 部屋名]（array_column の第3引数 = キーにする列）
// 部屋 id => 見出し・プルダウンに添える文字（広さ「12帖」＋備考「最大8人」）。どちらも無ければ ''
//   料金シミュレーションの部屋プルダウンと、部屋・料金カードの見出しの両方で使うので、表示用データのここで作る
$roomSize = [];
foreach ($rooms as $r) {
    $roomSize[(int)$r['studio_room_id']] = trim(studio_fmt_jo($r['area_jo']) . ($r['size_note'] ? ' ' . $r['size_note'] : ''));
}

// 料金表: 表示は「バンド → 個人1人 → 個人2人 …」の順にしたいので、people の NULL を先頭に並べる
//   ORDER BY people IS NULL DESC = 「NULL か？」が真（1）の行を先に。そのあと人数の小さい順
//   さらに 曜日（全日 → 平日 → 土日祝）→ 開始時刻 の順。編集画面の「対象・曜日」の見出しの並びと同じにする
$st = $pdo->prepare('SELECT studio_rate_id, people, day_type, start_min, end_min, price
    FROM studio_rate WHERE studio_id = ? ORDER BY people IS NULL DESC, people, day_type, start_min, sort_order, studio_rate_id');
$st->execute([$studioId]);
$rates = studio_attach_rooms($pdo, 'studio_rate', $studioId, $st->fetchAll(), array_keys($roomName));
$rates = studio_attach_discounts($pdo, 'studio_rate', $studioId, $rates);

$st = $pdo->prepare('SELECT studio_pack_id, people, name, day_type, start_min, end_min, minutes, price
    FROM studio_pack WHERE studio_id = ? ORDER BY sort_order, studio_pack_id');
$st->execute([$studioId]);
$packs = studio_attach_rooms($pdo, 'studio_pack', $studioId, $st->fetchAll(), array_keys($roomName));
$packs = studio_attach_discounts($pdo, 'studio_pack', $studioId, $packs);

// 割引区分（学割・U20 …）。$discName = [区分id => 名前]
$discounts = studio_discounts($pdo, $studioId);
$discName = array_column($discounts, 'name', 'studio_discount_id');

$st = $pdo->prepare('SELECT studio_rental_id, name, price, per_hour FROM studio_rental WHERE studio_id = ? ORDER BY sort_order, studio_rental_id');
$st->execute([$studioId]);
$rentals = $st->fetchAll();

$st = $pdo->prepare('SELECT studio_equipment_id, category, model_name, quantity
    FROM studio_equipment WHERE studio_id = ? ORDER BY sort_order, studio_equipment_id');
$st->execute([$studioId]);
$equipment = studio_attach_rooms($pdo, 'studio_equipment', $studioId, $st->fetchAll(), array_keys($roomName));

// 機材を「部屋（0 = 共通）→ 区分」の2段に分ける。??= は「まだ無ければ入れる」
$equipByRoom = [];
foreach ($equipment as $e) {
    // 複数の部屋に共通なら、その部屋それぞれの下に出す（room_ids が空 = 0 = 全部屋共通）
    foreach ($e['room_ids'] ?: [0] as $rid) {
        $equipByRoom[$rid][$e['category']][] = $e;
    }
}

// 料金表を「どの部屋に共通か（部屋の組み合わせ）」でまとめる。1つの料金は必ず1つのグループにだけ入る（部屋ごとに重複して出さない）
//   キー = 部屋 id をカンマでつないだ文字（例: A と B に共通 → '3,5' / 全部屋共通 → '0'）
//   'rooms' = その組み合わせの部屋 id の配列（空 = 全部屋共通）/ 'rows' = その料金の行
$rateGroups = [];
foreach ($rates as $r) {
    $key = $r['room_ids'] ? implode(',', $r['room_ids']) : '0';
    $rateGroups[$key]['rooms'] = $r['room_ids'];
    $rateGroups[$key]['rows'][] = $r;
}
// 自分専用の料金が無い部屋も、広さを見せたいので「見出しだけのグループ」を足す
//   $covered = どれかのグループに入っている部屋 id（array_merge([], ...配列の配列) = 全部をつなげて1つの配列にする）
$covered = array_merge([], ...array_column($rateGroups, 'rooms'));
foreach (array_keys($roomName) as $rid) {
    if (!in_array($rid, $covered, true)) {
        $rateGroups[(string)$rid] = ['rooms' => [$rid], 'rows' => []];
    }
}
// 並び順: 全部屋共通を先頭 → あとは「いちばん上に出る部屋」の順（$roomName は画面の並び順）→ 同じなら部屋の数が少ない方が先
//   uasort = キーを保ったまま、比べる関数で並べ替える / <=> = 小さければ -1・同じなら 0・大きければ 1 を返す（宇宙船演算子）
//   a ?: b = a が 0（同じ）のときだけ b で比べる
$roomPos = array_flip(array_keys($roomName)); // [部屋id => 何番目か]
uasort($rateGroups, fn($a, $b) =>
    ($a['rooms'] ? $roomPos[$a['rooms'][0]] : -1) <=> ($b['rooms'] ? $roomPos[$b['rooms'][0]] : -1)
    ?: count($a['rooms']) <=> count($b['rooms']));
// 料金が1つでも入っている割引区分の id（料金表の列・シミュレーションの選択肢に出すのはこれだけ）
//   array_keys(…'disc') = 各行の「料金がある区分の id」。array_merge(...) で全部の行をまとめる（... = 配列を引数に展開）
$rateDiscIds = array_unique(array_merge([], ...array_map(fn($r) => array_keys($r['disc']), $rates)));
$packDiscIds = array_unique(array_merge([], ...array_map(fn($r) => array_keys($r['disc']), $packs)));
$usedDisc = array_values(array_filter($discounts, fn($d) => in_array($d['studio_discount_id'], [...$rateDiscIds, ...$packDiscIds], true)));
$rateDisc = array_values(array_filter($discounts, fn($d) => in_array($d['studio_discount_id'], $rateDiscIds, true))); // 料金表の列
$hasStudent = $usedDisc !== []; // 割引があるスタジオか（区分の条件・シミュレーションの選択を出す）
// 個人練習の料金があるか（あれば料金表に「対象」の列と、シミュレーションに「バンド / 個人」の切り替えを出す）
$hasSolo = array_filter(array_merge(array_column($rates, 'people'), array_column($packs, 'people')), fn($v) => $v !== null) !== [];
$hasBand = in_array(null, array_column($rates, 'people'), true) || in_array(null, array_column($packs, 'people'), true);
// バンドと個人練習の「両方」の料金があるときだけ、タブで分ける（片方だけなら分ける必要が無いのでタブは出さない）
//   タブは 料金表・パック をまとめて切り替える（JS: setupTargetTabs）。料金シミュレーションには専用の切り替えがあり、タブとは連動しない
//   料金表・パックの各要素に data-tab-panel="band|solo" を付け、選ばれていない方を hidden で隠す
$hasTabs = $hasSolo && $hasBand;
// 土曜と日祝で料金（パック・割引の使える曜日も）が分かれているか → 分かれていれば、シミュレーションの曜日ボタンを「平日 / 土 / 日祝」の3択にする
//   day_type: 3 = 土 / 4 = 日祝（lib/studio_info.php の STUDIO_DAY_TYPES）。分かれていなければ今まで通り「平日 / 土日祝」の2択
//   array_column(配列, 'キー') = 配列の各要素から、そのキーの値だけを集める
$splitWeekend = array_filter(
    array_merge(array_column($rates, 'day_type'), array_column($packs, 'day_type'), array_column($usedDisc, 'day_type')),
    fn($d) => in_array((int)$d, [3, 4], true)
) !== [];
// その対象（'band' / 'solo'）の料金表の行だけに絞る。people が NULL = バンド、数字 = 個人n人
//   fn($r) => … = アロー関数（短い無名関数）。array_filter は true を返した要素だけ残す / array_values は添字を 0 から振り直す
$rowsOfTarget = fn(array $rows, string $target): array => array_values(array_filter(
    $rows,
    fn($r) => ($r['people'] === null) === ($target === 'band')
));

/** パックの「使える時間」の表示（NULL = 時間帯まるごと → 時間帯の長さを出す） */
$packLen = fn(array $p) => studio_fmt_min((int)($p['minutes'] ?? ((int)$p['end_min'] - (int)$p['start_min'])));

// 料金シミュレーション用のデータ（JS へ渡す）。キーは短く、値は数値にしておく
//   dp = 割引区分ごとの料金 {区分id: 料金}。(object) にするのは、空のときに [] ではなく {} として JSON にするため
//        （PHP の空配列は json_encode で [] になる。JS では r.dp[3] がどちらでも undefined なので動くが、形をそろえておく）
$simData = [
    'discounts' => array_map(fn($d) => ['id' => $d['studio_discount_id'], 'name' => $d['name'], 'day' => $d['day_type']], $usedDisc),
    'rooms' => array_map(fn($r) => ['id' => (int)$r['studio_room_id'], 'name' => $r['name']], $rooms),
    'rates' => array_map(fn($r) => [
        'rooms' => $r['room_ids'], // 空 = 全部屋共通
        'ppl' => $r['people'] === null ? 0 : (int)$r['people'], // 0 = バンド
        'day' => (int)$r['day_type'],
        's' => (int)$r['start_min'],
        'e' => (int)$r['end_min'],
        'p' => (int)$r['price'],
        'dp' => (object)$r['disc'],
    ], $rates),
    'packs' => array_map(fn($r) => [
        'name' => $r['name'],
        'rooms' => $r['room_ids'],
        'ppl' => $r['people'] === null ? 0 : (int)$r['people'],
        'day' => (int)$r['day_type'],
        's' => (int)$r['start_min'],
        'e' => (int)$r['end_min'],
        'min' => $r['minutes'] === null ? null : (int)$r['minutes'],
        'p' => (int)$r['price'],
        'dp' => (object)$r['disc'],
    ], $packs),
    'rentals' => array_map(fn($r) => [
        'id' => (int)$r['studio_rental_id'], 'name' => $r['name'], 'p' => (int)$r['price'], 'hour' => (bool)$r['per_hour'],
    ], $rentals),
];

// 地図: 座標があれば Leaflet でピン1本。Google マップのリンクは「経路検索」に使える（座標が無ければ住所 → 名前で検索）
$hasPin = $studio['lat'] !== null && $studio['lng'] !== null;
$gmapQuery = $hasPin ? $studio['lat'] . ',' . $studio['lng'] : (string)($studio['address'] ?: $studio['name']);
$gmapUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($gmapQuery);
$coordsValue = $hasPin ? rtrim(rtrim((string)$studio['lat'], '0'), '.') . ', ' . rtrim(rtrim((string)$studio['lng'], '0'), '.') : '';

/**
 * 部屋のチェック欄（行ごと）。「全部屋」と各部屋のチェックボックス。チェックなし・「全部屋」= 全部屋共通
 *   name="rooms[目印][]"（チェックを外した欄は送られてこないので、目印を名前に入れて、どの行の選択か分かるようにする）
 *     目印 = パック・機材は行番号 / 料金表は行ごとの固有キー（studio_post_rooms の説明を参照）
 *   「全部屋」と個別の部屋は同時に選べない（JS の setupRoomChips が切り替える。JS が無くてもサーバー側で「全部屋」を優先）
 */
$roomChips = function (int|string $i, array $selected) use ($rooms): string {
    if (!$rooms) {
        return ''; // 部屋が未登録なら選ぶものが無い
    }
    $all = $selected === [];
    $html = '<span class="sinfo-chips" role="group" aria-label="部屋">'
        . '<label class="sinfo-chip"><input type="checkbox" name="rooms[' . h((string)$i) . '][]" value="0" data-room-all' . ($all ? ' checked' : '') . '> 全部屋</label>';
    foreach ($rooms as $r) {
        $id = (int)$r['studio_room_id'];
        $html .= '<label class="sinfo-chip"><input type="checkbox" name="rooms[' . h((string)$i) . '][]" value="' . $id . '"'
            . (in_array($id, $selected, true) ? ' checked' : '') . '> ' . h($r['name']) . '</label>';
    }
    return $html . '</span>';
};

/**
 * 割引区分ごとの料金の入力欄（料金表・パック共通）。name="disc[区分id][]" は id[] などと同じ並びの配列になる
 *   number の欄は空でも必ず送られるので、行の位置（何番目か）が id[] とずれない
 */
$discInputs = function (array $disc, string $unit) use ($discounts): string {
    $html = '';
    foreach ($discounts as $d) {
        $id = $d['studio_discount_id'];
        $html .= '<input type="number" name="disc[' . $id . '][]" min="0" max="999999" value="' . h($disc[$id] ?? '') . '"'
            . ' placeholder="' . h($d['name']) . '" aria-label="' . h($d['name'] . 'の' . $unit) . '" inputmode="numeric">';
    }
    return $html;
};

/**
 * 新しい見出しの行の「曜日・開始・終了」1行分（曜日と時間をここ1か所にまとめる）
 *   $names = [曜日, 開始, 終了] の name。1行目は day[] / start[] / end[]、追加の行は x[キー][n][day|start|end]
 *   追加の行（$removable）には × を付ける。× で行ごと消す = 送られない
 */
$slotLine = function (array $names, int $day, int $start, int $end, bool $removable): string {
    $days = '';
    foreach (STUDIO_DAY_TYPES as $k => $label) {
        $days .= '<option value="' . $k . '"' . ($k === $day ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return '<span class="sinfo-rate-row__slot">'
        . '<select name="' . h($names[0]) . '" aria-label="曜日">' . $days . '</select>'
        . '<select name="' . h($names[1]) . '" aria-label="開始" data-native>' . studio_time_options($start) . '</select>'
        . '<select name="' . h($names[2]) . '" aria-label="終了" data-native>' . studio_time_options($end) . '</select>'
        . ($removable ? '<button type="button" class="icon-btn sinfo-row-del" data-slot-del title="削除" aria-label="削除">' . icon('close') . '</button>' : '<span></span>')
        . '</span>';
};

/**
 * 料金表の編集の1つの「時間帯」（HTML）。時間帯の下に、部屋ごとの 料金・割引区分ごとの割引 が並ぶ
 *   $slot = ['people', 'day_type', 'start_min', 'end_min', 'lines' => [部屋id => ['id' => 料金の行 id, 'price' => 料金, 'disc_raw' => [区分id => [種類, 値]]]]]
 *   $fixed = true  … 見出し（対象・曜日）の下の時間帯。対象・曜日は見出しで決まるので hidden
 *   $fixed = false … 新しい見出しを作る時間帯。対象・曜日を選ぶ。「＋ 時間帯」で同じ料金の時間帯を足せる
 *   $key = この時間帯の固有キー。name="slot[キー][…]"。「＋」で途中に足しても位置がずれないよう、位置ではなくキーで結ぶ
 *          既存 = 'e' + 先頭の料金の id / 新しい見出し = 'n1' / <template> の中 = '__K__'（JS が複製のときに本物のキーに置き換える）
 *   部屋の行は $roomLines（[[部屋id, 名前], …]。部屋が未登録なら「全部屋」の1行 = 部屋id 0）
 *   割引は区分ごとに「種類（金額 / 円引 / ％引）＋ 値」。値が空 = その区分の割引は無い
 */
$rateRow = function (array $slot, string $key, bool $fixed, array $roomLines) use ($discounts, $slotLine): string {
    $people = $slot['people'] === null ? 0 : (int)$slot['people'];
    $n = 'slot[' . h($key) . ']';
    $html = '<div class="sinfo-slot-edit' . ($fixed ? '' : ' sinfo-slot-edit--new') . '" data-key="' . h($key) . '">';
    $existing = false;
    foreach ($slot['lines'] as $l) {
        $existing = $existing || (int)$l['id'] !== 0;
    }
    if ($fixed) {
        $html .= '<input type="hidden" name="' . $n . '[people]" value="' . $people . '">'
            . '<input type="hidden" name="' . $n . '[day]" value="' . (int)$slot['day_type'] . '">'
            // 時刻は data-native = 独自の選択 UI に変えず、ブラウザ標準のプルダウン（スマホではドラム式）にする。
            //   選択肢が 61 個（30分きざみ）と多く、細いマスでも文字が切れないため
            . '<div class="sinfo-slot-edit__time">'
            . '<select name="' . $n . '[start]" aria-label="開始" data-native>' . studio_time_options((int)$slot['start_min']) . '</select>'
            . '<span class="muted">〜</span>'
            . '<select name="' . $n . '[end]" aria-label="終了" data-native>' . studio_time_options((int)$slot['end_min']) . '</select>'
            . ($existing ? '<button type="button" class="icon-btn sinfo-row-del" data-row-del title="削除" aria-label="削除">' . icon('delete') . '</button>' : '')
            . '</div>';
    } else {
        // 新しい見出しの時間帯だけ、先頭に「対象」の選択を出す。曜日・開始・終了は1か所にまとめる
        $html .= '<span class="sinfo-slot-edit__pick">' . icon('add') . '<select name="' . $n . '[people]" aria-label="対象">' . studio_target_options($people ?: null) . '</select></span>'
            . '<div class="sinfo-slot-edit__slots" data-slots>'
            . $slotLine([$n . '[day]', $n . '[start]', $n . '[end]'], (int)$slot['day_type'], (int)$slot['start_min'], (int)$slot['end_min'], false)
            . '<button type="button" class="btn btn--sm sinfo-slot-edit__slot-add" data-slot-add>' . icon('add') . ' 時間帯</button>'
            . '</div>';
    }
    foreach ($roomLines as [$rid, $rname]) {
        $line = $slot['lines'][$rid] ?? ['id' => 0, 'price' => '', 'disc_raw' => []];
        $ln = $n . '[room][' . (int)$rid . ']';
        $html .= '<div class="sinfo-rate-room">'
            . '<input type="hidden" name="' . $ln . '[id]" value="' . (int)$line['id'] . '">'
            . '<span class="sinfo-rate-room__name">' . h($rname) . '</span>'
            . '<input type="number" name="' . $ln . '[price]" min="0" max="999999" value="' . h($line['price']) . '" placeholder="1時間" aria-label="' . h($rname) . 'の1時間の料金" inputmode="numeric" data-price>';
        foreach ($discounts as $d) {
            $did = $d['studio_discount_id'];
            [$kind, $value] = $line['disc_raw'][$did] ?? [0, ''];
            $kinds = '';
            foreach (STUDIO_DISC_KINDS as $k => $label) {
                $kinds .= '<option value="' . $k . '"' . ($k === (int)$kind ? ' selected' : '') . '>' . h($label) . '</option>';
            }
            $html .= '<span class="sinfo-disc-cell"><span class="sinfo-disc-cell__name muted small">' . h($d['name']) . '</span><select name="' . $ln . '[dk][' . $did . ']" aria-label="' . h($d['name']) . 'の種類" data-native>' . $kinds . '</select>'
                . '<input type="number" name="' . $ln . '[dv][' . $did . ']" min="0" max="999999" value="' . h($value) . '" placeholder="' . h($d['name']) . '" aria-label="' . h($d['name'] . '（' . $rname . '）') . '" inputmode="numeric"></span>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
};

render_header($studio['name'], 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/vendor/leaflet/leaflet.css') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/studio_info.css') ?>">
<nav class="crumbs"><a href="tools">ツール</a> / <a href="studio_list">スタジオ情報</a></nav>
<h1 class="display display--sm"><?= h($studio['name']) ?></h1>

<!-- ページ内ジャンプ（縦に長いので、各カードへ一発で飛べる。画面上部に貼り付く） -->
<nav class="sinfo-jump" aria-label="ページ内移動">
    <?php if ($rates || $packs): ?><a href="#sim"><?= icon('calculate') ?> 試算</a><?php endif; ?>
    <a href="#rates"><?= icon('meeting_room') ?> 料金</a>
    <?php if ($packs || $canEdit): ?><a href="#packs"><?= icon('inventory_2') ?> パック</a><?php endif; ?>
    <a href="#rentals"><?= icon('mic') ?> レンタル</a>
    <a href="#equipment"><?= icon('speaker') ?> 機材</a>
</nav>

<?php if ($canEdit && claude_enabled()): ?>
<!-- ===== ✨AIで入力（幹部と管理者。結果のプレビューは JS が #studio-ai-result に作る） ===== -->
<section class="card sinfo-section sinfo-ai" id="studio-ai" data-studio-id="<?= $studioId ?>" data-csrf="<?= h(csrf_token()) ?>" data-max-images="<?= AI_MAX_IMAGES ?>">
    <div class="sinfo-ai__head">
        <button type="button" class="btn btn--sm" data-ai-run><?= icon('auto_awesome') ?> AIで入力</button>
        <!-- モデル（Opus = 高精度・高め / Sonnet = 標準 / Haiku = 安い）。初期は config.php の設定値。このボックスは編集できる人（幹部・管理者）にしか出ない -->
        <select data-ai-model aria-label="モデル" title="モデル">
            <?php foreach (studio_ai_models() as $mid => $mname): ?>
                <option value="<?= h($mid) ?>"<?= $mid === studio_ai_default_model() ? ' selected' : '' ?>><?= h($mname) ?></option>
            <?php endforeach; ?>
        </select>
        <!-- 画像（料金表のスクリーンショット・パンフレットの写真など）。選ぶ・貼り付け（Ctrl+V）で最大 <?= AI_MAX_IMAGES ?> 枚。付けると画像だけを読む（ウェブ検索なし） -->
        <button type="button" class="btn btn--sm" data-ai-pick title="画像を追加（貼り付けもOK）"><?= icon('add_photo_alternate') ?></button>
        <input type="file" accept="image/*" multiple hidden data-ai-files>
        <span class="muted small" data-ai-status></span>
    </div>
    <div class="sinfo-ai__thumbs" data-ai-thumbs hidden></div>
    <form method="post" class="sinfo-ai__result" id="studio-ai-result" hidden
        data-confirm="選んだ項目を AI の結果で置き換えます（料金・パック・レンタル・機材は今の行が削除されます）。よろしいですか？">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="apply_ai">
        <input type="hidden" name="studio_id" value="<?= $studioId ?>">
        <div data-ai-body></div>
        <div><button type="submit" class="btn btn--primary btn--sm"><?= icon('download_done') ?> 選んだ項目を反映</button></div>
    </form>
</section>
<?php endif; ?>

<!-- ===== 基本情報 ===== -->
<section class="card sinfo-section">
    <div class="sinfo-links">
        <?php if ($studio['website_url']): ?>
            <a class="btn btn--sm" href="<?= h($studio['website_url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('language') ?> サイト</a>
        <?php endif; ?>
        <?php if ($studio['phone']): ?>
            <!-- tel: リンク = スマホで押すと電話アプリが開く -->
            <a class="btn btn--sm" href="tel:<?= h(preg_replace('/[^0-9+]/', '', $studio['phone'])) ?>"><?= icon('call') ?> <?= h($studio['phone']) ?></a>
        <?php endif; ?>
    </div>
    <!-- PC は左に文字情報・右に地図の2列、スマホは縦に積む -->
    <div class="sinfo-basic<?= $hasPin ? ' sinfo-basic--map' : '' ?>">
        <div class="sinfo-basic__text">
            <p class="sinfo-info">
                <?php if ($studio['address']): ?><span><?= icon('location_on') ?> <?= h($studio['address']) ?></span><?php endif; ?>
                <?php if ($studio['access']): ?><span><?= icon('train') ?> <?= h($studio['access']) ?></span><?php endif; ?>
            </p>
            <?php if ($studio['note']): ?><p class="sinfo-note"><?= nl2br(h($studio['note'])) ?></p><?php endif; ?>
            <a class="small" href="<?= h($gmapUrl) ?>" target="_blank" rel="noopener noreferrer"><?= icon('directions') ?> Googleマップで開く</a>
        </div>
        <?php if ($hasPin): ?>
            <div class="sinfo-map sinfo-map--one" id="studio-map" role="region" aria-label="地図"></div>
            <script type="application/json" id="studio-pins"><?= json_encode([[
                'id' => $studioId, 'name' => $studio['name'], 'lat' => (float)$studio['lat'], 'lng' => (float)$studio['lng'], 'sub' => $studio['access'] ?? '',
            ]], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        <?php endif; ?>
    </div>

    <?php if ($canEdit): ?>
    <details class="sinfo-edit">
        <summary><?= icon('edit') ?> 編集</summary>
        <form method="post" class="sinfo-edit__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_info">
            <input type="hidden" name="studio_id" value="<?= $studioId ?>">
            <label class="sinfo-field"><span>スタジオ名</span>
                <input autocomplete="off" type="text" name="name" maxlength="100" required value="<?= h($studio['name']) ?>"></label>
            <label class="sinfo-field"><span>予約ページ</span>
                <input autocomplete="off" type="url" name="reserve_url" maxlength="255" value="<?= h($studio['reserve_url']) ?>" placeholder="https://"></label>
            <label class="sinfo-field"><span>サイト</span>
                <input autocomplete="off" type="url" name="website_url" maxlength="255" value="<?= h($studio['website_url']) ?>" placeholder="https://"></label>
            <label class="sinfo-field"><span>住所</span>
                <input autocomplete="off" type="text" name="address" maxlength="255" value="<?= h($studio['address']) ?>" data-geo-address></label>
            <label class="sinfo-field"><span>座標</span>
                <span class="sinfo-edit__row">
                    <input autocomplete="off" type="text" class="grow" name="coords" maxlength="60" value="<?= h($coordsValue) ?>" placeholder="35.6812, 139.7671" inputmode="decimal" data-geo-coords>
                    <!-- 住所から座標を取る（国土地理院の住所検索 API。JS: assets/studio_info.js） -->
                    <button type="button" class="btn btn--sm" data-geo-lookup title="住所から取得"><?= icon('my_location') ?></button>
                </span></label>
            <label class="sinfo-field"><span>最寄り駅</span>
                <input autocomplete="off" type="text" name="access" maxlength="100" value="<?= h($studio['access']) ?>" placeholder="立川駅 北口 徒歩5分"></label>
            <label class="sinfo-field"><span>電話</span>
                <input autocomplete="off" type="tel" name="phone" maxlength="30" value="<?= h($studio['phone']) ?>"></label>
            <label class="sinfo-field"><span>割引の条件</span>
                <textarea name="student_note" rows="2" maxlength="500" placeholder="学生証の提示が必要 など"><?= h($studio['student_note']) ?></textarea></label>
            <label class="sinfo-field"><span>メモ</span>
                <textarea name="note" rows="3" maxlength="1000"><?= h($studio['note']) ?></textarea></label>
            <div><button type="submit" class="btn btn--primary btn--sm"><?= icon('save') ?> 保存</button></div>
        </form>
    </details>
    <?php endif; ?>
</section>

<!-- ===== 料金シミュレーション（料金表が1行でもあれば出す。中身は JS が作る） ===== -->
<?php if ($rates || $packs): ?>
<section class="card sinfo-section" id="sim">
    <h2><?= icon('calculate') ?> 料金シミュレーション</h2>
    <form class="sinfo-sim" id="studio-sim" onsubmit="return false">
        <?php if ($hasTabs): ?>
            <!-- シミュレーション専用の バンド / 個人練習 の切り替え（両方の料金があるときだけ出す）。
                 下の料金表・パックのタブ（#studio-target-tabs）とは連動しない。それぞれ別に選べる -->
            <div class="sinfo-seg sinfo-seg--mode" role="radiogroup" aria-label="対象">
                <label><input type="radio" name="mode" value="band" checked> <?= icon('groups') ?> バンド練習</label>
                <label><input type="radio" name="mode" value="solo"> <?= icon('person') ?> 個人練習</label>
            </div>
        <?php else: ?>
            <!-- 片方しか無いときは選ばせず、hidden で固定 -->
            <input type="hidden" name="mode" value="<?= $hasBand ? 'band' : 'solo' ?>">
        <?php endif; ?>
        <div class="sinfo-sim__grid">
            <?php if ($rooms): ?>
            <label class="sinfo-field"><span>部屋</span>
                <select name="room">
                    <!-- 部屋名のあとに広さだけ（例: Aスタ 12帖）。備考（定員など）は出さない。広さが無ければ部屋名だけ -->
                    <?php foreach ($rooms as $r): $sz = studio_fmt_jo($r['area_jo']); ?><option value="<?= (int)$r['studio_room_id'] ?>"><?= h($r['name'] . ($sz !== '' ? ' ' . $sz : '')) ?></option><?php endforeach; ?>
                </select></label>
            <?php endif; ?>
            <label class="sinfo-field"><span>日付</span><input type="date" name="date"></label>
            <div class="sinfo-field"><span>曜日</span>
                <div class="sinfo-seg" role="radiogroup">
                    <label><input type="radio" name="day" value="1" checked> 平日</label>
                    <?php if ($splitWeekend): ?>
                        <!-- 土と日祝で料金が違うスタジオ。日付を選ぶと 土 → 土 / 日 → 日祝 に自動で切り替わる（祝日は判定しないので、祝日は手で「日祝」を選ぶ） -->
                        <label><input type="radio" name="day" value="3"> 土</label>
                        <label><input type="radio" name="day" value="4"> 日祝</label>
                    <?php else: ?>
                        <label><input type="radio" name="day" value="2"> 土日祝</label>
                    <?php endif; ?>
                </div></div>
            <label class="sinfo-field"><span>開始</span><select name="start"><?= studio_time_options(18 * 60) ?></select></label>
            <label class="sinfo-field"><span>時間</span>
                <select name="dur">
                    <?php for ($m = 60; $m <= 600; $m += 30): ?>
                        <option value="<?= $m ?>"<?= $m === 120 ? ' selected' : '' ?>><?= h(studio_fmt_min($m)) ?></option>
                    <?php endfor; ?>
                </select></label>
            <!-- 人数: ＋／－ボタンでタッチ操作できるステッパー（数字を直接打つのもOK）。
                 label ではなく div で囲む理由: button も label の「操作対象」になれるので、label 内に入れるとタップが誤作動するため -->
            <div class="sinfo-field"><span>人数</span>
                <div class="sinfo-step">
                    <button type="button" class="sinfo-step__btn" data-step="-1" aria-label="減らす"><?= icon('remove') ?></button>
                    <input type="number" name="people" min="1" max="30" value="5" inputmode="numeric" aria-label="人数">
                    <button type="button" class="sinfo-step__btn" data-step="1" aria-label="増やす"><?= icon('add') ?></button>
                </div></div>
        </div>
        <?php if ($hasStudent): ?>
            <!-- 割引区分の選択（なし / 学割 / U20 …）。区分に「使える曜日」があれば、使えない曜日のときは JS が通常料金で計算して理由を出す。
                 初期値は先頭の区分（これまでの「学割にチェック」と同じ動き） -->
            <div class="sinfo-field"><span>割引</span>
                <div class="sinfo-seg sinfo-seg--disc" role="radiogroup" aria-label="割引">
                    <label><input type="radio" name="disc" value="0"> なし</label>
                    <?php foreach ($usedDisc as $k => $d): ?>
                        <label><input type="radio" name="disc" value="<?= $d['studio_discount_id'] ?>"<?= $k === 0 ? ' checked' : '' ?>> <?= h($d['name']) ?></label>
                    <?php endforeach; ?>
                </div></div>
        <?php endif; ?>
        <?php if ($rentals): ?>
            <!-- レンタル欄: <details> / <summary> = ブラウザ標準の「開け閉めできる箱」（HTMLだけで動く・JS不要）。
                 ・初期状態は閉じている（open 属性を付けると最初から開く）
                 ・閉じていても中の入力欄はフォームに残るので、数を入れたぶんは合計に入ったまま
                   → 閉じても選んだ数がわかるよう、見出しに選択中の件数バッジ（data-rental-count）を出す。数は JS（render）が更新する -->
            <details class="sinfo-rentbox">
                <summary class="sinfo-rentbox__head">
                    <?= icon('mic') ?> レンタル
                    <span class="sinfo-rentbox__count" data-rental-count hidden>0</span>
                    <?= icon('expand_more', 'sinfo-rentbox__chev') ?>
                </summary>
                <div class="sinfo-sim__rentals">
                    <?php foreach ($rentals as $r): ?>
                        <label class="sinfo-rental">
                            <span><?= h($r['name']) ?> <span class="muted small"><?= h(studio_yen((int)$r['price'])) ?><?= $r['per_hour'] ? '/h' : '' ?></span></span>
                            <input type="number" name="rental_<?= (int)$r['studio_rental_id'] ?>" min="0" max="20" value="0" inputmode="numeric" aria-label="<?= h($r['name']) ?>の数">
                        </label>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
        <!-- 結果（JS が書き込む）。aria-live = 中身が変わったら読み上げソフトが読む -->
        <div class="sinfo-sim__result" id="studio-sim-result" aria-live="polite"></div>
    </form>
    <script type="application/json" id="studio-sim-data"><?= json_encode($simData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</section>
<?php endif; ?>

<!-- ===== バンド練習 / 個人練習のタブ（両方の料金があるときだけ出す）=====
     ・ここで選んだ対象に、下の 部屋・料金（料金表）と パック だけが切り替わる（JS: setupTargetTabs）。料金シミュレーションは連動しない
     ・ラジオボタン（input type="radio"）をボタン風に見せている（.sinfo-seg と同じ作り）。名前は tab_target -->
<?php if ($hasTabs): ?>
<div class="sinfo-seg sinfo-seg--mode sinfo-tabs" id="studio-target-tabs" role="radiogroup" aria-label="対象">
    <label><input type="radio" name="tab_target" value="band" checked> <?= icon('groups') ?> バンド練習</label>
    <label><input type="radio" name="tab_target" value="solo"> <?= icon('person') ?> 個人練習</label>
</div>
<?php endif; ?>

<!-- ===== 部屋と料金表（部屋の広さと料金を1枚のカードにまとめた）=====
     部屋ごとのブロック = 見出し（部屋名＋広さ）→ その部屋の料金表。「全部屋」共通の料金は先頭に出す -->
<section class="card sinfo-section" id="rates">
    <h2><?= icon('meeting_room') ?> 部屋・料金 <span class="muted small">1時間あたり</span></h2>
    <?php if (!$rates && !$rooms): ?><p class="muted small">-</p><?php endif; ?>
    <?php
    // 部屋の組み合わせごとに1つの表（共通の料金は「A・B」の見出しの下に1回だけ出す。全部屋共通は先頭）
    //   料金が未登録の部屋も、広さを見せたいので見出しだけ出す（rows が空のグループ）
    // タブがあるときは「バンド」「個人練習」の2つのパネルに分けて出す（どちらも同じ作りで、行だけ対象で絞る）
    //   タブが無いときは $panel = null のパネル1つだけ（これまでと同じ表示）
    //   $panelGroups = そのパネルに出すグループ。料金が無い「見出しだけ」のグループ（広さを見せるため）はバンドのパネルにだけ出す
    $panels = $hasTabs ? ['band', 'solo'] : [null];
    foreach ($panels as $panel):
        $panelGroups = [];
        foreach ($rateGroups as $g) {
            if ($panel === null) {
                $panelGroups[] = $g;
                continue;
            }
            $rowsT = $rowsOfTarget($g['rows'], $panel);
            if ($rowsT || (!$g['rows'] && $panel === 'band')) {
                $panelGroups[] = ['rooms' => $g['rooms'], 'rows' => $rowsT];
            }
        }
        // 料金が同じ部屋は1つの見出しにまとめる（パネルごとにやる。バンドの料金は同じでも個人の料金は違う、ということがあるため）
        $panelGroups = studio_merge_same_rate_groups($panelGroups);
        // 個人練習の料金だけを出すパネルか（タブの「個人」側 / タブが無く個人の料金しか無いスタジオ）
        //   → 「対象」列の代わりに、上の人数チップ（1人・2人 …）で表を切り替える（JS: setupSoloPeople）
        $soloPanel = $panel === 'solo' || ($panel === null && $hasSolo && !$hasBand);
        // このパネルにある人数の一覧（小さい順）。最初は いちばん少ない人数 を選んでおく
        $pplList = [];
        if ($soloPanel) {
            foreach ($panelGroups as $g) {
                foreach ($g['rows'] as $r) {
                    $pplList[(int)$r['people']] = true; // キーに入れると重複が自然に消える
                }
            }
            ksort($pplList); // ksort = キー（人数）の小さい順に並べ替え
            $pplList = array_keys($pplList);
        }
        $pplSel = $pplList[0] ?? 0;
    ?>
    <div<?= $panel !== null ? ' data-tab-panel="' . $panel . '"' . ($panel === 'solo' ? ' hidden' : '') : '' ?>>
    <?php if ($pplList): ?>
        <!-- 人数チップ（ラジオボタンをボタン風に。.sinfo-seg と同じ作り）。選んだ人数の料金だけを表に出す -->
        <div class="sinfo-seg sinfo-ppl" role="radiogroup" aria-label="人数">
            <?php foreach ($pplList as $n): ?>
                <label><input type="radio" name="solo_ppl" value="<?= $n ?>"<?= $n === $pplSel ? ' checked' : '' ?>> <?= $n ?>人</label>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php foreach ($panelGroups as $g):
        // 表の行を人数ごとに分ける（個人のパネルだけ。バンドは 0 の1組だけ）
        //   人数ごとに別の <tbody> にする理由: 行を1つずつ隠すと、しま模様（tbody tr:nth-child(even)）の順番がずれる。
        //   tbody ごとに切り替えれば、nth-child は tbody の中で数え直されるので、しま模様が崩れない
        $byPpl = [];
        foreach ($g['rows'] as $r) {
            $byPpl[$soloPanel ? (int)$r['people'] : 0][] = $r;
        }
        ksort($byPpl);
        // data-ppl-has = この部屋の料金がある人数（例: "1 2"）。選んだ人数の料金が無い部屋は見出しごと隠す
        $pplHas = $soloPanel ? implode(' ', array_keys($byPpl)) : '';
    ?>
        <div<?= $soloPanel ? ' data-ppl-has="' . $pplHas . '"' . (isset($byPpl[$pplSel]) ? '' : ' hidden') : '' ?>>
            <?php if ($rooms): ?>
                <h3 class="sinfo-sub">
                    <?php if (!$g['rooms']): ?>全部屋<?php else: ?>
                        <!-- 部屋が複数のとき、「・」でつなぐ。画面が狭くて折り返すときは、部屋の頭で改行し、行頭の「・」は出さない（CSS だけ。.sinfo-rooms の説明を参照）。
                             部屋の1つ1つ（名前＋広さ）が .sinfo-room。途中で改行されない（nowrap）ので、「Bスタ 8帖」が2行に割れない -->
                        <span class="sinfo-rooms"><span class="sinfo-rooms__in">
                            <?php foreach ($g['rooms'] as $rid): ?><span class="sinfo-room"><?= h($roomName[$rid]) ?><?php if (!empty($roomSize[$rid])): ?> <span class="muted small"><?= h($roomSize[$rid]) ?></span><?php endif; ?></span><?php endforeach; ?>
                        </span></span>
                    <?php endif; ?>
                </h3>
            <?php endif; ?>
            <?php if (!empty($g['rows'])): ?>
            <div class="sinfo-table-wrap">
            <table class="sinfo-table">
                <!-- 割引区分ごとに1列（学割・U20 …）。料金が1つも無い区分の列は出さない -->
                <!-- 同じ料金（対象・通常・割引すべて同じ）の行は1行にまとめ、時間帯の欄に「曜日 開始〜終了」を縦に並べる（studio_merge_same_price） -->
                <thead><tr><th>時間帯</th><th class="num">料金</th><?php foreach ($rateDisc as $d): ?><th class="num"><?= h($d['name']) ?></th><?php endforeach; ?></tr></thead>
                <?php foreach ($byPpl as $n => $pplRows): ?>
                <tbody<?= $soloPanel ? ' data-ppl="' . $n . '"' . ($n === $pplSel ? '' : ' hidden') : '' ?>>
                <?php foreach (studio_merge_same_price($pplRows) as $m): $r = $m['row']; ?>
                    <tr>
                        <td>
                            <?php foreach ($m['slots'] as $s): ?>
                                <span class="sinfo-slot"><span class="sinfo-slot__day"><?= h(STUDIO_DAY_TYPES[(int)$s['day_type']] ?? '') ?></span><?= h(studio_fmt_min((int)$s['start_min'])) ?>〜<?= h(studio_fmt_min((int)$s['end_min'])) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td class="num"><?= h(studio_yen((int)$r['price'])) ?></td>
                        <?php foreach ($rateDisc as $d): $dp = $r['disc'][$d['studio_discount_id']] ?? null; ?>
                            <td class="num sinfo-student"><?= $dp === null ? '<span class="muted">-</span>' : h(studio_yen($dp)) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; /* 行 */ ?>
                </tbody>
                <?php endforeach; /* 人数ごとの tbody */ ?>
            </table>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; /* グループ */ ?>
    </div>
    <?php endforeach; /* パネル（バンド / 個人） */ ?>
    <?php if ($hasStudent || $studio['student_note']): ?>
        <!-- 割引の条件: 区分ごとのタグ（使える曜日つき）＋ 共通の条件（学生証の提示など） -->
        <p class="sinfo-note">
            <?php foreach ($usedDisc as $d): ?>
                <span class="tag tag--plan"><?= h($d['name']) ?><?php if ($d['day_type']): ?> <?= h(studio_discount_day_label($d['day_type'])) ?><?php endif; ?></span>
            <?php endforeach; ?>
            <?= nl2br(h((string)$studio['student_note'])) ?>
        </p>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <details class="sinfo-edit">
        <summary><?= icon('edit') ?> 料金を編集</summary>
        <?php
        /*
         * 編集画面は「対象・曜日の見出し（バンド練習・平日 など）→ 時間帯 → 部屋ごとの料金」
         *   時間帯の対象・曜日は見出しで決まるので hidden で送る。時間帯の中で入れるのは 開始・終了 と、部屋ごとの 1時間の料金・割引区分ごとの割引
         *   見出しの「＋」で同じ見出しに空の時間帯を足す（<template> を複製。JS: setupRateEdit）
         *   一番下の時間帯（対象・曜日を選べる）で、新しい見出しを作れる。「＋ 見出し」で何個でも足せる
         *   時間帯のキー（$rateRow の説明）: 既存 = 'e' + 先頭の料金の id / 新しい見出し = 'n1' / ＋の雛形 = '__K__'
         *   DB の行は「1行 = 1つの時間帯 × 1つの部屋」。開始・終了が同じ行を1つの時間帯にまとめて表示する
         *   （複数の部屋にまたがる行・全部屋共通の行は、各部屋の欄に同じ料金を出す。保存すると部屋ごとの行になる）
         */
        $roomLines = $rooms ? array_map(fn($r) => [(int)$r['studio_room_id'], $r['name']], $rooms) : [[0, '全部屋']];
        // 料金を「時間帯（対象・曜日・開始・終了）」にまとめ、さらに「対象|曜日」ごとにまとめる（並び = 取得時の並び: 対象 → 曜日 → 開始時刻）
        $slots = [];
        foreach ($rates as $r) {
            $sk = ($r['people'] ?? 0) . '|' . (int)$r['day_type'] . '|' . (int)$r['start_min'] . '|' . (int)$r['end_min'];
            $slots[$sk] ??= ['people' => $r['people'], 'day_type' => (int)$r['day_type'], 'start_min' => (int)$r['start_min'], 'end_min' => (int)$r['end_min'], 'lines' => [], 'key' => 'e' . (int)$r['studio_rate_id']];
            // 部屋が決まっていない行（全部屋共通）は、すべての部屋の欄に出す。行の id は最初の欄だけに持たせる（保存で行が増えないように）
            $idUsed = false;
            foreach ($r['room_ids'] ?: array_column($roomLines, 0) as $rid) {
                if (isset($slots[$sk]['lines'][$rid]) || !in_array($rid, array_column($roomLines, 0), true)) {
                    continue;
                }
                $slots[$sk]['lines'][$rid] = ['id' => $idUsed ? 0 : (int)$r['studio_rate_id'], 'price' => $r['price'], 'disc_raw' => $r['disc_raw']];
                $idUsed = true;
            }
        }
        $groups = [];
        foreach ($slots as $s) {
            $groups[($s['people'] ?? 0) . '|' . $s['day_type']][] = $s;
        }
        $newBlank = ['people' => null, 'day_type' => 0, 'start_min' => 600, 'end_min' => 1380, 'lines' => []];
        ?>
        <form method="post" class="sinfo-edit__form sinfo-rate-edit">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_rates">
            <input type="hidden" name="studio_id" value="<?= $studioId ?>">
                    <?php foreach ($groups as $gk => $list):
                        [$people, $day] = array_map('intval', explode('|', $gk));
                        $last = end($list); // end = 配列の最後の要素（＋で足す時間帯の開始時刻を、最後の時間帯の終了時刻にする）
                        $blank = ['people' => $people ?: null, 'day_type' => $day, 'start_min' => (int)$last['end_min'],
                            'end_min' => min(STUDIO_MAX_MIN, (int)$last['end_min'] + 360), 'lines' => []];
                    ?>
                        <div class="sinfo-rate-group" data-rate-group>
                            <div class="sinfo-rate-group__head">
                                <strong><?= h(studio_target_label($people ?: null)) ?></strong>
                                <span class="tag"><?= h(STUDIO_DAY_TYPES[$day] ?? '') ?></span>
                                <button type="button" class="btn btn--sm sinfo-rate-group__add" data-rate-add title="時間帯を追加" aria-label="時間帯を追加"><?= icon('add') ?></button>
                            </div>
                            <?php foreach ($list as $s): ?><?= $rateRow($s, $s['key'], true, $roomLines) ?><?php endforeach; ?>
                            <!-- ＋ で複製する空の時間帯（<template> の中身は画面に出ず、送信もされない）。キー '__K__' は複製のときに JS が本物に置き換える -->
                            <template data-rate-tpl><?= $rateRow($blank, '__K__', true, $roomLines) ?></template>
                        </div>
                    <?php endforeach; ?>
                    <!-- 新しい見出し（対象・曜日）を作る時間帯。料金を入れて保存すると、その見出しの下に並ぶ。「＋ 見出し」で何個でも足せる -->
                    <div class="sinfo-rate-group sinfo-rate-group--new">
                        <?= $rateRow($newBlank, 'n1', false, $roomLines) ?>
                    </div>
                    <button type="button" class="btn btn--sm sinfo-rate-new-add" data-rate-new-add><?= icon('add') ?> 見出し</button>
                    <!-- 雛形（JS が複製）: 新しい見出し / 追加の時間帯の1行。'__K__' = 時間帯のキー、'__N__' = 追加の時間帯の番号 -->
                    <template data-rate-new-tpl><div class="sinfo-rate-group sinfo-rate-group--new"><?= $rateRow($newBlank, '__K__', false, $roomLines) ?></div></template>
                    <template data-slot-tpl><?= $slotLine(['x[__K__][__N__][day]', 'x[__K__][__N__][start]', 'x[__K__][__N__][end]'], 0, 600, 1380, true) ?></template>
            <div><button type="submit" class="btn btn--primary btn--sm" title="料金を空にした行は削除"><?= icon('save') ?> 保存</button></div>
        </form>
    </details>
    <!-- 割引区分（学割・U20 …）の編集。使える曜日を選ぶと、シミュレーションがその曜日以外では割引を使わない -->
    <details class="sinfo-edit">
        <summary><?= icon('edit') ?> 割引区分を編集</summary>
        <form method="post" class="sinfo-edit__form" data-confirm="区分名を空にした区分は、その区分の料金も削除されます。保存しますか？">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_discounts">
            <input type="hidden" name="studio_id" value="<?= $studioId ?>">
            <?php foreach (array_merge($discounts, count($discounts) < STUDIO_DISCOUNT_MAX ? [['studio_discount_id' => 0, 'name' => '', 'day_type' => 0]] : []) as $d): ?>
                <div class="sinfo-edit__row">
                    <input type="hidden" name="id[]" value="<?= (int)$d['studio_discount_id'] ?>">
                    <input autocomplete="off" type="text" class="grow" name="name[]" maxlength="30" value="<?= h($d['name']) ?>" placeholder="区分名（例: 学割 / U20）" aria-label="区分名">
                    <select name="day[]" aria-label="使える曜日">
                        <?php foreach (STUDIO_DAY_TYPES as $k => $label): ?>
                            <option value="<?= $k ?>"<?= $k === (int)$d['day_type'] ? ' selected' : '' ?>><?= h($k === 0 ? $label : $label . 'のみ') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= studio_row_del((int)$d['studio_discount_id']) ?>
                </div>
            <?php endforeach; ?>
            <div><button type="submit" class="btn btn--primary btn--sm" title="区分名を空にした行は削除"><?= icon('save') ?> 保存</button></div>
        </form>
    </details>
    <!-- 部屋（名前・広さ）の編集。同じカードの中に置く。部屋を削除するとその部屋の料金・パック・機材も消える（FK の CASCADE）ので確認ダイアログ付き -->
    <details class="sinfo-edit">
        <summary><?= icon('edit') ?> 部屋を編集</summary>
        <form method="post" class="sinfo-edit__form" data-confirm="部屋名を空にした部屋は、その部屋の料金・パック・機材も削除されます。保存しますか？">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_rooms">
            <input type="hidden" name="studio_id" value="<?= $studioId ?>">
            <!-- 広さは数値（帖）の欄に分けた。検索で「12帖以上」と比べられるように、単位は帖に統一（畳も同じ数字でOK）。備考は定員・階数など -->
            <?php foreach (array_merge($rooms, [['studio_room_id' => 0, 'name' => '', 'area_jo' => null, 'size_note' => '']]) as $r): ?>
                <div class="sinfo-edit__row">
                    <input type="hidden" name="id[]" value="<?= (int)$r['studio_room_id'] ?>">
                    <input autocomplete="off" type="text" class="grow" name="name[]" maxlength="50" value="<?= h($r['name']) ?>" placeholder="部屋名" aria-label="部屋名">
                    <label class="sinfo-edit__jo"><input type="text" name="area_jo[]" inputmode="decimal" maxlength="5" value="<?= h(studio_jo_num($r['area_jo'])) ?>" placeholder="広さ" aria-label="広さ（帖）"><span class="muted small">帖</span></label>
                    <input autocomplete="off" type="text" class="grow" name="size_note[]" maxlength="50" value="<?= h($r['size_note']) ?>" placeholder="備考（定員・階数）" aria-label="備考">
                    <?= studio_row_del((int)$r['studio_room_id']) ?>
                </div>
            <?php endforeach; ?>
            <div><button type="submit" class="btn btn--primary btn--sm" title="部屋名を空にした行は削除"><?= icon('save') ?> 保存</button></div>
        </form>
    </details>
    <?php endif; ?>
</section>

<!-- ===== パック（時間帯の中の決まった時間を一律料金）===== -->
<?php if ($packs || $canEdit): ?>
<?php
// タブがあるとき、パックの <li> に data-tab-panel（band / solo）を付けて JS が出し分ける
//   編集できない人は、選んだタブにパックが1つも無ければ、セクションごと隠す（見出しだけ残らないように）。data-tab-has = パックがある対象の一覧
//   （編集できる人はフォームがこのセクション内にあるので、隠さない）
$packTargetOf = fn(array $p): string => $p['people'] === null ? 'band' : 'solo';
$packHas = array_values(array_unique(array_map($packTargetOf, $packs)));
?>
<section class="card sinfo-section" id="packs"<?= $hasTabs && !$canEdit ? ' data-tab-has="' . h(implode(' ', $packHas)) . '"' : '' ?>>
    <h2><?= icon('inventory_2') ?> パック</h2>
    <?php if (!$packs): ?><p class="muted small">-</p><?php else: ?>
        <ul class="sinfo-plain sinfo-packs">
            <?php foreach ($packs as $p): ?>
                <li<?= $hasTabs ? ' data-tab-panel="' . $packTargetOf($p) . '"' . ($packTargetOf($p) === 'solo' ? ' hidden' : '') : '' ?>>
                    <div class="sinfo-packs__head">
                        <strong><?= h($p['name']) ?></strong>
                        <?php if ($hasSolo && $packTargetOf($p) === 'solo'): ?><span class="tag"><?= h(studio_target_label((int)$p['people'])) ?></span><?php endif; ?>
                        <?php foreach ($p['room_ids'] as $rid): ?><span class="tag"><?= h($roomName[$rid] ?? '') ?></span><?php endforeach; ?>
                    </div>
                    <div class="sinfo-packs__body">
                        <span class="muted small">
                            <?= h(STUDIO_DAY_TYPES[(int)$p['day_type']] ?? '') ?>
                            <?= h(studio_fmt_min((int)$p['start_min'])) ?>〜<?= h(studio_fmt_min((int)$p['end_min'])) ?>
                            <?php if ($p['minutes'] !== null): ?>のうち <?= h($packLen($p)) ?><?php endif; ?>
                        </span>
                        <span>
                            <?= h(studio_yen((int)$p['price'])) ?>
                            <?php foreach ($p['disc'] as $did => $dp): ?><span class="sinfo-student"><?= h($discName[$did] ?? '') ?> <?= h(studio_yen($dp)) ?></span><?php endforeach; ?>
                        </span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <details class="sinfo-edit">
        <summary><?= icon('edit') ?> 編集</summary>
        <form method="post" class="sinfo-edit__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_packs">
            <input type="hidden" name="studio_id" value="<?= $studioId ?>">
            <!-- 使える時間を「時間帯まるごと」にするときは、時間の欄を空にする。料金を空にした行は削除 -->
            <?php foreach (array_merge($packs, [['studio_pack_id' => 0, 'room_ids' => [], 'people' => null, 'name' => '', 'day_type' => 0, 'start_min' => 1440, 'end_min' => 1800, 'minutes' => null, 'price' => '', 'disc' => []]]) as $i => $p): ?>
                <div class="sinfo-edit__row sinfo-edit__rate">
                    <input type="hidden" name="id[]" value="<?= (int)$p['studio_pack_id'] ?>">
                    <input autocomplete="off" type="text" class="grow" name="name[]" maxlength="50" value="<?= h($p['name']) ?>" placeholder="名前（例: 深夜パック）" aria-label="パック名">
                    <select name="people[]" aria-label="対象"><?= studio_target_options($p['people'] === null ? null : (int)$p['people']) ?></select>
                    <?= $roomChips($i, $p['room_ids']) ?>
                    <select name="day[]" aria-label="曜日">
                        <?php foreach (STUDIO_DAY_TYPES as $k => $label): ?>
                            <option value="<?= $k ?>"<?= $k === (int)$p['day_type'] ? ' selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="start[]" aria-label="時間帯の開始"><?= studio_time_options((int)$p['start_min']) ?></select>
                    <span class="muted">〜</span>
                    <select name="end[]" aria-label="時間帯の終了"><?= studio_time_options((int)$p['end_min']) ?></select>
                    <select name="minutes[]" aria-label="使える時間">
                        <option value="">時間帯まるごと</option>
                        <?php for ($m = 60; $m <= 720; $m += 30): ?>
                            <option value="<?= $m ?>"<?= $p['minutes'] !== null && (int)$p['minutes'] === $m ? ' selected' : '' ?>><?= h(studio_fmt_min($m)) ?></option>
                        <?php endfor; ?>
                    </select>
                    <input type="number" name="price[]" min="0" max="999999" value="<?= h($p['price']) ?>" placeholder="料金" aria-label="パックの料金" inputmode="numeric">
                    <?= $discInputs($p['disc'], 'パックの料金') ?>
                    <?= studio_row_del((int)$p['studio_pack_id']) ?>
                </div>
            <?php endforeach; ?>
            <div><button type="submit" class="btn btn--primary btn--sm" title="料金を空にした行は削除"><?= icon('save') ?> 保存</button></div>
        </form>
    </details>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- ===== レンタル ===== -->
<section class="card sinfo-section" id="rentals">
    <h2><?= icon('mic') ?> レンタル</h2>
    <?php if (!$rentals): ?><p class="muted small">-</p><?php else: ?>
        <ul class="sinfo-plain sinfo-price-list">
            <?php foreach ($rentals as $r): ?>
                <li><span><?= h($r['name']) ?></span><span><?= h(studio_yen((int)$r['price'])) ?><span class="muted small"><?= $r['per_hour'] ? '/h' : '/回' ?></span></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <details class="sinfo-edit">
        <summary><?= icon('edit') ?> 編集</summary>
        <form method="post" class="sinfo-edit__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_rentals">
            <input type="hidden" name="studio_id" value="<?= $studioId ?>">
            <?php foreach (array_merge($rentals, array_fill(0, 2, ['studio_rental_id' => 0, 'name' => '', 'price' => '', 'per_hour' => 0])) as $r): ?>
                <div class="sinfo-edit__row">
                    <input type="hidden" name="id[]" value="<?= (int)$r['studio_rental_id'] ?>">
                    <input autocomplete="off" type="text" class="grow" name="name[]" maxlength="100" value="<?= h($r['name']) ?>" placeholder="名前（例: SM58）" aria-label="名前">
                    <input type="number" name="price[]" min="0" max="999999" value="<?= h($r['price']) ?>" placeholder="料金" aria-label="料金" inputmode="numeric">
                    <!-- チェックボックスは「外れていると何も送られない」ので、行の番号がずれる。select にして必ず値を送る -->
                    <select name="per_hour[]" aria-label="単位">
                        <option value="0"<?= (int)$r['per_hour'] === 0 ? ' selected' : '' ?>>/回</option>
                        <option value="1"<?= (int)$r['per_hour'] === 1 ? ' selected' : '' ?>>/h</option>
                    </select>
                    <?= studio_row_del((int)$r['studio_rental_id']) ?>
                </div>
            <?php endforeach; ?>
            <div><button type="submit" class="btn btn--primary btn--sm" title="名前を空にした行は削除"><?= icon('save') ?> 保存</button></div>
        </form>
    </details>
    <?php endif; ?>
</section>

<!-- ===== 機材（部屋ごと → 区分ごと） ===== -->
<section class="card sinfo-section" id="equipment">
    <h2><?= icon('speaker') ?> 機材</h2>
    <?php if (!$equipment): ?><p class="muted small">-</p><?php endif; ?>
    <?php foreach ([0 => '全部屋'] + $roomName as $rid => $rname): if (empty($equipByRoom[$rid])) continue; ?>
        <div>
            <?php if ($rooms): ?><h3 class="sinfo-sub"><?= h($rname) ?></h3><?php endif; ?>
            <?php foreach (STUDIO_EQUIP_CATEGORIES as $cat): if (empty($equipByRoom[$rid][$cat])) continue; ?>
                <div class="sinfo-equip">
                    <span class="sinfo-equip__cat"><?= h($cat) ?></span>
                    <ul class="sinfo-plain">
                        <?php foreach ($equipByRoom[$rid][$cat] as $e): ?>
                            <li><?= h($e['model_name']) ?><?php if ((int)$e['quantity'] !== 1): ?> <span class="muted">× <?= (int)$e['quantity'] ?></span><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($canEdit): ?>
    <details class="sinfo-edit">
        <summary><?= icon('edit') ?> 編集</summary>
        <form method="post" class="sinfo-edit__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_equipment">
            <input type="hidden" name="studio_id" value="<?= $studioId ?>">
            <?php foreach (array_merge($equipment, array_fill(0, 2, ['studio_equipment_id' => 0, 'room_ids' => [], 'category' => '', 'model_name' => '', 'quantity' => 1])) as $i => $e): ?>
                <div class="sinfo-edit__row">
                    <input type="hidden" name="id[]" value="<?= (int)$e['studio_equipment_id'] ?>">
                    <?= $roomChips($i, $e['room_ids']) ?>
                    <select name="category[]" aria-label="区分">
                        <?php foreach (STUDIO_EQUIP_CATEGORIES as $cat): ?>
                            <option value="<?= h($cat) ?>"<?= $cat === $e['category'] ? ' selected' : '' ?>><?= h($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input autocomplete="off" type="text" class="grow" name="model[]" maxlength="100" value="<?= h($e['model_name']) ?>" placeholder="機種名" aria-label="機種名">
                    <input type="number" name="quantity[]" min="0" max="99" value="<?= (int)$e['quantity'] ?>" aria-label="台数" inputmode="numeric">
                    <?= studio_row_del((int)$e['studio_equipment_id']) ?>
                </div>
            <?php endforeach; ?>
            <div><button type="submit" class="btn btn--primary btn--sm" title="機種名を空にした行は削除"><?= icon('save') ?> 保存</button></div>
        </form>
    </details>
    <?php endif; ?>
</section>

<?php if ($canEdit): ?>
    <form method="post" class="sinfo-delete" data-confirm="「<?= h($studio['name']) ?>」を削除します（料金・パック・レンタル・機材も削除）。元に戻せません。よろしいですか？">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_studio">
        <input type="hidden" name="studio_id" value="<?= $studioId ?>">
        <button type="submit" class="btn btn--danger btn--sm"><?= icon('delete') ?> スタジオを削除</button>
    </form>
<?php endif; ?>

<?php if ($studio['reserve_url']): // 予約ボタンは画面の右下に浮かせる（スクロールしても押せる） ?>
    <a class="sinfo-reserve-fab" href="<?= h($studio['reserve_url']) ?>" target="_blank" rel="noopener noreferrer" title="予約" aria-label="予約"><?= icon('event_available') ?> 予約</a>
<?php endif; ?>

<script src="<?= asset_url('assets/vendor/leaflet/leaflet.js') ?>"></script>
<script src="<?= asset_url('assets/studio_info.js') ?>"></script>
<?php render_footer();
