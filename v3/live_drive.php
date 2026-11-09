<?php
/**
 * =====================================================================
 *  live_drive.php?id=ライブID — ドライブのフォルダの「バンド名のサブフォルダ」を、そのライブのバンドに割り当てる
 * =====================================================================
 *  live_youtube.php（プレイリスト → バンド）のドライブ版。作りも画面もほぼ同じ。
 *    1. ライブのフォルダのリンク（live.drive_url）からフォルダ ID を取り出す
 *    2. 中のサブフォルダの一覧を取ってくる（lib/drive.php の drive_folder_children）
 *    3. サブフォルダ名にバンド名が入っているかで結び付ける（drive_match_bands）
 *    4. 確認画面を出す。いきなり保存はしない（間違って上書きされても気づけないので）
 *    5. 保存すると、選んだサブフォルダの中のファイルを全部、そのバンドに紐付ける（band_drive_file）
 *
 *  選択肢の初期値:
 *    1. 迷わず決まったサブフォルダ（候補が1つだけ）→ 中身を取り直す（後から足したファイルも入る）
 *    2. 決まらず、すでにファイルがあるバンドは「現在のまま」（前に人が選んだものを勝手に消さない）
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
$st = $pdo->prepare('SELECT b.band_id, b.name, b.play_order, b.artist_id, a.name AS artist_name, d.label
    FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    LEFT JOIN artist a ON a.artist_id = b.artist_id
    WHERE d.live_id = ? ORDER BY d.held_on IS NULL, d.held_on, d.live_day_id, b.play_order');
$st->execute([$liveId]);
$bands = [];
foreach ($st as $b) {
    $bands[(int)$b['band_id']] = $b;
}
$fileCounts = array_map('count', drive_load_files($pdo, array_keys($bands))); // band_id => 紐付け済みのファイル数

// ---------- 保存 ----------
if (is_post()) {
    verify_csrf(); // CSRF 対策: フォームに埋めた合言葉（トークン）が合っているか
    // f[band_id] = 'keep'（現在のまま） / ''（なし = 全部外す） / サブフォルダ ID
    $picks = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
    // 先に通信だけ済ませる（サブフォルダの中身を取る）。DB のトランザクションの中で待たない
    //   トランザクション中に何秒も通信すると、その間ほかの人の保存を待たせてしまうため
    $plan = []; // band_id => 紐付けるファイルの一覧
    $folderIdOk = []; // このライブのフォルダの直下にあるサブフォルダだけ受け付ける（POST を書き換えて他人のフォルダを入れさせない）
    $parent = drive_folder_id((string)$live['drive_url']);
    try {
        foreach ($parent !== null ? drive_folder_children($parent)['folders'] : [] as $fo) {
            $folderIdOk[$fo['file_id']] = true;
        }
        foreach ($bands as $id => $b) {
            $pick = $picks[$id] ?? 'keep';
            if (!is_string($pick) || $pick === 'keep') {
                continue;
            }
            if ($pick === '') {
                $plan[$id] = [];
            } elseif (isset($folderIdOk[$pick])) {
                $plan[$id] = drive_folder_children($pick)['files']; // サブフォルダの中のファイル（さらに下のフォルダは見ない）
            } // それ以外は改造されたリクエスト。黙って無視する
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect('live_drive?id=' . $liveId);
    }
    $changed = 0;
    $op = null; // 変更履歴。実際に変わるバンドがあったときだけ作る
    $current = drive_load_files($pdo, array_keys($plan));
    // トランザクション: 全部成功したら確定（commit）、途中で失敗したら全部取り消し（rollBack）
    $pdo->beginTransaction();
    try {
        foreach ($plan as $id => $files) {
            // 変わらないなら何もしない（file_id・名前の並びで比べる）
            $now = array_map(fn($f) => $f['file_id'] . '|' . $f['name'], $current[$id] ?? []);
            $new = array_map(fn($f) => $f['file_id'] . '|' . $f['name'], $files);
            if ($now === $new) {
                continue;
            }
            $op ??= change_begin($pdo, 'drive', 'バンドのドライブのファイル', $liveId); // ??= は「null のときだけ代入」
            change_capture($pdo, $op, 'band', $id); // 変更前の状態（紐付けていたファイルも）を履歴に残す
            drive_save_files($pdo, $id, $files);
            $changed++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    flash($changed ? "{$changed} バンドのファイルを更新しました" : 'ファイルは変更なし');
    redirect('live?id=' . $liveId);
}

// ---------- フォルダを読んで割り当てる ----------
$error = null;
$folders = [];  // サブフォルダ ID => フォルダ名
$match = ['bands' => [], 'auto' => [], 'unmatched' => []];
$folderId = drive_folder_id((string)$live['drive_url']);
if ($folderId === null) {
    $error = 'このライブにドライブのフォルダのリンク（…/drive/folders/…）が入っていません。ライブを編集で追加してください';
} elseif (!$bands) {
    $error = 'このライブにはまだバンドが登録されていません';
} else {
    try {
        foreach (drive_folder_children($folderId)['folders'] as $f) {
            $folders[$f['file_id']] = $f['name'];
        }
        if (!$folders) {
            $error = 'フォルダの中にサブフォルダがありません。バンドごとにサブフォルダを作って、ファイルを入れてください';
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
    foreach ($folders as $id => $name) {
        $list[] = ['file_id' => $id, 'name' => $name];
    }
    $match = drive_match_bands($list, $input);
}

// バンドごとの初期値と状態を決める
$rows = [];
$used = []; // 初期値でどこかのバンドに入ったサブフォルダ
foreach ($bands as $id => $b) {
    $candidates = $match['bands'][$id] ?? [];
    $count = $fileCounts[$id] ?? 0;
    if (isset($match['auto'][$id])) {
        $selected = $match['auto'][$id]; // 決まったサブフォルダ → 中身を取り直す（増えたファイルも入る）
        $status = 'auto';
    } elseif ($count > 0) {
        $selected = 'keep';
        $status = 'current';
    } else {
        $selected = '';
        $status = $candidates ? 'pick' : 'none';
    }
    if (isset($folders[$selected])) {
        $used[$selected] = true;
    }
    $rows[$id] = ['band' => $b, 'candidates' => $candidates, 'selected' => $selected, 'status' => $status, 'count' => $count];
}
$leftover = array_diff_key($folders, $used); // どのバンドにも入っていないサブフォルダ（確認用に下に並べる）
$autoCount = count(array_filter($rows, fn($r) => $r['status'] === 'auto'));
$pickCount = count(array_filter($rows, fn($r) => $r['status'] === 'pick'));

// 状態 → [表示する文字, CSS のクラス]。表記は熟語で統一（CLAUDE.md）
const DRIVE_STATUS = [
    'current' => ['現在のまま', 'pill'],
    'auto'    => ['自動選択', 'pill pill--ok'],
    'pick'    => ['候補が複数', 'pill pill--warn'],
    'none'    => ['該当なし', 'pill muted'],
];

render_header('ドライブのファイルを割り当て', 'lives'); ?>
<nav class="crumbs"><a href="live?id=<?= $liveId ?>"><?= h($live['name']) ?></a><span>/</span><a href="live_edit?id=<?= $liveId ?>">編集</a><span>/</span>ドライブ</nav>
<h1 class="display display--sm">ドライブのファイルをバンドに割り当て</h1>

<?php if ($error !== null): ?>
    <div class="flash flash--error"><?= h($error) ?></div>
    <p><a class="btn btn--ghost" href="live_edit?id=<?= $liveId ?>">ライブを編集に戻る</a></p>
<?php else: ?>
    <div class="flash flash--info">
        サブフォルダ <?= count($folders) ?> 個のうち <?= $autoCount ?> バンドを自動で割り当て。
        <?php if ($pickCount): ?><strong><?= $pickCount ?> バンドは候補が複数あるので選択してください。</strong><?php endif; ?>
    </div>

    <!-- data-yt-form / data-yt-leftover などは live_youtube.php と同じ名前にして、
         「どのバンドにも入っていない一覧」を減らす JS（assets/app.js の setupYoutubeLeftover）をそのまま使う -->
    <form method="post" class="card form-card" data-yt-form<?= unsaved_attr() ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="live_id" value="<?= $liveId ?>">
        <div class="table-scroll table-scroll--yt">
            <table class="table table--yt">
                <thead><tr><th>日程</th><th>順</th><th>バンド名</th><th>フォルダ</th><th>状態</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $id => $r): $b = $r['band']; [$statusText, $statusClass] = DRIVE_STATUS[$r['status']]; ?>
                    <tr<?= $r['status'] === 'pick' ? ' class="is-warn"' : '' ?>>
                        <td class="muted"><?= h($b['label']) ?></td>
                        <td class="num"><?= (int)$b['play_order'] ?></td>
                        <td><?= h($b['name']) ?></td>
                        <td>
                            <select name="f[<?= $id ?>]" aria-label="「<?= h($b['name']) ?>」のフォルダ">
                                <option value="">なし</option>
                                <?php if ($r['selected'] === 'keep'): ?>
                                    <option value="keep" selected>現在のまま（<?= (int)$r['count'] ?> ファイル）</option>
                                <?php endif; ?>
                                <?php if ($r['candidates']): ?>
                                    <optgroup label="候補">
                                        <?php foreach ($r['candidates'] as $fid): ?>
                                            <option value="<?= h($fid) ?>"<?= $fid === $r['selected'] ? ' selected' : '' ?>><?= h($folders[$fid]) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                                <optgroup label="ほかのサブフォルダ">
                                    <?php foreach ($folders as $fid => $name): if (in_array($fid, $r['candidates'], true)) continue; ?>
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

        <!-- どのバンドにも選ばれていないサブフォルダ。全部出しておき、選ばれているものは hidden（JS が出し入れする） -->
        <section data-yt-leftover<?= $leftover ? '' : ' hidden' ?>>
            <h2 class="section-title">未割り当てのフォルダ（<span data-yt-left-count><?= count($leftover) ?></span>）</h2>
            <ul class="yt-leftover">
                <?php foreach ($folders as $fid => $name): ?>
                    <li data-yt-video="<?= h($fid) ?>"<?= isset($leftover[$fid]) ? '' : ' hidden' ?>><a href="https://drive.google.com/drive/folders/<?= h($fid) ?>" target="_blank" rel="noopener noreferrer"><?= h($name) ?></a></li>
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
