<?php
/**
 * =====================================================================
 *  live_drive.php?id=ライブID — ドライブのフォルダのラインミックスを、そのライブのバンドに割り当てる
 * =====================================================================
 *  live_youtube.php（プレイリスト → バンド）のドライブ版。作りも画面もほぼ同じ。
 *    1. ライブのフォルダのリンク（live.drive_url）からフォルダ ID を取り出す
 *    2. フォルダの中の音声・動画ファイルを取ってくる（lib/drive.php の drive_folder_files）
 *    3. ファイル名にバンド名が入っているかで結び付ける（drive_match_bands）
 *    4. 確認画面を出す。いきなり保存はしない（間違って上書きされても気づけないので）
 *
 *  選択肢の初期値:
 *    1. 今入っているリンク（前に人が選んだものを勝手に変えない）
 *    2. 迷わず決まったファイル（候補が1つだけ）
 *    3. どちらも無ければ「なし」（候補が2つ以上のバンドは黄色くして、人に選ばせる）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/drive.php';
require_once __DIR__ . '/lib/history.php'; // 変更履歴（元に戻すための「変更前の状態」を残す）
require_login();

$pdo = db();
// (int) で型をそろえる。数字以外が来ても 0 になり、下の SELECT で「見つからない」になる
$liveId = (int)($_GET['id'] ?? $_POST['live_id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM live WHERE live_id = ?'); // プリペアドステートメント（SQL インジェクション対策）
$st->execute([$liveId]);
$live = $st->fetch();
if (!$live) {
    error_page(404, 'ライブが見つかりません');
}

// このライブのバンド（日程順 → 出演順）。キーを band_id にしておくと、あとで $bands[$id] で引ける
$st = $pdo->prepare('SELECT b.band_id, b.name, b.play_order, b.drive_url, b.artist_id, a.name AS artist_name, d.label
    FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    LEFT JOIN artist a ON a.artist_id = b.artist_id
    WHERE d.live_id = ? ORDER BY d.held_on IS NULL, d.held_on, d.live_day_id, b.play_order');
$st->execute([$liveId]);
$bands = [];
foreach ($st as $b) {
    $bands[(int)$b['band_id']] = $b;
}

// ---------- 保存 ----------
if (is_post()) {
    verify_csrf(); // CSRF 対策: フォームに埋めた合言葉（トークン）が合っているか
    // f[band_id] = 'keep'（今のまま） / ''（なし） / ファイル ID
    $picks = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
    $update = $pdo->prepare('UPDATE band SET drive_url = ? WHERE band_id = ?');
    $changed = 0;
    $op = null; // 変更履歴。実際に変わるバンドがあったときだけ作る
    // トランザクション: 全部成功したら確定（commit）、途中で失敗したら全部取り消し（rollBack）
    $pdo->beginTransaction();
    try {
        // 回すのは DB から取った「このライブのバンド」だけ（POST の band_id を書き換えて他のライブのバンドを変えさせない）
        foreach ($bands as $id => $b) {
            $pick = $picks[$id] ?? 'keep';
            if (!is_string($pick) || $pick === 'keep') {
                continue;
            }
            if ($pick === '') {
                $url = null;
            } elseif (drive_id_valid($pick)) {
                $url = drive_file_url($pick); // URL はこちらで組み立てる（送られてきた文字列をそのまま保存しない）
            } else {
                continue; // 改造されたリクエスト。黙って無視する
            }
            if ($url !== $b['drive_url']) {
                $op ??= change_begin($pdo, 'drive', 'バンドのラインミックス', $liveId); // ??= は「null のときだけ代入」
                change_capture($pdo, $op, 'band', $id); // 変更前のリンクを履歴に残す
                $update->execute([$url, $id]);
                $changed++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    flash($changed ? "{$changed} バンドのラインミックスを更新しました" : 'ラインミックスは変更なし');
    redirect('live?id=' . $liveId);
}

// ---------- フォルダを読んで割り当てる ----------
$error = null;
$files = [];  // file_id => 表示名（拡張子なし）
$match = ['bands' => [], 'auto' => [], 'unmatched' => []];
$folderId = drive_folder_id((string)$live['drive_url']);
if ($folderId === null) {
    $error = 'このライブにドライブのフォルダのリンク（…/drive/folders/…）が入っていません。ライブを編集で追加してください';
} elseif (!$bands) {
    $error = 'このライブにはまだバンドが登録されていません';
} else {
    try {
        foreach (drive_folder_files($folderId) as $f) {
            $files[$f['file_id']] = drive_strip_ext($f['name']);
        }
        if (!$files) {
            $error = 'フォルダに音声・動画ファイルがありません';
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

if ($error === null) {
    // ファイル名の中で探す名前: バンド名（lib 側で括弧の前を使う）・アーティスト名・アーティストの別称（エルレ → ELLEGARDEN など）
    //   live_youtube.php と同じ。別称は全バンド分を1回の SQL で取る（バンドごとに SQL を投げない = N+1 問題の回避）
    $st = $pdo->prepare('SELECT DISTINCT al.artist_id, al.name FROM artist_alias al
        JOIN band b ON b.artist_id = al.artist_id
        JOIN live_day d ON d.live_day_id = b.live_day_id
        WHERE d.live_id = ?');
    $st->execute([$liveId]);
    $aliases = [];
    foreach ($st as $r) {
        $aliases[(int)$r['artist_id']][] = $r['name'];
    }
    $input = [];
    foreach ($bands as $id => $b) {
        // ...（スプレッド構文）で別称の配列を展開して、アーティスト名の後ろに並べる
        $names = $b['artist_id'] ? [(string)$b['artist_name'], ...($aliases[(int)$b['artist_id']] ?? [])] : [];
        $input[$id] = ['name' => $b['name'], 'names' => $names];
    }
    $list = [];
    foreach ($files as $id => $name) {
        $list[] = ['file_id' => $id, 'name' => $name];
    }
    $match = drive_match_bands($list, $input);
}

// バンドごとの初期値と状態を決める
$rows = [];
$used = []; // 初期値でどこかのバンドに入ったファイル
foreach ($bands as $id => $b) {
    $current = $b['drive_url'] !== null ? drive_file_id($b['drive_url']) : null;
    $candidates = $match['bands'][$id] ?? [];
    if ($b['drive_url'] !== null && $b['drive_url'] !== '') {
        // 今のリンクを優先。フォルダのファイルならそのファイルを選んだ状態、違えば「今のまま」
        $selected = $current !== null && isset($files[$current]) ? $current : 'keep';
        $status = 'current';
    } elseif (isset($match['auto'][$id])) {
        $selected = $match['auto'][$id];
        $status = 'auto';
    } else {
        $selected = '';
        $status = $candidates ? 'pick' : 'none';
    }
    if (isset($files[$selected])) {
        $used[$selected] = true;
    }
    $rows[$id] = ['band' => $b, 'candidates' => $candidates, 'selected' => $selected, 'status' => $status];
}
$leftover = array_diff_key($files, $used); // どのバンドにも入っていないファイル（確認用に下に並べる）
$autoCount = count(array_filter($rows, fn($r) => $r['status'] === 'auto'));
$pickCount = count(array_filter($rows, fn($r) => $r['status'] === 'pick'));

// 状態 → [表示する文字, CSS のクラス]。表記は熟語で統一（CLAUDE.md）
const DRIVE_STATUS = [
    'current' => ['現在のまま', 'pill'],
    'auto'    => ['自動選択', 'pill pill--ok'],
    'pick'    => ['候補が複数', 'pill pill--warn'],
    'none'    => ['該当なし', 'pill muted'],
];

render_header('ラインミックスを割り当て', 'lives'); ?>
<nav class="crumbs"><a href="live?id=<?= $liveId ?>"><?= h($live['name']) ?></a><span>/</span><a href="live_edit?id=<?= $liveId ?>">編集</a><span>/</span>ラインミックス</nav>
<h1 class="display display--sm">ラインミックスをバンドに割り当て</h1>

<?php if ($error !== null): ?>
    <div class="flash flash--error"><?= h($error) ?></div>
    <p><a class="btn btn--ghost" href="live_edit?id=<?= $liveId ?>">ライブを編集に戻る</a></p>
<?php else: ?>
    <div class="flash flash--info">
        <?= count($files) ?> ファイル中 <?= $autoCount ?> バンドを自動で割り当て。
        <?php if ($pickCount): ?><strong><?= $pickCount ?> バンドは候補が複数あるので選択してください。</strong><?php endif; ?>
    </div>

    <!-- data-yt-form / data-yt-leftover などは live_youtube.php と同じ名前にして、
         「どのバンドにも入っていない一覧」を減らす JS（assets/app.js の setupYoutubeLeftover）をそのまま使う -->
    <form method="post" class="card form-card" data-yt-form<?= unsaved_attr() ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="live_id" value="<?= $liveId ?>">
        <div class="table-scroll table-scroll--yt">
            <table class="table table--yt">
                <thead><tr><th>日程</th><th>順</th><th>バンド名</th><th>ファイル</th><th>状態</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $id => $r): $b = $r['band']; [$statusText, $statusClass] = DRIVE_STATUS[$r['status']]; ?>
                    <tr<?= $r['status'] === 'pick' ? ' class="is-warn"' : '' ?>>
                        <td class="muted"><?= h($b['label']) ?></td>
                        <td class="num"><?= (int)$b['play_order'] ?></td>
                        <td><?= h($b['name']) ?></td>
                        <td>
                            <select name="f[<?= $id ?>]" aria-label="「<?= h($b['name']) ?>」のファイル">
                                <option value="">なし</option>
                                <?php if ($r['selected'] === 'keep'): ?>
                                    <option value="keep" selected>現在のリンクのまま</option>
                                <?php endif; ?>
                                <?php if ($r['candidates']): ?>
                                    <optgroup label="候補">
                                        <?php foreach ($r['candidates'] as $fid): ?>
                                            <option value="<?= h($fid) ?>"<?= $fid === $r['selected'] ? ' selected' : '' ?>><?= h($files[$fid]) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                                <optgroup label="フォルダのほかのファイル">
                                    <?php foreach ($files as $fid => $name): if (in_array($fid, $r['candidates'], true)) continue; ?>
                                        <option value="<?= h($fid) ?>"<?= $fid === $r['selected'] ? ' selected' : '' ?>><?= h($name) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </td>
                        <td><span class="<?= $statusClass ?>"><?= h($statusText) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- どのバンドにも選ばれていないファイル。全部出しておき、選ばれているものは hidden（JS が出し入れする） -->
        <section data-yt-leftover<?= $leftover ? '' : ' hidden' ?>>
            <h2 class="section-title">未割り当てのファイル（<span data-yt-left-count><?= count($leftover) ?></span>）</h2>
            <ul class="yt-leftover">
                <?php foreach ($files as $fid => $name): ?>
                    <li data-yt-video="<?= h($fid) ?>"<?= isset($leftover[$fid]) ? '' : ' hidden' ?>><a href="<?= h(drive_file_url($fid)) ?>" target="_blank" rel="noopener noreferrer"><?= h($name) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <div class="form-actions">
            <a class="btn btn--ghost" href="live?id=<?= $liveId ?>">キャンセル</a>
            <button class="btn btn--primary" type="submit">保存する</button>
        </div>
    </form>
<?php endif; ?>
<?php render_footer();
