<?php
/**
 * =====================================================================
 *  masters.php — 会場・日程名・楽器・係の管理（管理者のみ）
 * =====================================================================
 *  「選択肢として使い回す名前」をまとめて直す画面。上のタブで切り替える（?tab=venue / day / instrument / role）。
 *
 *  会場（venue テーブル）
 *    名前の変更 … venue.name は UNIQUE なので、他の会場と同じ名前にはできない（→ 統合を使う）
 *    統合       … 使っている日程を統合先に付け替えてから消す
 *    削除       … 日程で使われている会場は消せない（外部キーは ON DELETE SET NULL なので DB は消させてくれるが、
 *                  消すとその日程の会場が「未設定」になってしまうため、ここで止めている）
 *
 *  日程名（専用のテーブルは無い。live_day.label の文字そのもの）
 *    名前の変更 … その日程名の live_day.label を全部書きかえる。もうある日程名にすると、それと1つにまとまる（= 統合）
 *                  ただし同じライブに両方の日程名があると UNIQUE(live_id, label) にぶつかるので、変更できない
 *    いつもの日程名（DAY_LABELS）… 取り込みが日程を見分けるのに使っているので変えられない
 *    削除       … 日程名は「使われている日程」があるから一覧に出ている。使われなくなれば一覧から自然に消える
 *
 *  楽器（instrument テーブル）
 *    名前の変更 … 名簿の取り込みで「etc」から追加された楽器だけ。略称は UNIQUE
 *    削除       … 最初から入っている楽器（BUILTIN_INSTRUMENTS。プログラムが略称で探している）と、
 *                  出演記録で使われている楽器（band_member の外部キーが ON DELETE RESTRICT）は消せない
 *
 *  係（role テーブル。プロフィールの「新しい係」で増える）
 *    会場と同じく 名前の変更・統合・削除。統合は member_role を統合先に付け替えてから消す。
 *    削除 … 誰かに付いている係は消せない（member_role の外部キーが ON DELETE RESTRICT）
 *
 *  以前の venues.php / instruments.php は、このページのタブへ移動するだけのファイルとして残している。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_admin();

$pdo = db();

$tabs = [
    'venue'      => ['会場', '会場の名前の変更・統合・削除ができます。表記ゆれで2つに分かれた会場は「統合」でまとめてください（日程は統合先に付け替わります）。日程で使われている会場は削除できません。'],
    'day'        => ['日程名', '新しく作られた日程名の変更ができます。もうある日程名に変えると、その日程名にまとまります（表記ゆれの統合）。「1日目」などのいつもの日程名は変えられません。'],
    'instrument' => ['楽器', '名簿の取り込みで「etc」から追加された楽器の名前の変更・削除ができます。最初から入っている楽器と、出演記録で使われている楽器は消せません。'],
    'role'       => ['係', 'プロフィールで作られた係の名前の変更・統合・削除ができます。表記ゆれで2つに分かれた係は「統合」でまとめてください（付いている人は統合先に移ります）。誰かに付いている係は削除できません。'],
];
$tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? 'venue');
if (!isset($tabs[$tab])) {
    $tab = 'venue';
}

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    // ↑↓ の並び替え（会場・係）。テーブル名は SQL に直接入るので、ここに書いた決まった名前だけを渡す
    $sortable = ['venue' => ['venue', 'venue_id'], 'role' => ['role', 'role_id']];
    if ($action === 'move' && isset($sortable[$tab])) {
        [$table, $idCol] = $sortable[$tab];
        $id = (int)($_POST[$idCol] ?? 0);
        move_sort_order($pdo, $table, $idCol, $id, ($_POST['dir'] ?? '') === 'up');
        redirect('masters?tab=' . $tab . '#row-' . $id); // 動かした行の所に戻る（続けて押しやすいように）
    }

    if ($tab === 'venue') {
        // ================= 会場 =================
        $id = (int)($_POST['venue_id'] ?? 0);
        $st = $pdo->prepare('SELECT v.name, (SELECT COUNT(*) FROM live_day d WHERE d.venue_id = v.venue_id) AS used
            FROM venue v WHERE v.venue_id = ?');
        $st->execute([$id]);
        $target = $st->fetch();

        if (!$target) {
            flash('会場が見つかりません', 'error');
        } elseif ($action === 'rename') {
            $name = trim((string)($_POST['name'] ?? ''));
            $dup = $pdo->prepare('SELECT 1 FROM venue WHERE name = ? AND venue_id <> ?');
            $dup->execute([$name, $id]);
            if ($name === '' || mb_strlen($name) > 50) {
                flash('会場名は1〜50文字で入力してください', 'error');
            } elseif ($dup->fetchColumn()) {
                flash("「{$name}」という会場はすでにあります（まとめるときは「統合」を使ってください）", 'error');
            } elseif ($name !== $target['name']) {
                $pdo->prepare('UPDATE venue SET name = ? WHERE venue_id = ?')->execute([$name, $id]);
                flash("「{$target['name']}」を「{$name}」に変更しました");
            }
        } elseif ($action === 'merge') {
            // 統合: この会場を使っている日程を統合先に付け替えてから、この会場を消す
            $toId = (int)($_POST['to_id'] ?? 0);
            $st = $pdo->prepare('SELECT name FROM venue WHERE venue_id = ?');
            $st->execute([$toId]);
            $toName = $st->fetchColumn();
            if ($toName === false || $toId === $id) {
                flash('統合先の会場を選んでください（同じ会場は選べません）', 'error');
            } else {
                // 付け替えと削除は「両方成功」か「両方なし」にしたいのでトランザクションにする。
                // 先に付け替える: 先に消すと ON DELETE SET NULL で日程の会場が空になってしまう
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE live_day SET venue_id = ? WHERE venue_id = ?')->execute([$toId, $id]);
                    $pdo->prepare('DELETE FROM venue WHERE venue_id = ?')->execute([$id]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                flash("「{$target['name']}」を「{$toName}」に統合しました（日程 {$target['used']} 件を付け替え）");
            }
        } elseif ($action === 'delete') {
            if ((int)$target['used'] > 0) {
                flash("「{$target['name']}」は {$target['used']} 件の日程で使われているので消せません", 'error');
            } else {
                $pdo->prepare('DELETE FROM venue WHERE venue_id = ?')->execute([$id]);
                flash("「{$target['name']}」を消しました");
            }
        }
    } elseif ($tab === 'day') {
        // ================= 日程名 =================
        $old = (string)($_POST['label'] ?? '');
        $st = $pdo->prepare('SELECT COUNT(*) FROM live_day WHERE label = ?');
        $st->execute([$old]);
        $used = (int)$st->fetchColumn();

        if ($action !== 'rename' || $used === 0 || str_starts_with($old, '#')) {
            flash('日程名が見つかりません', 'error');
        } elseif (in_array($old, DAY_LABELS, true)) {
            flash("「{$old}」はいつもの日程名なので変えられません", 'error');
        } else {
            // ライブ編集・取り込みと同じチェック（1〜50文字・「#」で始まらない）
            [$new, $error] = resolve_day_label('__new__', (string)($_POST['name'] ?? ''), []);
            if ($error !== null) {
                flash($error, 'error');
            } elseif ($new !== $old) {
                // 同じライブに「変更前」と「変更後」の日程が両方あると、1つのライブに同じ日程名が2つになってしまう
                //   UNIQUE(live_id, label) が UPDATE を止めてくれるので、その例外（23000）をメッセージにする
                try {
                    $up = $pdo->prepare('UPDATE live_day SET label = ? WHERE label = ?');
                    $up->execute([$new, $old]);
                    flash("日程名「{$old}」を「{$new}」に変更しました（日程 {$up->rowCount()} 件）");
                } catch (PDOException $e) {
                    if ($e->getCode() !== '23000') {
                        throw $e;
                    }
                    flash("「{$old}」と「{$new}」の両方の日程があるライブがあるので変更できません。先にライブ編集で日程を直してください", 'error');
                }
            }
        }
    } elseif ($tab === 'role') {
        // ================= 係（会場とほぼ同じ） =================
        $id = (int)($_POST['role_id'] ?? 0);
        $st = $pdo->prepare('SELECT r.name, (SELECT COUNT(*) FROM member_role mr WHERE mr.role_id = r.role_id) AS used
            FROM role r WHERE r.role_id = ?');
        $st->execute([$id]);
        $target = $st->fetch();

        if (!$target) {
            flash('係が見つかりません', 'error');
        } elseif ($action === 'rename') {
            $name = trim((string)($_POST['name'] ?? ''));
            $dup = $pdo->prepare('SELECT 1 FROM role WHERE name = ? AND role_id <> ?');
            $dup->execute([$name, $id]);
            if ($name === '' || mb_strlen($name) > 30) {
                flash('係の名前は1〜30文字で入力してください', 'error');
            } elseif ($dup->fetchColumn()) {
                flash("「{$name}」という係はすでにあります（まとめるときは「統合」を使ってください）", 'error');
            } elseif ($name !== $target['name']) {
                $pdo->prepare('UPDATE role SET name = ? WHERE role_id = ?')->execute([$name, $id]);
                flash("「{$target['name']}」を「{$name}」に変更しました");
            }
        } elseif ($action === 'merge') {
            $toId = (int)($_POST['to_id'] ?? 0);
            $st = $pdo->prepare('SELECT name FROM role WHERE role_id = ?');
            $st->execute([$toId]);
            $toName = $st->fetchColumn();
            if ($toName === false || $toId === $id) {
                flash('統合先の係を選んでください（同じ係は選べません）', 'error');
            } else {
                // 会場のように UPDATE で付け替えると、両方の係を持っている人のところで主キー (member_id, role_id) が重なってエラーになる。
                // なので INSERT IGNORE で統合先の行を作り（重なる人は飛ばす）、元の行はあとでまとめて消す
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT IGNORE INTO member_role (member_id, role_id)
                        SELECT member_id, ? FROM member_role WHERE role_id = ?')->execute([$toId, $id]);
                    $pdo->prepare('DELETE FROM member_role WHERE role_id = ?')->execute([$id]);
                    $pdo->prepare('DELETE FROM role WHERE role_id = ?')->execute([$id]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                flash("「{$target['name']}」を「{$toName}」に統合しました（{$target['used']} 人を付け替え）");
            }
        } elseif ($action === 'delete') {
            if ((int)$target['used'] > 0) {
                flash("「{$target['name']}」は {$target['used']} 人に付いているので消せません", 'error');
            } else {
                $pdo->prepare('DELETE FROM role WHERE role_id = ?')->execute([$id]);
                flash("「{$target['name']}」を消しました");
            }
        }
    } else {
        // ================= 楽器 =================
        $id = (int)($_POST['instrument_id'] ?? 0);
        $st = $pdo->prepare('SELECT i.short_name, i.name, (SELECT COUNT(*) FROM band_member bm WHERE bm.instrument_id = i.instrument_id) AS used
            FROM instrument i WHERE i.instrument_id = ?');
        $st->execute([$id]);
        $target = $st->fetch();

        if (!$target) {
            flash('楽器が見つかりません', 'error');
        } elseif (in_array($target['short_name'], BUILTIN_INSTRUMENTS, true)) {
            flash('最初から入っている楽器は変更・削除できません', 'error');
        } elseif ($action === 'rename') {
            $short = trim((string)($_POST['short_name'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $dup = $pdo->prepare('SELECT 1 FROM instrument WHERE short_name = ? AND instrument_id <> ?');
            $dup->execute([$short, $id]);
            if ($short === '' || mb_strlen($short) > 10 || $name === '' || mb_strlen($name) > 30) {
                flash('略称は1〜10文字、楽器名は1〜30文字で入力してください', 'error');
            } elseif ($dup->fetchColumn()) {
                flash("略称「{$short}」の楽器はすでにあります", 'error');
            } elseif ($short !== $target['short_name'] || $name !== $target['name']) {
                $pdo->prepare('UPDATE instrument SET short_name = ?, name = ? WHERE instrument_id = ?')->execute([$short, $name, $id]);
                flash("「{$target['name']}（{$target['short_name']}）」を「{$name}（{$short}）」に変更しました");
            }
        } elseif ($action === 'delete') {
            if ((int)$target['used'] > 0) {
                flash("「{$target['name']}」は {$target['used']} 件の出演記録で使われているので消せません", 'error');
            } else {
                $pdo->prepare('DELETE FROM instrument WHERE instrument_id = ?')->execute([$id]);
                flash("「{$target['name']}（{$target['short_name']}）」を消しました");
            }
        }
    }
    redirect('masters?tab=' . $tab);
}

// ---- 一覧（3つとも数を出したいので全部読む。どれも数十行なので軽い） ----
// 会場ごとに「何件の日程で使われているか」
$venues = $pdo->query('SELECT v.venue_id, v.name, COUNT(d.live_day_id) AS used
    FROM venue v LEFT JOIN live_day d ON d.venue_id = v.venue_id
    GROUP BY v.venue_id, v.name, v.sort_order
    ORDER BY v.sort_order, v.name')->fetchAll();
// 日程名ごとの日程の数。いつもの日程名（DAY_LABELS）は使われていなくても出し、その順で先頭に並べる
$dayCounts = $pdo->query("SELECT label, COUNT(*) FROM live_day WHERE label NOT LIKE '#%' GROUP BY label")->fetchAll(PDO::FETCH_KEY_PAIR);
$days = [];
foreach (day_labels($pdo) as $label) {
    $days[] = ['label' => $label, 'used' => (int)($dayCounts[$label] ?? 0), 'builtin' => in_array($label, DAY_LABELS, true)];
}
// 楽器ごとに「何件の出演記録で使われているか」
$instruments = $pdo->query('SELECT i.instrument_id, i.short_name, i.name, COUNT(bm.band_id) AS used
    FROM instrument i LEFT JOIN band_member bm ON bm.instrument_id = i.instrument_id
    GROUP BY i.instrument_id, i.short_name, i.name, i.sort_order
    ORDER BY i.sort_order, i.instrument_id')->fetchAll();
// 係ごとに「何人に付いているか」
$roles = $pdo->query('SELECT r.role_id, r.name, COUNT(mr.member_id) AS used
    FROM role r LEFT JOIN member_role mr ON mr.role_id = r.role_id
    GROUP BY r.role_id, r.name, r.sort_order
    ORDER BY r.sort_order, r.name')->fetchAll();
$counts = ['venue' => count($venues), 'day' => count($days), 'instrument' => count($instruments), 'role' => count($roles)];

/** 消せないときのグレーアウトしたゴミ箱（disabled のボタンはマウスの反応が鈍いので、外側の span でツールチップを出す） */
function trash_disabled(string $why): string
{
    return '<span class="tooltip" data-tooltip="' . h($why) . '" tabindex="0">'
        . '<button class="btn-trash" type="button" disabled aria-label="削除（' . h($why) . '）">' . icon('delete') . '</button></span>';
}

/**
 * 並び替えの ↑↓ ボタン（会場・係の行の左端）。1つのフォームに2つの送信ボタンを置き、押したほうの dir（up / down）が送られる。
 * 一番上の ↑・一番下の ↓ は押せなくする
 */
function move_buttons(string $tab, string $idCol, int $id, string $name, int $index, int $count): string
{
    return '<form method="post" class="move-btns">' . csrf_field()
        . '<input type="hidden" name="tab" value="' . h($tab) . '"><input type="hidden" name="action" value="move">'
        . '<input type="hidden" name="' . h($idCol) . '" value="' . $id . '">'
        . '<button class="btn-move" type="submit" name="dir" value="up" aria-label="「' . h($name) . '」を上へ"' . ($index === 0 ? ' disabled' : '') . '>' . icon('arrow_upward') . '</button>'
        . '<button class="btn-move" type="submit" name="dir" value="down" aria-label="「' . h($name) . '」を下へ"' . ($index === $count - 1 ? ' disabled' : '') . '>' . icon('arrow_downward') . '</button>'
        . '</form>';
}

render_header($tabs[$tab][0] . 'の管理');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Admin</p>
        <h1 class="display">会場・日程名・楽器・係の管理</h1>
        <p class="muted"><?= h($tabs[$tab][1]) ?></p>
    </div>
    <dl class="stats"><div><dt><?= h($tabs[$tab][0]) ?></dt><dd><?= $counts[$tab] ?></dd></div></dl>
</section>
<nav class="tabs tabs--static no-print" aria-label="管理するもの">
    <?php foreach ($tabs as $key => [$label]): ?>
        <a class="tab<?= $key === $tab ? ' is-active' : '' ?>" href="masters?tab=<?= $key ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= h($label) ?> <span class="muted small"><?= $counts[$key] ?></span></a>
    <?php endforeach; ?>
</nav>

<div class="card table-card">
    <div class="table-scroll table-scroll--flush">
    <table class="table table--edit">
    <?php if ($tab === 'venue'): ?>
        <thead><tr><th>順番</th><th>会場名</th><th class="num">使われている日程</th><th>他の会場に統合</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($venues as $vi => $v): ?>
            <tr id="row-<?= (int)$v['venue_id'] ?>">
                <td><?= move_buttons('venue', 'venue_id', (int)$v['venue_id'], $v['name'], $vi, count($venues)) ?></td>
                <td>
                    <form method="post" class="row-form"><?= csrf_field() ?>
                        <input type="hidden" name="tab" value="venue">
                        <input type="hidden" name="action" value="rename">
                        <input type="hidden" name="venue_id" value="<?= (int)$v['venue_id'] ?>">
                        <input name="name" value="<?= h($v['name']) ?>" maxlength="50" required aria-label="会場名">
                        <button class="btn btn--ghost btn--sm" type="submit">名前を変更</button>
                    </form>
                </td>
                <td class="num"><?= (int)$v['used'] ?></td>
                <td>
                    <?php if (count($venues) > 1): ?>
                        <form method="post" class="row-form" data-confirm="「<?= h($v['name']) ?>」を選んだ会場に統合します。「<?= h($v['name']) ?>」は消えて元に戻せません。よろしいですか？"><?= csrf_field() ?>
                            <input type="hidden" name="tab" value="venue">
                            <input type="hidden" name="action" value="merge">
                            <input type="hidden" name="venue_id" value="<?= (int)$v['venue_id'] ?>">
                            <select name="to_id" required aria-label="統合先の会場">
                                <option value="">統合先を選択</option>
                                <?php foreach ($venues as $to): if ($to['venue_id'] === $v['venue_id']) continue; ?>
                                    <option value="<?= (int)$to['venue_id'] ?>"><?= h($to['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn--ghost btn--sm" type="submit">統合</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td class="num">
                    <?php if ((int)$v['used'] > 0): ?>
                        <?= trash_disabled('使用されているため削除できません') ?>
                    <?php else: ?>
                        <form method="post" class="inline-form" data-confirm="「<?= h($v['name']) ?>」を消します。よろしいですか？"><?= csrf_field() ?>
                            <input type="hidden" name="tab" value="venue">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="venue_id" value="<?= (int)$v['venue_id'] ?>">
                            <button class="btn-trash" type="submit" aria-label="「<?= h($v['name']) ?>」を削除" title="削除"><?= icon('delete') ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$venues): ?>
            <tr><td colspan="5" class="muted">まだ会場が登録されていません</td></tr>
        <?php endif; ?>
        </tbody>

    <?php elseif ($tab === 'role'): ?>
        <thead><tr><th>順番</th><th>係の名前</th><th class="num">付いている人</th><th>他の係に統合</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($roles as $ri => $r): ?>
            <tr id="row-<?= (int)$r['role_id'] ?>">
                <td><?= move_buttons('role', 'role_id', (int)$r['role_id'], $r['name'], $ri, count($roles)) ?></td>
                <td>
                    <form method="post" class="row-form"><?= csrf_field() ?>
                        <input type="hidden" name="tab" value="role">
                        <input type="hidden" name="action" value="rename">
                        <input type="hidden" name="role_id" value="<?= (int)$r['role_id'] ?>">
                        <input name="name" value="<?= h($r['name']) ?>" maxlength="30" required aria-label="係の名前">
                        <button class="btn btn--ghost btn--sm" type="submit">名前を変更</button>
                    </form>
                </td>
                <td class="num"><?= (int)$r['used'] ?></td>
                <td>
                    <?php if (count($roles) > 1): ?>
                        <form method="post" class="row-form" data-confirm="「<?= h($r['name']) ?>」を選んだ係に統合します。「<?= h($r['name']) ?>」は消えて元に戻せません。よろしいですか？"><?= csrf_field() ?>
                            <input type="hidden" name="tab" value="role">
                            <input type="hidden" name="action" value="merge">
                            <input type="hidden" name="role_id" value="<?= (int)$r['role_id'] ?>">
                            <select name="to_id" required aria-label="統合先の係">
                                <option value="">統合先を選択</option>
                                <?php foreach ($roles as $to): if ($to['role_id'] === $r['role_id']) continue; ?>
                                    <option value="<?= (int)$to['role_id'] ?>"><?= h($to['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn--ghost btn--sm" type="submit">統合</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td class="num">
                    <?php if ((int)$r['used'] > 0): ?>
                        <?= trash_disabled('付いている人がいるため削除できません') ?>
                    <?php else: ?>
                        <form method="post" class="inline-form" data-confirm="「<?= h($r['name']) ?>」を消します。よろしいですか？"><?= csrf_field() ?>
                            <input type="hidden" name="tab" value="role">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="role_id" value="<?= (int)$r['role_id'] ?>">
                            <button class="btn-trash" type="submit" aria-label="「<?= h($r['name']) ?>」を削除" title="削除"><?= icon('delete') ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$roles): ?>
            <tr><td colspan="5" class="muted">まだ係がありません（プロフィールの「新しい係」で作れます）</td></tr>
        <?php endif; ?>
        </tbody>

    <?php elseif ($tab === 'day'): ?>
        <thead><tr><th>日程名</th><th class="num">使われている日程</th></tr></thead>
        <tbody>
        <?php foreach ($days as $d): ?>
            <tr>
                <td>
                    <?php if ($d['builtin']): ?>
                        <span class="strong"><?= h($d['label']) ?></span> <span class="muted small">いつもの日程名</span>
                    <?php else: ?>
                        <!-- もうある日程名にすると1つにまとまるので、確認を出す -->
                        <form method="post" class="row-form" data-confirm="日程名「<?= h($d['label']) ?>」を変更します。もうある日程名にすると、その日程名にまとまり元に戻せません。よろしいですか？"><?= csrf_field() ?>
                            <input type="hidden" name="tab" value="day">
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="label" value="<?= h($d['label']) ?>">
                            <input name="name" value="<?= h($d['label']) ?>" maxlength="50" required aria-label="日程名" list="dl-day-labels">
                            <button class="btn btn--ghost btn--sm" type="submit">名前を変更</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td class="num"><?= $d['used'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>

    <?php else: ?>
        <thead><tr><th>略称・楽器名</th><th class="num">使われている数</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($instruments as $ins):
            $builtin = in_array($ins['short_name'], BUILTIN_INSTRUMENTS, true); ?>
            <tr>
                <td>
                    <?php if ($builtin): ?>
                        <span class="strong"><?= h($ins['short_name']) ?></span> <?= h($ins['name']) ?> <span class="muted small">最初からある楽器</span>
                    <?php else: ?>
                        <form method="post" class="row-form"><?= csrf_field() ?>
                            <input type="hidden" name="tab" value="instrument">
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="instrument_id" value="<?= (int)$ins['instrument_id'] ?>">
                            <input name="short_name" value="<?= h($ins['short_name']) ?>" maxlength="10" required aria-label="略称" class="input-short">
                            <input name="name" value="<?= h($ins['name']) ?>" maxlength="30" required aria-label="楽器名">
                            <button class="btn btn--ghost btn--sm" type="submit">名前を変更</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td class="num"><?= (int)$ins['used'] ?></td>
                <td class="num">
                    <?php if ($builtin): ?>
                        <?= trash_disabled('最初からある楽器は削除できません') ?>
                    <?php elseif ((int)$ins['used'] > 0): ?>
                        <?= trash_disabled('使用されているため削除できません') ?>
                    <?php else: ?>
                        <form method="post" class="inline-form" data-confirm="「<?= h($ins['name']) ?>（<?= h($ins['short_name']) ?>）」を消します。よろしいですか？"><?= csrf_field() ?>
                            <input type="hidden" name="tab" value="instrument">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="instrument_id" value="<?= (int)$ins['instrument_id'] ?>">
                            <button class="btn-trash" type="submit" aria-label="「<?= h($ins['name']) ?>」を削除" title="削除"><?= icon('delete') ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    <?php endif; ?>
    </table>
    </div>
</div>
<?php if ($tab === 'day'): ?>
    <!-- 日程名の変更欄の入力候補（もうある日程名を選ぶと、そこにまとめられる） -->
    <datalist id="dl-day-labels"><?php foreach ($days as $d): ?><option value="<?= h($d['label']) ?>"><?php endforeach; ?></datalist>
<?php endif; ?>
<?php render_footer();
