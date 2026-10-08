<?php
/**
 * =====================================================================
 *  live_edit.php — ライブの新規追加 / 編集
 * =====================================================================
 *    live_edit.php          … 新しいライブを手入力で追加（タイムテーブルのファイルが無いとき用）
 *                              「＋ 日程を追加」ボタンで、2日目以降の日程もまとめて入れられる（xd[番号][...]）
 *    live_edit.php?id=ID    … ライブ名・年度・各日程の情報を直す。日程の追加もここ
 *
 *  live（1行）と live_day（日程の数だけ）をまとめて1つのフォームで更新する。
 *  日付は必須（年度を日付から決めるため）。会場は「分からなければ空欄」= NULL で保存する。
 *  バンドとメンバーは、保存したあとライブページの「＋ バンドを追加」（band_edit.php）から入れる。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/youtube.php';
require_login();

$pdo = db();
$liveId = (int)($_GET['id'] ?? $_POST['live_id'] ?? 0);
$isNew = $liveId === 0; // id が無ければ「新規追加」モード

// 今年度（4月始まり）。新規追加のときの年度の初期値
$thisYear = current_fiscal_year();

if ($isNew) {
    $live = ['live_id' => 0, 'fiscal_year' => $thisYear, 'name' => '', 'youtube_url' => null];
    $days = [];
} else {
    $st = $pdo->prepare('SELECT * FROM live WHERE live_id = ?');
    $st->execute([$liveId]);
    $live = $st->fetch();
    if (!$live) {
        http_response_code(404);
        exit('ライブが見つかりません');
    }
    // band_count: その日程に登録済みのバンド数。総バンド数はこれより少なくできない
    $st = $pdo->prepare('SELECT d.*, v.name AS venue_name,
            (SELECT COUNT(*) FROM band b WHERE b.live_day_id = d.live_day_id) AS band_count
        FROM live_day d LEFT JOIN venue v ON v.venue_id = d.venue_id
        WHERE d.live_id = ? ORDER BY d.held_on IS NULL, d.held_on, d.live_day_id');
    $st->execute([$liveId]);
    $days = $st->fetchAll();
}

/**
 * フォームの日程1つ分を取り出してチェックする。
 * 既存の日程（d[ID][...]）と追加する日程（nd[...]）の両方で同じチェックを使うので関数にしている。
 * @return array{0: array, 1: string[]}  [入力値, エラーメッセージの配列]
 */
function read_day_input(mixed $in, array $venues, array $labels, int $registered = 0): array
{
    $in = is_array($in) ? $in : []; // 改造されたリクエストで配列以外が来ても落ちないように
    $labelSel = (string)($in['label'] ?? '');            // 日程名 / '__new__'（新しく作る）
    $labelNew = trim((string)($in['label_new'] ?? ''));  // '__new__' のときの新しい日程名
    $date = (string)($in['held_on'] ?? '');
    $venueSel = (string)($in['venue_id'] ?? '');      // venue_id / 'new' / ''（未設定）
    $venueNew = trim((string)($in['venue_new'] ?? '')); // 'new' のときの新しい会場名
    $note = trim((string)($in['note'] ?? ''));
    $total = trim(mb_convert_kana((string)($in['total_bands'] ?? ''), 'n')); // 総バンド数。全角数字も受け付ける。空欄 = 未入力
    $errors = [];
    // 日程名: プルダウンで選んだ日程名 or 新規作成（lib/repository.php。取り込み画面と同じ）
    [$label, $labelError] = resolve_day_label($labelSel, $labelNew, $labels);
    if ($labelError !== null) {
        $errors[] = $labelError;
    }
    if ($date === '') {
        $errors[] = "「{$label}」の日付を入力してください";
    } elseif (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        $errors[] = "「{$label}」の日付が正しくありません";
    }
    // 会場: プルダウンで選んだ既存の会場 or 新規作成（取り込み画面と同じ）。後の処理は会場名で扱う
    $venue = '';
    if ($venueSel === 'new') {
        $venue = $venueNew;
        if ($venue === '' || mb_strlen($venue) > 50) {
            $errors[] = "「{$label}」の新しい会場名は1〜50文字で入力してください";
        }
    } elseif ($venueSel !== '') {
        // フォームの値は書き換えられる可能性があるので、本当にある会場か確かめる
        if (ctype_digit($venueSel) && isset($venues[(int)$venueSel])) {
            $venue = $venues[(int)$venueSel];
        } else {
            $errors[] = "「{$label}」の会場の選択が正しくありません";
            $venueSel = '';
        }
    }
    // 総バンド数: 登録済みより多いのはOK（タイムテーブルが一部しか無い日程用）、少ないのはNG
    if ($total !== '' && (!ctype_digit($total) || (int)$total > 999)) {
        $errors[] = "「{$label}」の総バンド数は 0〜999 の数字で入力してください";
    } elseif ($total !== '' && (int)$total < $registered) {
        $errors[] = "「{$label}」の総バンド数が登録済みのバンド数（{$registered}組）より少ないです。{$registered} 以上にするか、空欄にしてください";
    }
    // compact は ['label' => $label, ...] と同じ。*_sel / *_new はエラーで戻ったときの表示用
    return [compact('label', 'date', 'venue', 'note', 'total') + ['venue_sel' => $venueSel, 'venue_new' => $venueNew,
        'label_sel' => $labelSel, 'label_new' => $labelNew], $errors];
}

/** 会場のプルダウン（＋「新しい会場を作る」を選んだときだけ出る入力欄）。$prefix は d[ID] / nd */
function venue_field(string $prefix, string $sel, string $newName, array $venues): string
{
    $html = '<div class="field"><span>会場</span><select name="' . $prefix . '[venue_id]" aria-label="会場" data-venue-select>'
        . '<option value="">— 未設定 —</option>';
    foreach ($venues as $id => $name) {
        $html .= '<option value="' . (int)$id . '"' . ($sel === (string)$id ? ' selected' : '') . '>' . h($name) . '</option>';
    }
    $html .= '<option value="new"' . ($sel === 'new' ? ' selected' : '') . '>＋ 新しい会場を作る</option></select>'
        . '<input name="' . $prefix . '[venue_new]" value="' . h($newName) . '" maxlength="50" placeholder="新しい会場名"'
        . ' aria-label="新しい会場名" data-venue-new' . ($sel === 'new' ? '' : ' hidden') . '></div>';
    return $html;
}

/**
 * 日程名の欄（プルダウン ＋ 新しい日程名の入力欄 ＋ 警告）。$prefix は d[ID] / nd / xd[番号]
 * $attrs は select に付ける属性（追加する日程には data-new-day を足す）
 */
function day_label_field(string $prefix, string $sel, string $newName, array $labels, string $attrs = ''): string
{
    return '<div class="field"><span>日程名</span>' . day_label_control($prefix, $sel, $newName, $labels, ' data-day-label' . $attrs)
        . '<small class="merge-note merge-note--warn" data-overwrite-note hidden>⚠ 登録済の日程のため上書きされます</small>'
        . '<small class="merge-note merge-note--warn" data-dup-note hidden>⚠ 他の日程と重複しています</small></div>';
}

/**
 * 新規ライブの2つ目以降の日程の入力欄（「＋ 日程を追加」ボタンで増える）。$i は番号（<template> の中では __i__）
 * 名前を xd[番号][...] にして、PHP では $_POST['xd'] の配列として受け取る
 */
function extra_day_block(string $i, array $d, array $venues, array $labels): string
{
    $p = "xd[$i]";
    return '<div data-extra-day><h2 class="section-title">日程</h2><div class="form-grid">'
        . day_label_field($p, $d['label_sel'], $d['label_new'], $labels)
        . '<label class="field"><span>日付</span><input type="date" name="' . $p . '[held_on]" value="' . h($d['date']) . '" required></label>'
        . venue_field($p, $d['venue_sel'], $d['venue_new'], $venues)
        . total_bands_field($p, $d['total'], 0)
        . '<label class="field field--wide"><span>メモ</span><input name="' . $p . '[note]" value="' . h($d['note']) . '"></label>'
        . '</div><p class="day-actions"><button type="button" class="btn btn--ghost btn--danger btn--sm" data-extra-day-remove>この日程をやめる</button></p></div>';
}

/**
 * 日程の総バンド数の欄。$prefix は d[ID] / nd / xd[番号]
 * min に登録済みのバンド数を入れて、少ない数だとブラウザが送信前に止める（サーバーでも read_day_input でチェックする）
 */
function total_bands_field(string $prefix, string $value, int $registered): string
{
    return '<label class="field"><span>総バンド数（任意）</span>'
        . '<input type="number" name="' . $prefix . '[total_bands]" value="' . h($value) . '" min="' . $registered . '" max="999" step="1" inputmode="numeric"'
        . ' placeholder="' . $registered . '">'
        . '<small class="merge-note">登録済み ' . $registered . ' 組より少ない数は保存できません。</small></label>';
}

/** 日程1つ分の値を、SQL に渡す配列にする（空欄 → NULL） */
function day_params(PDO $pdo, array $in): array
{
    return [
        $in['label'],
        $in['date'],                                       // 必須（read_day_input でチェック済み）
        find_or_create_venue($pdo, $in['venue']),           // 空欄 → NULL
        $in['note'] !== '' ? $in['note'] : null,
        $in['total'] !== '' ? (int)$in['total'] : null,    // 総バンド数。空欄 → NULL
    ];
}

// 保存済みのリンクがプレイリストなら「バンドに割り当てる」ボタンを出す（live_youtube.php）
$savedPlaylist = !$isNew && youtube_playlist_id((string)$live['youtube_url']) !== null;

// 追加する日程の初期値: まだ使っていない最初の日程名（新規ライブなら「1日目」）
$usedLabels = array_column($days, 'label');
$freeLabels = array_values(array_diff(DAY_LABELS, $usedLabels));
$newDay = ['label' => $freeLabels[0] ?? DAY_LABELS[0], 'date' => '', 'venue' => '', 'note' => '', 'total' => '', 'add' => false,
    'venue_sel' => '', 'venue_new' => ''];
$newDay += ['label_sel' => $newDay['label'], 'label_new' => ''];

// 新規ライブで「＋ 日程を追加」ボタンで増やした日程（エラーで戻ったときに入力値を出し直す用）
$extraDays = [];

// 会場はプルダウンで選ばせる（表記ゆれ防止）。FETCH_KEY_PAIR で [venue_id => name] の形になる
$venues = $pdo->query('SELECT venue_id, name FROM venue ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);

// 日程名のプルダウンの中身: いつもの日程名 ＋ これまでに新しく作られた日程名（lib/repository.php）
$dayLabels = day_labels($pdo);

// 「他のライブに統合」で選んだライブ（統合しないなら null）
$mergeLive = null;

$errors = [];
if (is_post()) {
    verify_csrf();
    $name = trim((string)($_POST['name'] ?? $live['name'])); // 統合 ON のときはライブ名の欄は送られてこない
    $youtube = trim((string)($_POST['youtube_url'] ?? ($live['youtube_url'] ?? '')));

    // ---- 他のライブに統合（管理者のみ。統合先の日程・バンドを消すことがあるので） ----
    $merge = !$isNew && !empty($_POST['merge']);
    if ($merge && !is_admin()) {
        // ボタンは管理者にしか出さないが、リクエストを自作すれば送れてしまうのでサーバーでも止める
        http_response_code(403);
        exit('統合は管理者のみできます');
    }
    if ($merge) {
        // フォームの値は書き換えられる可能性があるので、本当に存在するライブか DB で確認する
        $mergeIdIn = (string)($_POST['merge_live_id'] ?? '');
        $st = $pdo->prepare("SELECT l.live_id, l.fiscal_year, l.name,
                GROUP_CONCAT(d.label ORDER BY d.label SEPARATOR '・') AS labels
            FROM live l LEFT JOIN live_day d ON d.live_id = l.live_id
            WHERE l.live_id = ? GROUP BY l.live_id, l.fiscal_year, l.name");
        $st->execute([ctype_digit($mergeIdIn) ? (int)$mergeIdIn : 0]);
        $mergeLive = $st->fetch() ?: null;
        if ($mergeLive === null || (int)$mergeLive['live_id'] === $liveId) {
            $errors[] = '統合先のライブを選んでください';
            $mergeLive = null;
        }
    } else {
        if ($name === '' || mb_strlen($name) > 50) {
            $errors[] = 'ライブ名は1〜50文字で入力してください';
        }
        // 空欄はOK（リンクなし）。入れたなら YouTube の https のリンクだけ受け付ける
        if ($youtube !== '' && !youtube_url_valid($youtube)) {
            $errors[] = 'YouTube のリンクは https://www.youtube.com/… か https://youtu.be/… の形で入力してください';
        }
    }

    $dayInputs = [];
    foreach ($days as $d) {
        [$in, $dayErrors] = read_day_input($_POST['d'][$d['live_day_id']] ?? [], $venues, $dayLabels, (int)$d['band_count']);
        $errors = array_merge($errors, $dayErrors);
        $dayInputs[(int)$d['live_day_id']] = $in;
    }

    // 追加する日程: 新規ライブなら必須。既存ライブでは「この日程を追加する」にチェックしたときだけ追加する
    // （日程名は選択式で常に値が入るので、「何か入力されたか」では判定できない）
    $nd = is_array($_POST['nd'] ?? null) ? $_POST['nd'] : [];
    $wantsNewDay = $isNew || !empty($nd['add']);
    [$newDay, $newDayErrors] = read_day_input($nd, $venues, $dayLabels);
    $newDay['add'] = $wantsNewDay;
    if ($wantsNewDay) {
        $errors = array_merge($errors, $newDayErrors);
    }
    // 新規ライブの2つ目以降の日程。番号は途中で消されて飛ぶことがあるので、値だけ順に読む
    //   リクエストを自作して大量に送られても困らないよう、20 日程より多い分は読まない
    if ($isNew && is_array($_POST['xd'] ?? null)) {
        foreach (array_slice($_POST['xd'], 0, 20) as $x) {
            [$in, $xErrors] = read_day_input($x, $venues, $dayLabels);
            $errors = array_merge($errors, $xErrors);
            $extraDays[] = $in;
        }
    }

    // 日程名の重複チェック（同じライブに「1日目」が2つは保存できない）
    //   プルダウンでは入れ替えのために一時的に重複させられるので、保存のときにここで止める
    $labels = array_column($dayInputs, 'label');
    if ($wantsNewDay) {
        $labels[] = $newDay['label'];
    }
    $labels = array_merge($labels, array_column($extraDays, 'label'));
    foreach (array_count_values($labels) as $label => $count) {
        if ($count > 1) {
            $errors[] = "日程名「{$label}」が{$count}つあります。別々の日程名にしてください";
        }
    }

    // 年度は入力させず、日程の日付から決める（取り込みと同じ。年度の入れ間違いが起きない）
    //   一番早い日付の年度にする（日付は必須。日程が1つも無いときだけ今の年度のまま）
    $dates = array_filter(array_column($dayInputs, 'date'));
    if ($wantsNewDay && $newDay['date'] !== '') {
        $dates[] = $newDay['date'];
    }
    $dates = array_merge($dates, array_filter(array_column($extraDays, 'date')));
    $year = $dates ? (fiscal_year_from_date(min($dates)) ?? (int)$live['fiscal_year']) : (int)$live['fiscal_year'];
    if (!$mergeLive && ($year < 1990 || $year > 2100)) { // live.fiscal_year の CHECK 制約と同じ範囲
        $errors[] = '日付の年が範囲外です（年度は日付から自動で決まります）';
    }

    if (!$errors && $mergeLive !== null) {
        // ---- 統合: このライブの日程を全部、統合先のライブへ移す ----
        $targetId = (int)$mergeLive['live_id'];
        $pdo->beginTransaction();
        try {
            // 統合先に同じ日程名があれば、統合先のその日程を消して置き換える（上書き）
            //   バンド・出演記録は ON DELETE CASCADE で一緒に消える。
            //   delete_live_day() は「日程が0になったライブも消す」ので、統合先が消えないようにここでは直接 DELETE する
            $findSame = $pdo->prepare('SELECT live_day_id FROM live_day WHERE live_id = ? AND label = ?');
            $deleteDay = $pdo->prepare('DELETE FROM live_day WHERE live_day_id = ?');
            $moving = array_column($dayInputs, 'label');
            if ($wantsNewDay) {
                $moving[] = $newDay['label'];
            }
            foreach (array_unique($moving) as $label) {
                $findSame->execute([$targetId, $label]);
                $sameId = $findSame->fetchColumn();
                if ($sameId !== false) {
                    $deleteDay->execute([(int)$sameId]);
                }
            }
            $move = $pdo->prepare('UPDATE live_day SET label = ?, held_on = ?, venue_id = ?, note = ?, total_bands = ?, live_id = ? WHERE live_day_id = ?');
            foreach ($dayInputs as $id => $in) {
                $move->execute([...day_params($pdo, $in), $targetId, $id]);
            }
            if ($wantsNewDay) {
                $pdo->prepare('INSERT INTO live_day (label, held_on, venue_id, note, total_bands, live_id) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([...day_params($pdo, $newDay), $targetId]);
            }
            // 日程が全部いなくなった元のライブを消す
            $pdo->prepare('DELETE FROM live WHERE live_id = ? AND NOT EXISTS (SELECT 1 FROM live_day WHERE live_id = ?)')
                ->execute([$liveId, $liveId]);
            $pdo->commit();
            flash('「' . $mergeLive['fiscal_year'] . '年度 ' . $mergeLive['name'] . '」に統合しました');
            redirect('live?id=' . $targetId);
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            // 移す日程どうしで日程名がかぶっている（例: 2つとも「1日目」）
            $errors[] = 'このライブの日程どうしで日程名が重複しています。別々の日程名にしてください';
        }
    } elseif (!$errors) {
        $pdo->beginTransaction();
        try {
            if ($isNew) {
                $pdo->prepare('INSERT INTO live (fiscal_year, name, youtube_url) VALUES (?, ?, ?)')
                    ->execute([$year, $name, $youtube !== '' ? $youtube : null]);
                $liveId = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE live SET fiscal_year = ?, name = ?, youtube_url = ? WHERE live_id = ?')
                    ->execute([$year, $name, $youtube !== '' ? $youtube : null, $liveId]);
            }
            // 「1日目 ⇄ 2日目」の入れ替えは、1行ずつ UPDATE すると途中で UNIQUE(live_id, label) にぶつかる
            //   → いったん全部の日程名を重ならない仮の名前（#日程ID）にしてから、本当の値を入れる
            $tmp = $pdo->prepare("UPDATE live_day SET label = CONCAT('#', live_day_id) WHERE live_day_id = ?");
            foreach (array_keys($dayInputs) as $id) {
                $tmp->execute([$id]);
            }
            $update = $pdo->prepare('UPDATE live_day SET label = ?, held_on = ?, venue_id = ?, note = ?, total_bands = ? WHERE live_day_id = ?');
            foreach ($dayInputs as $id => $in) {
                $update->execute([...day_params($pdo, $in), $id]); // ...（スプレッド構文）で配列を展開して、最後に id を足す
            }
            $insertDay = $pdo->prepare('INSERT INTO live_day (label, held_on, venue_id, note, total_bands, live_id) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($wantsNewDay ? [$newDay, ...$extraDays] : [] as $in) {
                $insertDay->execute([...day_params($pdo, $in), $liveId]);
            }
            $pdo->commit();
            flash($isNew ? 'ライブを追加しました。次は「＋ バンドを追加」から出演バンドを入れよう' : 'ライブ情報を更新しました');
            // プレイリストのリンクを新しく貼った（変えた）ら、そのまま動画をバンドに割り当てる画面へ
            //   バンドがまだいない（新規ライブ）なら割り当てる先がないので、いつもどおりライブページへ
            if (!$isNew && $youtube !== (string)$live['youtube_url'] && youtube_playlist_id($youtube) !== null && $days) {
                redirect('live_youtube?id=' . $liveId);
            }
            redirect('live?id=' . $liveId);
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($isNew) {
                $liveId = 0; // ロールバックで INSERT は取り消されているので、新規モードに戻す
            }
            // 23000 = 一意制約違反（UNIQUE）。「同じ年度に同じ名前のライブ」「同じライブに同じ日程名」が既にある
            // → DB の制約にチェックを任せて、引っかかったらメッセージにする
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $errors[] = '同じ年度・名前のライブ、または同じ日程名がすでにあります';
        }
    }
    // エラー時は入力値で表示し直す
    $live = array_merge($live, ['fiscal_year' => $year, 'name' => $name, 'youtube_url' => $youtube]);
    foreach ($days as &$d) {
        $in = $dayInputs[(int)$d['live_day_id']];
        $d = array_merge($d, ['label' => $in['label'], 'label_sel' => $in['label_sel'], 'label_new' => $in['label_new'], 'held_on' => $in['date'], 'venue_name' => $in['venue'], 'venue_sel' => $in['venue_sel'], 'venue_new' => $in['venue_new'],
            'note' => $in['note'], 'total_bands' => $in['total']]);
    }
    unset($d);
}

$canMerge = !$isNew && is_admin(); // 「他のライブに統合」ボタンは管理者だけに出す
$merging = $canMerge && is_post() && !empty($_POST['merge']); // エラーで戻ったときもトグル ON のまま見せる
if ($canMerge) {
    $lives = lives_with_labels($pdo); // 統合先のポップアップ用
    $excludeLiveId = $liveId;         // 自分自身には統合させない
}
if ($isNew) {
    // 新規追加は「新規追加」ページの1タブとして見せる（見出しとタブは共通パーツ）
    render_header('新規追加', 'import');
    $addTab = 'live';
    require __DIR__ . '/partials/add_tabs.php';
} else {
    render_header('ライブを編集', 'lives'); ?>
    <nav class="crumbs"><a href="live?id=<?= $liveId ?>"><?= h($live['name']) ?></a><span>/</span>編集</nav>
    <h1 class="display display--sm">ライブを編集</h1>
<?php } ?>
<?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" class="card form-card" id="live-form" data-same-year><!-- 日付の年を変えると、ほかの日程の年もそろう（assets/app.js の setupSameYear） -->
    <?= csrf_field() ?>
    <input type="hidden" name="live_id" value="<?= $liveId ?>">
    <?php if (!$canMerge): ?>
        <div class="form-grid">
            <label class="field field--wide"><span>ライブ名</span><input name="name" value="<?= h($live['name']) ?>" maxlength="50" placeholder="例: 9月ライブ" required></label>
            <label class="field field--wide"><span>YouTubeプレイリストのリンク（任意）</span><input type="url" name="youtube_url" value="<?= h((string)$live['youtube_url']) ?>" maxlength="500" placeholder="https://www.youtube.com/playlist?list=…" inputmode="url"></label>
            <?php if ($savedPlaylist): ?><p class="field--wide yt-assign"><a class="btn btn--ghost btn--sm" href="live_youtube?id=<?= $liveId ?>"><?= youtube_icon() ?> プレイリストの動画をバンドに割り当てる</a></p><?php endif; ?>
        </div>
    <?php else: ?>
        <!-- 取り込み画面と同じ「統合」トグル（assets/app.js の setupMergeToggle / setupLiveEditMerge）
             ON: 年度・ライブ名の欄を隠して disabled（送信されない）にし、統合先のライブを選ばせる -->
        <?php require __DIR__ . '/partials/live_picker.php'; ?>
        <div class="form-grid" data-live-merge>
            <div class="field field--wide" data-merge data-year="<?= (int)$live['fiscal_year'] ?>">
                <span class="live-name-head">ライブ名
                    <button type="button" class="merge-toggle" aria-pressed="<?= $merging ? 'true' : 'false' ?>" data-merge-toggle><?= icon('merge') ?> 他のライブに統合</button>
                </span>
                <input name="name" value="<?= h($live['name']) ?>" maxlength="50" placeholder="例: 9月ライブ" required data-live-name<?= $merging ? ' hidden disabled' : '' ?>>
                <!-- disabled の欄は送信されない → OFF のときは merge / merge_live_id を送らない -->
                <input type="hidden" name="merge" value="1" data-merge-flag<?= $merging ? '' : ' disabled' ?>>
                <input type="hidden" name="merge_live_id" value="<?= $mergeLive ? (int)$mergeLive['live_id'] : '' ?>" data-live-id<?= $merging ? '' : ' disabled' ?>>
                <div class="live-pick" data-live-pick-wrap<?= $merging ? '' : ' hidden' ?>>
                    <button type="button" class="live-pick__btn" aria-haspopup="listbox" aria-expanded="false" data-live-pick
                        data-year="<?= $mergeLive ? (int)$mergeLive['fiscal_year'] : '' ?>" data-labels="<?= $mergeLive ? h((string)$mergeLive['labels']) : '' ?>"
                        data-name="<?= $mergeLive ? h($mergeLive['name']) : '' ?>">
                        <span data-live-pick-text<?= $mergeLive ? '' : ' class="is-placeholder"' ?>><?= $mergeLive ? h($mergeLive['fiscal_year'] . '年度 ' . $mergeLive['name']) : 'ライブを選択' ?></span>
                        <?= icon('expand_more') ?>
                    </button>
                </div>
                <small class="merge-note" data-merge-note></small>
            </div>
            <!-- 統合するとこのライブは消えるので、統合 ON のときは隠す（data-merge-hide） -->
            <label class="field field--wide" data-merge-hide<?= $merging ? ' hidden' : '' ?>><span>YouTubeプレイリストのリンク（任意）</span><input type="url" name="youtube_url" value="<?= h((string)$live['youtube_url']) ?>" maxlength="500" placeholder="https://www.youtube.com/watch?v=…" inputmode="url"<?= $merging ? ' disabled' : '' ?>></label>
            <?php if ($savedPlaylist): ?><p class="field--wide yt-assign" data-merge-hide<?= $merging ? ' hidden' : '' ?>><a class="btn btn--ghost btn--sm" href="live_youtube?id=<?= $liveId ?>"><?= youtube_icon() ?> プレイリストの動画をバンドに割り当てる</a></p><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php foreach ($days as $d): $id = (int)$d['live_day_id']; ?>
        <h2 class="section-title"><?= h($d['label'] ?: '日程') ?></h2>
        <div class="form-grid">
            <?= day_label_field("d[$id]", $d['label_sel'] ?? (string)$d['label'], $d['label_new'] ?? '', $dayLabels) ?>
            <label class="field"><span>日付</span><input type="date" name="d[<?= $id ?>][held_on]" value="<?= h($d['held_on']) ?>" required></label>
            <?= venue_field("d[$id]", $d['venue_sel'] ?? (string)$d['venue_id'], $d['venue_new'] ?? '', $venues) ?>
            <?= total_bands_field("d[$id]", (string)$d['total_bands'], (int)$d['band_count']) ?>
            <label class="field field--wide"><span>メモ</span><input name="d[<?= $id ?>][note]" value="<?= h($d['note']) ?>"></label>
        </div>
        <p class="day-actions">
            <a class="btn btn--ghost btn--sm" href="band_edit?day=<?= $id ?>">＋ この日程にバンドを追加</a>
            <?php if (is_admin()): ?>
                <!-- form の中に form は入れられない（HTML のルール）ので、削除フォームは下の </form> の外に置き、
                     form="del-day-ID" 属性でボタンとつなぐ -->
                <button class="btn btn--ghost btn--danger btn--sm" type="submit" form="del-day-<?= $id ?>">この日程を削除</button>
            <?php endif; ?>
        </p>
    <?php endforeach; ?>

    <!-- 追加する日程。新規ライブでは最初の日程（必須）。
         既存ライブでは「＋ 日程を追加」のチェックボックスだけ見せ、チェックすると入力欄が開く（assets/app.js の setupNewDayToggle） -->
    <?php if ($isNew): ?>
        <h2 class="section-title">日程</h2>
    <?php else: ?>
        <label class="new-day-toggle"><input type="checkbox" name="nd[add]" value="1" data-new-day-add<?= $newDay['add'] ? ' checked' : '' ?>> ＋ 日程を追加</label>
    <?php endif; ?>
    <div class="form-grid" data-new-day-fields<?= !$isNew && !$newDay['add'] ? ' hidden' : '' ?>>
        <?= day_label_field('nd', $newDay['label_sel'], $newDay['label_new'], $dayLabels, ' data-new-day') ?>
        <label class="field"><span>日付</span><input type="date" name="nd[held_on]" value="<?= h($newDay['date']) ?>"<?= $isNew || $newDay['add'] ? ' required' : '' ?>></label>
        <?= venue_field('nd', $newDay['venue_sel'], $newDay['venue_new'], $venues) ?>
        <?= total_bands_field('nd', $newDay['total'], 0) ?>
        <label class="field field--wide"><span>メモ</span><input name="nd[note]" value="<?= h($newDay['note']) ?>"></label>
    </div>
    <?php if ($isNew): ?>
        <!-- 2つ目以降の日程。「＋ 日程を追加」で <template> を複製して増やす（assets/app.js の setupExtraDays） -->
        <div data-extra-days><?php foreach ($extraDays as $i => $x) echo extra_day_block((string)$i, $x, $venues, $dayLabels); ?></div>
        <template id="extra-day-tpl"><?= extra_day_block('__i__', ['label_sel' => '', 'label_new' => '', 'date' => '', 'venue_sel' => '', 'venue_new' => '', 'note' => '', 'total' => ''], $venues, $dayLabels) ?></template>
        <p class="day-actions"><button type="button" class="btn btn--ghost btn--sm" data-extra-day-add>＋ 日程を追加</button></p>
    <?php endif; ?>

    <div class="form-actions">
        <?php if (!$isNew): ?><a class="btn btn--ghost" href="live?id=<?= $liveId ?>">キャンセル</a><?php endif; ?>
        <button class="btn btn--primary" type="submit"><?= $isNew ? '追加する' : '保存する' ?></button>
    </div>
</form>
<?php if (is_admin()): foreach ($days as $d): ?>
    <form method="post" action="live_delete" id="del-day-<?= (int)$d['live_day_id'] ?>" hidden data-dirty-check="live-form" data-confirm="<?= h($d['label'] . ' のデータを削除します。元に戻せません。よろしいですか？') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="live_day_id" value="<?= (int)$d['live_day_id'] ?>">
    </form>
<?php endforeach; endif; ?>
<?php render_footer();
