<?php
/**
 * =====================================================================
 *  member_album_save.php — 好きなアルバムの「追加」と「削除」
 * =====================================================================
 *  member.php のボタンの送信先。画面は持たない（処理して member.php に戻るだけ）。
 *  member_edit.php と同じ型: ログイン確認 → POST確認 → CSRF確認 → 権限確認 → 入力チェック → DB → 戻る
 *
 *  権限:
 *    追加 … 本人だけ（自分の好きなアルバムを他人が決めるのはおかしい）
 *    削除 … 本人 + 管理者（ふざけた登録があったとき、管理者が消せるように）
 *
 *  送られてくる値（POST）:
 *    action        'add' か 'delete'
 *    member_id     誰のアルバムか
 *    collection_id 追加: 追加したいアルバムの iTunes ID（タイトルや画像URLは受け取らない！）
 *    sort_order    削除: 何番目を消すか
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/itunes.php';
$user = require_login();

if (!is_post()) {
    redirect('members.php');
}
verify_csrf();

$action = (string)($_POST['action'] ?? '');
$memberId = (int)($_POST['member_id'] ?? 0);
$back = 'member.php?id=' . $memberId . '#albums'; // #albums: 戻ったときアルバム欄までスクロールさせる
$isMe = $memberId === $user['member_id'];
$pdo = db();

// ---------------------------------------------------------------------
//  追加
// ---------------------------------------------------------------------
if ($action === 'add') {
    if (!$isMe) {
        http_response_code(403);
        exit('好きなアルバムを追加できるのは本人だけです');
    }

    $collectionId = (int)($_POST['collection_id'] ?? 0);
    if ($collectionId <= 0) {
        flash('アルバムが選ばれていません', 'error');
        redirect($back);
    }

    // 上限チェック。COUNT(*) は条件に合う行の数を数える
    $st = $pdo->prepare('SELECT COUNT(*) FROM member_favorite_album WHERE member_id = ?');
    $st->execute([$memberId]);
    if ((int)$st->fetchColumn() >= FAVORITE_ALBUM_LIMIT) {
        flash('好きなアルバムは' . FAVORITE_ALBUM_LIMIT . '枚までです。どれかを削除してから追加してください', 'error');
        redirect($back);
    }

    // 二重登録チェック（DB の UNIQUE でも止まるが、分かりやすいメッセージを出すために先に確認）
    $st = $pdo->prepare('SELECT 1 FROM member_favorite_album WHERE member_id = ? AND itunes_collection_id = ?');
    $st->execute([$memberId, $collectionId]);
    if ($st->fetchColumn()) {
        flash('そのアルバムはもう登録されています', 'info');
        redirect($back);
    }

    // ★ ブラウザから来たのは ID だけ。中身はサーバーが iTunes に聞き直す（偽装対策）
    $album = itunes_lookup_album($collectionId);
    if ($album === null) {
        flash('iTunes からアルバムの情報を取得できませんでした。時間をおいてもう一度試してください', 'error');
        redirect($back);
    }

    try {
        // 次の番号 = 今の最大の番号 + 1（1枚も無ければ COALESCE で 0 扱い → 1番）
        //   COALESCE(a, b): a が NULL なら b を使う。MAX() は行が無いと NULL を返すので必要。
        $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM member_favorite_album WHERE member_id = ?');
        $st->execute([$memberId]);
        $nextOrder = (int)$st->fetchColumn();

        $pdo->prepare('INSERT INTO member_favorite_album
                (member_id, sort_order, itunes_collection_id, title, artist_name, artwork_url, release_year)
                VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $memberId, $nextOrder, $album['collection_id'],
                $album['title'], $album['artist_name'], $album['artwork_url'], $album['release_year'],
            ]);
    } catch (PDOException $e) {
        // 23000 = 「主キー / UNIQUE が重複した」エラーの番号。
        //   ボタンを2連打した場合などに、2回目がここに来る（DB が二重登録を止めてくれた）
        if ($e->getCode() === '23000') {
            flash('そのアルバムはもう登録されています', 'info');
            redirect($back);
        }
        throw $e; // それ以外のエラーは想定外なので、隠さずそのまま上に投げる
    }

    flash('「' . $album['title'] . '」を追加しました');
    redirect($back);
}

// ---------------------------------------------------------------------
//  削除
// ---------------------------------------------------------------------
if ($action === 'delete') {
    if (!$isMe && !is_admin()) {
        http_response_code(403);
        exit('自分のアルバムか、管理者だけが削除できます');
    }
    $order = (int)($_POST['sort_order'] ?? 0);

    // トランザクション: 「消す」と「番号を詰める」をひとまとめにする。
    //   途中で失敗したら rollBack() で両方なかったことになる（片方だけ実行された中途半端な状態を作らない）
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('DELETE FROM member_favorite_album WHERE member_id = ? AND sort_order = ?');
        $st->execute([$memberId, $order]);

        // rowCount(): 今の SQL で何行変わったか。0 なら「もう無かった」（2連打など）
        if ($st->rowCount() > 0) {
            // 後ろの番号を1つずつ詰める（3番を消したら 4→3, 5→4 ...）
            //   ⚠ 主キーが (member_id, sort_order) なので、5→4 を先にやると「4番が2つ」になりエラー。
            //     ORDER BY sort_order（小さい順）で 4→3, 5→4 の順に更新させて衝突を避けている。
            $pdo->prepare('UPDATE member_favorite_album SET sort_order = sort_order - 1
                    WHERE member_id = ? AND sort_order > ? ORDER BY sort_order')
                ->execute([$memberId, $order]);
        }
        $pdo->commit(); // ここで初めて確定
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    flash('アルバムを削除しました');
    redirect($back);
}

// add でも delete でもない
http_response_code(400);
exit('不正な操作です');
