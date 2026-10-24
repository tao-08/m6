<?php
/**
 * =====================================================================
 *  band_edit.php — バンドの編集 / 追加 / 削除
 * =====================================================================
 *    band_edit.php?id=バンドID   … 既存バンドの編集
 *    band_edit.php?day=日程ID    … その日程に新しいバンドを追加
 *
 *  メンバーは「フォームの内容との差分だけ」更新する（sync_band_members）。
 *  曲ごとに誰が演奏したかは songs_edit.php で編集する。
 *
 *  出演順を変えると、同じ日のほかのバンドも 1,2,3... と振り直される（renumber_bands）。
 *  バンドを削除・追加すると、その後ろのバンドと休憩の時刻は持ち時間の分だけ自動で詰まる / 繰り下がる（shift_day_times）。
 *  休憩の位置（after_order）も出演順に合わせて直す。追加で時刻を空欄にすると、直前の行の終了から隣のバンドと同じ持ち時間で入る。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/youtube.php'; // プレイリストの動画から選ぶプルダウン
require_once __DIR__ . '/lib/history.php'; // 変更履歴（元に戻すための「変更前の状態」を残す）
require_once __DIR__ . '/lib/attach_image.php'; // バンドの添付画像（最大5枚）
require_login();

$pdo = db();
$bandId = (int)($_GET['id'] ?? $_POST['band_id'] ?? 0);
$dayId = (int)($_GET['day'] ?? $_POST['day_id'] ?? 0);
$isNew = $bandId === 0; // id が無ければ「新規追加」モード

if ($isNew) {
    $st = $pdo->prepare('SELECT d.live_day_id, d.live_id, d.label, l.name AS live_name, l.youtube_url AS live_youtube_url
        FROM live_day d JOIN live l ON l.live_id = d.live_id WHERE d.live_day_id = ?');
    $st->execute([$dayId]);
    $band = $st->fetch();
    if (!$band) {
        error_page(404, '日程が見つかりません');
    }
    $band += ['band_id' => 0, 'name' => '', 'artist_name' => '', 'song_count' => '', 'note' => '', 'youtube_url' => '', 'needs_check' => 0,
        'start_time' => null, 'end_time' => null, 'play_order' => next_play_order($pdo, $dayId)];
} else {
    $st = $pdo->prepare('SELECT b.*, a.name AS artist_name, d.live_id, d.label, l.name AS live_name, l.youtube_url AS live_youtube_url FROM band b
        JOIN live_day d ON d.live_day_id = b.live_day_id
        JOIN live l ON l.live_id = d.live_id
        LEFT JOIN artist a ON a.artist_id = b.artist_id
        WHERE b.band_id = ?');
    $st->execute([$bandId]);
    $band = $st->fetch();
    if (!$band) {
        error_page(404, 'バンドが見つかりません');
    }
}
$dayId = (int)$band['live_day_id'];
$liveUrl = 'live?id=' . (int)$band['live_id'] . '#day-' . $dayId;
// 戻り先: 既存のバンドはバンド詳細、新しく追加するときはライブページ
$backUrl = $isNew ? $liveUrl : 'band?id=' . $bandId;

// 今のメンバー。セトリに出ている人（locked = 1）は楽器をセトリの実績で決めるので、この画面では変えられない
//   （sync_band_members もその人の行には触らない）
$editableRows = [];
$lockedRows = [];
if (!$isNew) {
    $st = $pdo->prepare('SELECT bm.band_id, bm.member_id, m.name, bm.instrument_id,
            EXISTS (SELECT 1 FROM song_performer sp WHERE sp.band_id = bm.band_id AND sp.member_id = bm.member_id) AS locked
        FROM band_member bm
        JOIN member m ON m.member_id = bm.member_id
        JOIN instrument i ON i.instrument_id = bm.instrument_id
        WHERE bm.band_id = ? ORDER BY i.sort_order, m.name');
    $st->execute([$bandId]);
    foreach ($st as $r) {
        if ($r['locked']) {
            $lockedRows[] = $r;
        } else {
            $editableRows[] = $r;
        }
    }
}
$lockedMembers = merge_vocal_roles($lockedRows); // Vo と Gt の2行を持つ人は「Vo/Gt」の1行にまとめて見せる
// 🚩「誰について」の候補（今のメンバー。Vo と Gt の2行を持つ人も1人）と、いま選ばれている人
$checkCandidates = array_column(array_merge($lockedRows, $editableRows), 'name', 'member_id');
$checkWho = $isNew ? [] : array_keys(load_check_members($pdo, $bandId));
// ボーカル（Vo の行を持つ人 = Vo / Vo/Gt など）。「VoかVo/Gtか不明」を押すと自動で選ばれる
$checkVocals = array_map('intval', array_column(array_filter(array_merge($lockedRows, $editableRows),
    static fn($r) => (int)$r['instrument_id'] === instrument_id_by_short('Vo')), 'member_id'));

$errors = [];
$images = $isNew ? [] : (attach_images($pdo, 'band', [$bandId])[$bandId] ?? []); // 添付済みの画像
if (is_post()) {
    verify_csrf();

    // ---------- 削除（管理者のみ） ----------
    if (($_POST['action'] ?? '') === 'delete' && !$isNew) {
        if (!is_admin()) {
            error_page(403, '削除は管理者のみできます');
        }
        $pdo->beginTransaction();
        $op = change_begin($pdo, 'band_delete', '「' . $band['name'] . '」を削除', (int)$band['live_id']);
        // 消す前の状態（日程・休憩・全バンド。メンバー・セトリごと）を履歴に残す。後ろの時刻も動かすので、消すバンドだけでなく全部
        change_capture_day($pdo, $op, $dayId);
        $order = (int)$band['play_order'];
        delete_band($pdo, $bandId);   // band_member は ON DELETE CASCADE で一緒に消える
        // 開始と終了の両方があるときだけ、後ろの時刻（と消したバンドの直後の休憩）をその持ち時間の分だけ前へ詰める
        //   日付をまたぐ（false）ことは詰める方向では起きないはずだが、起きたらずらさずに続ける
        $len = time_to_sec($band['end_time']) !== null && time_to_sec($band['start_time']) !== null
            ? time_to_sec($band['end_time']) - time_to_sec($band['start_time']) : null; // 持ち時間（秒）
        if ($len !== null) {
            shift_day_times($pdo, $dayId, $order + 1, $order, -$len);
        }
        shift_breaks_for_delete($pdo, $dayId, $order); // 休憩の「どのバンドの後か」も1つ前へ
        renumber_bands($pdo, $dayId); // 抜けた番号を詰める
        $pdo->commit();
        flash('「' . $band['name'] . '」を削除しました');
        redirect($liveUrl);
    }

    // ---------- 保存 ----------
    $name = trim((string)($_POST['name'] ?? ''));
    $artistName = trim((string)($_POST['artist'] ?? ''));
    $songs = (string)($_POST['song_count'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    $youtube = trim((string)($_POST['youtube_url'] ?? ''));
    // 🚩 確認依頼（チェックが無ければ外す）。理由はビットで複数、誰についてはメンバーID の配列
    $chk = is_array($_POST['chk'] ?? null) ? $_POST['chk'] : [];
    $flag = check_bits($chk['flag'] ?? null, $chk['reason'] ?? []);
    $checkWho = array_map('intval', (array)($chk['who'] ?? []));
    $order = max(1, (int)($_POST['play_order'] ?? 1));
    $start = (string)($_POST['start_time'] ?? '');
    $end = (string)($_POST['end_time'] ?? '');

    $rows = [];    // 登録する [名前, instrument_id]（ギターボーカルは Vo と Gt の2つ、Gt/Cho は Gt と Cho の2つ）
    $picked = [];  // エラーで画面に戻すとき用の [名前, 楽器欄の値]
    foreach ((array)($_POST['m_name'] ?? []) as $i => $memberName) {
        $memberName = member_display((string)$memberName);
        $choice = $_POST['m_inst'][$i] ?? '';
        $cho = ($_POST['m_cho'][$i] ?? '') === '1'; // Cho のトグル（Gt/Cho）。m_inst[] と同じ番号の行
        if ($memberName === '') {
            continue;
        }
        $choice2 = $_POST['m_inst2'][$i] ?? ''; // 2つ目の楽器（空 = なし）
        $choice2 = is_string($choice2) ? $choice2 : '';
        $picked[] = ['name' => $memberName, 'choice' => is_string($choice) ? $choice : '', 'cho' => $cho, 'choice2' => $choice2];
        foreach ([...instruments_for_choice($choice, $cho), ...($choice2 !== '' ? instruments_for_choice($choice2) : [])] as $inst) {
            $rows[] = [$memberName, $inst]; // 同じ楽器が重なっても sync_band_members のキーで1つになる
        }
    }

    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = 'バンド名は1〜100文字で入力してください';
    }
    // 曲数は任意。空欄なら NULL（不明）で保存する。0 は「0曲」の意味なので空欄と分ける（migrations/014）
    if ($songs !== '' && (!ctype_digit($songs) || (int)$songs > 255)) {
        $errors[] = '曲数を0〜255の数字で入力してください';
    }
    foreach ([$start, $end] as $t) {
        if ($t !== '' && !preg_match('/^\d{2}:\d{2}$/', $t)) {
            $errors[] = '時刻が正しくありません';
        }
    }
    if ($start !== '' && $end !== '' && $end <= $start) {
        $errors[] = '終了時刻は開始時刻より後にしてください'; // DB の CHECK 制約 ck_band_time と同じルール
    }
    if (mb_strlen($note) > 255) {
        $errors[] = 'メモは255文字以内にしてください';
    }
    // 添付画像: 削除にチェックされた分を引いて、新しく選んだ分を足して5枚まで
    $removeIds = array_values(array_intersect(array_map('intval', array_column($images, 'image_id')), array_map('intval', (array)($_POST['image_remove'] ?? []))));
    $uploads = live_image_uploads($_FILES['images'] ?? null);
    if (count($images) - count($removeIds) + count($uploads) > ATTACH_KINDS['band']['max']) {
        $errors[] = '画像は ' . ATTACH_KINDS['band']['max'] . ' 枚までです';
    }
    // 空欄はOK（リンクなし）。入れたなら YouTube の https のリンクだけ受け付ける（live_edit.php と同じ）
    if ($youtube !== '' && !youtube_url_valid($youtube)) {
        $errors[] = 'YouTube のリンクは https://www.youtube.com/… か https://youtu.be/… の形で入力してください';
    }

    if (!$errors) {
        $index = load_member_index($pdo);
        // アーティスト欄が空ならバンド名から推測（「ヨルシカ（安田）」→ ヨルシカ）
        // オムニバスのバンドはコピー元アーティストを持たない（欄は入力できないので、送られてきても見ない）
        $artistId = !empty($band['is_omnibus']) ? null : find_or_create_artist($pdo, $artistName !== '' ? $artistName : $name);
        $values = [$artistId, $name, $songs !== '' ? (int)$songs : null, $start ?: null, $end ?: null, $note !== '' ? $note : null, $youtube !== '' ? $youtube : null, $flag];

        $newFiles = [];
        $pdo->beginTransaction();
        try {
            // 変更履歴: 編集なら変更前の状態を残す。追加なら「作った」ことを残す（元に戻す = 消す）
            $op = change_begin($pdo, $isNew ? 'band_add' : 'band_edit', '「' . $name . '」を' . ($isNew ? '追加' : '更新'), (int)$band['live_id']);
            if ($isNew) {
                // 差し込む位置 $position は 1〜(既存の数 + 1)（renumber_bands と同じ丸め）。数えるのは INSERT の前
                $st = $pdo->prepare('SELECT COUNT(*) FROM band WHERE live_day_id = ?');
                $st->execute([$dayId]);
                $position = max(1, min($order, (int)$st->fetchColumn() + 1));
                // ほかのバンドの時刻も動かすので、日程・休憩・全バンドの「変更前」を残す（この後の change_created はそのまま）
                change_capture_day($pdo, $op, $dayId);

                // 新しいバンドの時刻: 両方入力 → その値 / 両方空 → 直前の行の終了から、隣のバンドと同じ持ち時間で自動 / 片方だけ → そのまま（追随しない）
                $newStart = $start !== '' ? $start : null;
                $newEnd = $end !== '' ? $end : null;
                if ($start === '' && $end === '') {
                    $st = $pdo->prepare('SELECT COUNT(*) FROM band WHERE live_day_id = ? AND (start_time IS NOT NULL OR end_time IS NOT NULL)');
                    $st->execute([$dayId]);
                    if ((int)$st->fetchColumn() > 0) { // その日程に時刻の入ったバンドがあるときだけ
                        // 隣のバンド: 1つ前（位置 1 のときは、今の1番）
                        $st = $pdo->prepare('SELECT start_time, end_time FROM band WHERE live_day_id = ? AND play_order = ?');
                        $st->execute([$dayId, max(1, $position - 1)]);
                        $near = $st->fetch() ?: ['start_time' => null, 'end_time' => null];
                        // 差し込む場所の直前に並ぶ休憩のうち、いちばん遅い終了
                        $st = $pdo->prepare('SELECT MAX(end_time) FROM live_break WHERE live_day_id = ? AND after_order = ?');
                        $st->execute([$dayId, $position - 1]);
                        $breakEnd = $st->fetchColumn();
                        // 開始 = 直前の行の終了（バンドの終了と休憩の終了の遅い方。位置 1 なら 今の1番の開始と、その前の休憩の終了の遅い方）
                        //   'HH:MM:SS' の文字列は、そのまま max で時刻の早い遅いを比べられる
                        $before = array_filter([$position > 1 ? $near['end_time'] : $near['start_time'], $breakEnd === false ? null : $breakEnd], static fn($t) => $t !== null);
                        $nearLen = time_to_sec($near['end_time']) !== null && time_to_sec($near['start_time']) !== null
                            ? time_to_sec($near['end_time']) - time_to_sec($near['start_time']) : null; // 隣の持ち時間（秒）
                        if ($before && $nearLen !== null && $nearLen > 0) {
                            $newStart = max($before);
                            $newEnd = shift_time_by($newStart, $nearLen);
                            if ($newEnd === false) {
                                throw new RuntimeException('日付をまたぐので追加できません');
                            }
                        }
                    }
                }
                // 開始と終了が決まったら、後ろのバンド・休憩をその持ち時間の分だけ繰り下げる（INSERT の前なので、新しいバンドは動かない）
                if ($newStart !== null && $newEnd !== null && !shift_day_times($pdo, $dayId, $position, $position, time_to_sec($newEnd) - time_to_sec($newStart))) {
                    throw new RuntimeException('日付をまたぐので追加できません'); // 下の catch で取り消して、フォームに戻す
                }
                shift_breaks_for_insert($pdo, $dayId, $position); // 休憩の「どのバンドの後か」も後ろへ送る（INSERT の前）
                $values[3] = $newStart; // $values の並びは start_time, end_time が 4・5 番目
                $values[4] = $newEnd;
                // 出演順は UNIQUE なので、いったん最後尾に入れてから renumber_bands で正しい位置に動かす
                $pdo->prepare('INSERT INTO band (artist_id, name, song_count, start_time, end_time, note, youtube_url, needs_check, live_day_id, play_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$values, $dayId, next_play_order($pdo, $dayId)]);
                $bandId = (int)$pdo->lastInsertId();
                change_created($pdo, $op, 'band', $bandId);
            } else {
                change_capture($pdo, $op, 'band', $bandId);
                $pdo->prepare('UPDATE band SET artist_id = ?, name = ?, song_count = ?, start_time = ?, end_time = ?, note = ?, youtube_url = ?, needs_check = ?
                    WHERE band_id = ?')->execute([...$values, $bandId]);
            }
            // 差分だけ更新（全部消すと曲ごとの演奏記録が CASCADE で消えるため。sync_band_members の説明参照）
            sync_band_members($pdo, $index, $bandId, $rows);
            save_check_members($pdo, $bandId, $flag, $checkWho); // メンバーを入れ替えた後に（外した人は入らない）
            renumber_bands($pdo, $dayId, $bandId, $isNew ? $position : $order);
            if ($isNew) {
                sync_day_total_bands($pdo, $dayId); // バンドが増えて総バンド数を超えたら追いつかせる
            }
            $removeFiles = $isNew ? [] : attach_remove($pdo, 'band', $bandId, $removeIds);
            attach_add($pdo, 'band', $bandId, $uploads, (int)current_user()['user_id'], $newFiles);
            $pdo->commit();
            array_map('live_image_unlink', $removeFiles);
        } catch (RuntimeException $e) { // 画像のエラー（形式・大きさなど）。保存ごと取り消して、入力を表示し直す
            $pdo->rollBack();
            array_map('live_image_unlink', $newFiles);
            if ($isNew) {
                $bandId = 0;
            }
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            $pdo->rollBack();
            array_map('live_image_unlink', $newFiles);
            throw $e;
        }
    }
    if (!$errors) {
        flash('「' . $name . '」を' . ($isNew ? '追加' : '更新') . 'しました');
        redirect('band?id=' . $bandId);
    }
    // エラーのときは入力した値をそのまま表示し直す
    $band = array_merge($band, ['name' => $name, 'artist_name' => $artistName, 'song_count' => $songs, 'note' => $note, 'youtube_url' => $youtube, 'needs_check' => $flag,
        'play_order' => $order, 'start_time' => $start, 'end_time' => $end]);
    $members = $picked;
} elseif ($isNew) {
    $members = array_map(static fn($i) => ['name' => '', 'choice' => (string)$i], [1, 2, 3, 4]); // Vo Gt Ba Dr の空欄
} else {
    // 同じ人の2つ目の行は、1つ目の行の「2つ目の楽器」欄にまとめる（3つ目以降は別の行のまま）
    $members = [];
    $firstOf = []; // 名前 => $members の番号
    foreach (merge_vocal_roles($editableRows) as $m) {
        $k = $firstOf[$m['name']] ?? null;
        if ($k !== null && !isset($members[$k]['choice2']) && !$m['cho']) {
            $members[$k]['choice2'] = $m['choice'];
            continue;
        }
        $firstOf[$m['name']] ??= count($members);
        $members[] = $m;
    }
}
if (!$members) {
    $members[] = ['name' => '', 'choice' => '2']; // メンバー0人でも1行は出す（「＋ 行を追加」は最後の行をコピーして作るので）
}
$allNames = member_name_choices($pdo); // 名前の入力候補 [名前 => ふりがな]（lib/repository.php）
$allArtists = $pdo->query('SELECT name FROM artist ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$allBandNames = band_name_choices($pdo); // バンド名の入力候補（新しい順。lib/repository.php）

// ---------- ライブの YouTube がプレイリストなら、その動画から選べるプルダウンを出す ----------
//   後からバンドを追加したとき、URL を手で探して貼らなくて済むように。
//   並び: 候補（タイトルにバンド名が入っている）→ まだどのバンドにも入っていない動画 → ほかのバンドに入っている動画
//   プルダウンは選ぶと URL 欄に入れるだけ（assets/app.js）。保存するのは今までどおり URL 欄
$ytVideos = [];     // video_id => タイトル（プレイリストの順）
$ytError = null;    // 読めなかった理由（プルダウンの代わりに出す）
$ytCandidates = []; // 候補の video_id
$ytTaken = [];      // video_id => その動画が入っているほかのバンドの名前
$ytPlaylist = youtube_playlist_id((string)$band['live_youtube_url']);
if ($ytPlaylist !== null) {
    try {
        foreach (youtube_playlist_items($ytPlaylist) as $v) {
            $ytVideos[$v['video_id']] = $v['title'];
        }
    } catch (RuntimeException $e) {
        $ytError = $e->getMessage();
    }
}
if ($ytVideos) {
    $st = $pdo->prepare('SELECT b.name, b.youtube_url FROM band b JOIN live_day d ON d.live_day_id = b.live_day_id
        WHERE d.live_id = ? AND b.band_id <> ? AND b.youtube_url IS NOT NULL');
    $st->execute([(int)$band['live_id'], (int)$band['band_id']]);
    foreach ($st as $r) {
        $vid = youtube_video_id((string)$r['youtube_url']);
        if ($vid !== null) {
            $ytTaken[$vid] = $r['name'];
        }
    }
    // 名前が入っているとき（既存のバンド・保存に失敗して戻ったとき）だけ、タイトルにバンド名が入っている動画を候補にする
    //   探す名前は live_youtube.php と同じ: バンド名・アーティスト名・アーティストの別称
    if ($band['name'] !== '') {
        $names = [];
        if ($band['artist_name'] !== '' && $band['artist_name'] !== null) {
            $names[] = (string)$band['artist_name'];
            $st = $pdo->prepare('SELECT al.name FROM artist_alias al JOIN artist a ON a.artist_id = al.artist_id WHERE a.name = ?');
            $st->execute([$band['artist_name']]);
            array_push($names, ...$st->fetchAll(PDO::FETCH_COLUMN));
        }
        $list = [];
        foreach ($ytVideos as $vid => $title) {
            $list[] = ['video_id' => $vid, 'title' => $title];
        }
        $ytCandidates = youtube_match_bands($list, [0 => ['name' => $band['name'], 'names' => $names]])['bands'][0] ?? [];
    }
}
$ytCurrent = youtube_video_id((string)$band['youtube_url']); // 今の URL の動画（プレイリストにあれば選んだ状態にする）
/** プルダウンの1行。value は URL（こちらで組み立てる）、表示はタイトル */
$ytOption = static fn(string $vid, string $label) => '<option value="' . h(youtube_watch_url($vid)) . '"'
    . ($vid === $ytCurrent ? ' selected' : '') . '>' . h($label) . '</option>';

render_header($isNew ? 'バンドを追加' : 'バンドを編集', 'lives');
?>
<nav class="crumbs"><a href="<?= h($liveUrl) ?>"><?= h($band['live_name']) ?></a><span>/</span><?= h($band['label']) ?></nav>
<h1 class="display display--sm"><?= $isNew ? 'バンドを追加' : 'バンドを編集' ?></h1>
<?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="card form-card" id="band-form"<?= unsaved_attr() ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="band_id" value="<?= (int)$band['band_id'] ?>">
    <input type="hidden" name="day_id" value="<?= $dayId ?>">
    <div class="form-grid">
        <label class="field field--wide"><span>バンド名（タイムテーブルの表記）</span><input name="name" value="<?= h($band['name']) ?>" data-suggest-list="band-names" autocomplete="off" maxlength="100" required <?= $isNew ? 'autofocus' : '' ?>></label>
        <!-- オムニバスのバンド（曲の編集でチェック）はコピー元アーティストを持たないので、空欄で入力できなくする -->
        <?php if (!empty($band['is_omnibus'])): ?>
            <label class="field field--wide"><span>コピー元アーティスト</span><input value="" placeholder="オムニバス" disabled title="曲の編集で「オムニバス」を外すと入力できます"></label>
        <?php else: ?>
            <label class="field field--wide"><span>コピー元アーティスト</span><input name="artist" value="<?= h($band['artist_name']) ?>" data-suggest-list="artists" autocomplete="off" maxlength="100"></label>
        <?php endif; ?>
        <label class="field"><span>出演順</span><input type="number" min="1" name="play_order" value="<?= h($band['play_order']) ?>" required></label>
        <label class="field"><span>曲数</span><input type="number" min="0" max="255" name="song_count" value="<?= h((string)$band['song_count']) ?>"></label>
        <label class="field"><span>開始</span><input type="time" step="300" name="start_time" value="<?= h(fmt_time($band['start_time'])) ?>"></label>
        <label class="field"><span>終了</span><input type="time" step="300" name="end_time" value="<?= h(fmt_time($band['end_time'])) ?>"></label>
        <label class="field field--wide"><span>メモ</span><input name="note" value="<?= h($band['note']) ?>" maxlength="255"></label>
        <div class="field field--wide"><span>画像の追加・削除</span><?= attach_field_html('band', $images) ?></div>
        <label class="field field--wide"><span>YouTube のリンク（任意）</span><span class="field__hint"><a href="live_edit?id=<?= (int)$band['live_id'] ?>">ライブを編集</a>からプレイリストを追加すると各バンドのリンクを一括で登録できます</span><input type="url" name="youtube_url" value="<?= h((string)$band['youtube_url']) ?>" maxlength="500" placeholder="https://www.youtube.com/watch?v=…" inputmode="url"></label>
        <?php if ($ytVideos): ?>
            <!-- name を付けない（送信しない）。選ぶと JS が上の URL 欄に入れる -->
            <label class="field field--wide"><span>ライブのプレイリストから選ぶ</span>
                <select data-yt-pick>
                    <option value="">— 動画を選ぶ —</option>
                    <?php if ($ytCandidates): ?>
                        <optgroup label="候補（タイトルにバンド名が入っている）">
                            <?php foreach ($ytCandidates as $vid): ?><?= $ytOption($vid, $ytVideos[$vid]) ?><?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <optgroup label="まだどのバンドにも入っていない動画">
                        <?php foreach ($ytVideos as $vid => $title): if (in_array($vid, $ytCandidates, true) || isset($ytTaken[$vid])) continue; ?><?= $ytOption($vid, $title) ?><?php endforeach; ?>
                    </optgroup>
                    <optgroup label="ほかのバンドに入っている動画">
                        <?php foreach ($ytVideos as $vid => $title): if (in_array($vid, $ytCandidates, true) || !isset($ytTaken[$vid])) continue; ?><?= $ytOption($vid, $title . ' — ' . $ytTaken[$vid]) ?><?php endforeach; ?>
                    </optgroup>
                </select>
            </label>
        <?php elseif ($ytError !== null): ?>
            <p class="field--wide muted small">ライブのプレイリストを読めなかったので、動画から選べません（<?= h($ytError) ?>）。リンクを直接貼ってください。</p>
        <?php endif; ?>
    </div>
    <?= render_suggest_datalist('band-names', $allBandNames) ?>
    <datalist id="artists"><?php foreach ($allArtists as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?></datalist>

    <h2 class="section-title">メンバー</h2>
    <p class="muted small">サポートメンバーを含めて出演者を全員登録してください。<br>セットリストを登録すると曲ごとの楽器の持ち替えも記録できます。</p>
    <!-- 🚩 楽器の確認待ち（取り込み・タイムテーブル編集と同じ部品）。付けるとバンドページ・メンバーのトップで知らせる。
         いま付いているときは、確認をうながす文を一緒に出す（外して保存 = 確認した） -->
    <div class="check-flag<?= $band['needs_check'] ? ' flash flash--warn' : '' ?>">
        <?php if ($band['needs_check']): ?><span>確認依頼： <?= h(implode(' / ', check_reason_labels((int)$band['needs_check']))) ?>。確認できたらフラッグを外して保存してください。</span><?php endif; ?>
        <?= check_picker('chk', (int)$band['needs_check'], $checkCandidates, $checkWho, '確認をメンバーにお願いする', $checkVocals) ?>
    </div>
    <?php if ($lockedMembers): ?>
        <!-- セトリに出ている人: 楽器はセトリから自動で決まるので、ここでは見るだけ（disabled なので送信もされない） -->
        <!-- ⚠ のポップアップの中身（JS が複製して出す。ここ自体は hidden で見えない） -->
        <div id="lock-tip" hidden data-tip-body>セットリストが登録されているので、ここでは編集できません。<a class="tip-link" href="songs_edit?band=<?= (int)$band['band_id'] ?>" data-leave-check="band-form">セットリスト編集画面</a>で編集してください。</div>
        <p class="muted small">セットリストに出ている人の楽器は、セットリストの内容から自動で設定されます。変更する場合は<a href="songs_edit?band=<?= (int)$band['band_id'] ?>">セットリストを編集</a>してください。</p>
        <div class="member-rows">
            <?php foreach ($lockedMembers as $m): ?>
                <div class="member-row-edit member-row-edit--locked">
                    <!-- 上の段: 名前 / 印、下の段: 楽器 / Cho（下の編集できる行と同じ並び） -->
                    <input value="<?= h($m['name']) ?>" disabled aria-label="名前" class="name-input">
                    <!-- ⚠ マウスを乗せる・押すと、下の #lock-tip（リンク付き）をポップアップで出す（assets/app.js の setupSetlistTip） -->
                    <button type="button" class="lock-warn" data-setlist-tip data-tip-from="#lock-tip" aria-label="編集できない理由を見る"><?= icon('warning') ?></button>
                    <select disabled aria-label="楽器"><?= instrument_choice_options($m['choice']) ?></select>
                    <?= chorus_toggle('', $m['cho'], $m['choice'], true) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="member-rows" data-rows>
        <?php foreach ($members as $m): ?>
            <div class="member-row-edit">
                <!-- 上の段: 名前 / ✕、下の段: 楽器 / Cho のトグル（2列のグリッドに上から順に入る。CSS の .member-row-edit） -->
                <input name="m_name[]" value="<?= h($m['name']) ?>" data-suggest-list="member-names" autocomplete="off" placeholder="名前" aria-label="名前" class="name-input" data-name-cell>
                <button type="button" class="btn btn--ghost btn--sm" data-remove-row aria-label="この行を削除"><?= icon('close') ?></button>
                <select name="m_inst[]" aria-label="楽器"><?= instrument_choice_options($m['choice']) ?></select>
                <?php $open2 = ($m['choice2'] ?? '') !== ''; ?>
                <div class="inst-tools">
                    <?= chorus_toggle('m_cho[]', $m['cho'] ?? false, $m['choice']) ?>
                    <button type="button" class="inst2-toggle<?= $open2 ? ' is-open' : '' ?>" data-inst2-toggle aria-label="2つ目の楽器" aria-expanded="<?= $open2 ? 'true' : 'false' ?>"><?= icon('add') ?></button>
                </div>
                <!-- 2つ目の楽器: ＋ で1つ目の楽器の下に開閉（閉じたら値は空 = なし） -->
                <div class="inst2-slot<?= $open2 ? ' is-open' : '' ?>" data-inst2-slot><div>
                    <select name="m_inst2[]" aria-label="2つ目の楽器" data-inst2><option value="">—</option><?= instrument_choice_options($m['choice2'] ?? '') ?></select>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn--ghost btn--sm" data-add-row>＋ メンバーを追加</button>
    <?= render_suggest_datalist('member-names', array_keys($allNames), $allNames) ?>

    <div class="form-actions">
        <?php if (!$isNew): ?><a class="btn btn--ghost" href="songs_edit?band=<?= (int)$band['band_id'] ?>" data-leave-check="band-form">セットリストを編集</a><?php endif; ?>
        <a class="btn btn--ghost" href="<?= h($backUrl) ?>">キャンセル</a>
        <button class="btn btn--primary" type="submit"><?= $isNew ? '追加する' : '保存する' ?></button>
    </div>
</form>

<?php if (!$isNew && is_admin()): ?>
    <!-- 削除は別フォーム（保存フォームの中に入れると、Enter キーで誤って削除が送られることがある） -->
    <form method="post" class="danger-zone" data-confirm="「<?= h($band['name']) ?>」を削除します。メンバーの出演記録も消えます。よろしいですか？">
        <?= csrf_field() ?>
        <input type="hidden" name="band_id" value="<?= (int)$band['band_id'] ?>">
        <input type="hidden" name="action" value="delete">
        <button class="btn btn--ghost btn--danger btn--sm" type="submit">このバンドを削除</button>
    </form>
<?php endif; ?>
<!-- 未保存の変更があるままページを移動しようとしたときの確認（JS の setupLeaveCheck が開く） -->
<dialog class="modal" data-leave-dialog aria-labelledby="leave-title">
    <form method="dialog" class="modal__body">
        <h3 id="leave-title" class="modal__title">保存されていません</h3>
        <p class="muted small">変更が保存されていません。このまま移動すると変更は失われます。よろしいですか？</p>
        <div class="form-actions">
            <button type="submit" class="btn btn--ghost" value="cancel">戻る</button>
            <button type="submit" class="btn btn--primary" value="leave">移動する</button>
        </div>
    </form>
</dialog>
<?php render_footer();
