<?php
/**
 * =====================================================================
 *  instruments.php — 楽器の管理（管理者のみ）
 * =====================================================================
 *  楽器（instrument テーブル）の 色・略称・正式名称 の変更と、削除ができる。
 *
 *  変更・削除できない楽器（LOCKED_INSTRUMENTS = Vo / Gt / Ba / Dr / Key / Cho / etc）
 *    プログラムが略称で直接探しているので、鍵マークを出して何も変えられないようにする。
 *    色も assets/app.css に固定で書いてある。
 *
 *  それ以外の楽器（Perc / Vn / Sax / Prog / Harm と、名簿の取り込みで「etc」から追加された楽器）
 *    保存 … 色（'#rrggbb'）・略称（UNIQUE。10文字まで）・正式名称（30文字まで）をまとめて更新
 *            色は instrument.color に入り、ページの <head> の <style> で付く（lib/bootstrap.php の instrument_color_css）
 *    削除 … 使われていない楽器はそのまま消す。
 *            出演記録・TAB 譜で使われている楽器は、統合先を選んで「付け替えてから」消す（lib/repository.php の merge_instrument）
 *
 *  セキュリティ:
 *    ・POST は verify_csrf() で CSRF トークンを確認（よそのサイトからフォームを送らせる攻撃を防ぐ）
 *    ・SQL は全部プリペアドステートメント（? に値を入れる）で、SQL インジェクションを防ぐ
 *    ・画面に出す文字は全部 h()（htmlspecialchars）を通して XSS を防ぐ
 *    ・色は is_hex_color() で '#rrggbb' の形だけを通す（<style> に直接書くため）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_admin();

$pdo = db();

/**
 * 楽器が何か所で使われているか（出演記録 + TAB 譜 + パートのカード）。
 * サブクエリ（SELECT の中の SELECT）で楽器ごとに数える。楽器は十数行なので軽い
 */
const INSTRUMENT_USED_SQL = '(SELECT COUNT(*) FROM band_member bm WHERE bm.instrument_id = i.instrument_id)
    + (SELECT COUNT(*) FROM song_tab t WHERE t.instrument_id = i.instrument_id)
    + (SELECT COUNT(*) FROM song_part p WHERE p.instrument_id = i.instrument_id)';

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['instrument_id'] ?? 0);

    $st = $pdo->prepare('SELECT i.instrument_id, i.short_name, i.name, i.color, ' . INSTRUMENT_USED_SQL . ' AS used
        FROM instrument i WHERE i.instrument_id = ?');
    $st->execute([$id]);
    $target = $st->fetch();

    if (!$target) {
        flash('楽器が見つかりません', 'error');
    } elseif (instrument_locked($target['short_name'])) {
        // 画面ではボタンを出していないが、フォームを書き換えて送られても止める（サーバー側でも必ず確かめる）
        flash("「{$target['name']}」は変更・削除できません", 'error');
    } elseif ($action === 'save') {
        // tt_width = 全角英数字を半角にそろえる（名簿の取り込みで追加するときと同じ）
        $short = trim(tt_width((string)($_POST['short_name'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $color = strtolower((string)($_POST['color'] ?? ''));

        $dup = $pdo->prepare('SELECT 1 FROM instrument WHERE short_name = ? AND instrument_id <> ?');
        $dup->execute([$short, $id]);

        if ($short === '' || mb_strlen($short) > 10 || $name === '' || mb_strlen($name) > 30) {
            flash('略称は1〜10文字、正式名称は1〜30文字で入力してください', 'error');
        } elseif (instrument_locked($short)) {
            // Vo などと同じ略称にすると、プログラムがどちらを探しているか分からなくなる
            flash("略称「{$short}」は使えません", 'error');
        } elseif ($dup->fetchColumn()) {
            flash("略称「{$short}」の楽器はすでにあります", 'error');
        } elseif (!is_hex_color($color)) {
            flash('色が正しくありません', 'error');
        } else {
            $pdo->prepare('UPDATE instrument SET short_name = ?, name = ?, color = ? WHERE instrument_id = ?')
                ->execute([$short, $name, $color, $id]);
            flash("「{$name}（{$short}）」を保存しました");
        }
    } elseif ($action === 'delete') {
        $used = (int)$target['used'];
        if ($used === 0) {
            $pdo->prepare('DELETE FROM instrument WHERE instrument_id = ?')->execute([$id]);
            flash("「{$target['name']}（{$target['short_name']}）」を削除しました");
        } else {
            // 使われている楽器 → 統合先に付け替えてから消す
            $toId = (int)($_POST['to_id'] ?? 0);
            $to = $pdo->prepare('SELECT short_name, name FROM instrument WHERE instrument_id = ?');
            $to->execute([$toId]);
            $toRow = $to->fetch();
            if (!$toRow || $toId === $id) {
                flash('統合先の楽器を選んでください', 'error');
            } else {
                merge_instrument($pdo, $id, $toId);
                flash("「{$target['name']}」を「{$toRow['name']}（{$toRow['short_name']}）」に統合して削除しました（{$used} 件を付け替え）");
            }
        }
    }
    redirect('instruments#row-' . $id);
}

// ---- 一覧 ----
$list = $pdo->query('SELECT i.instrument_id, i.short_name, i.name, i.color, ' . INSTRUMENT_USED_SQL . ' AS used
    FROM instrument i ORDER BY i.sort_order, i.instrument_id')->fetchAll();

/** 消せないときのグレーアウトしたゴミ箱（masters.php と同じ見た目。押すとポップアップで理由が出る） */
function locked_mark(string $why): string
{
    return '<span class="tooltip" data-tip="' . h($why) . '" tabindex="0">'
        . '<button class="btn-trash" type="button" disabled aria-label="' . h($why) . '">' . icon('lock') . '</button></span>';
}

render_header('楽器の管理');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Admin</p>
        <h1 class="display">楽器の管理</h1>
    </div>
    <dl class="stats"><div><dt>楽器</dt><dd><?= count($list) ?></dd></div></dl>
</section>

<div class="card table-card">
    <div class="table-scroll table-scroll--flush">
    <table class="table table--edit inst-admin">
        <thead><tr><th>色</th><th>略称</th><th>正式名称</th><th></th><th class="num">使用数</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $ins):
            $iid = (int)$ins['instrument_id'];
            $used = (int)$ins['used'];
            $cls = instrument_class($ins['short_name']);
            $form = 'inst-' . $iid; // 行の入力欄はセルをまたぐので、form="…" 属性でこのフォームに属させる（表の中にフォームを広げられないため）
        ?>
            <tr id="row-<?= $iid ?>">
            <?php if (instrument_locked($ins['short_name'])): ?>
                <td><span class="part part--<?= h($cls) ?>"><?= h($ins['short_name']) ?></span></td>
                <td class="strong"><?= h($ins['short_name']) ?></td>
                <td><?= h($ins['name']) ?></td>
                <td></td>
                <td class="num"><?= $used ?></td>
                <td class="num"><?= locked_mark('変更・削除不可') ?></td>
            <?php else:
                // 色が未設定（NULL）の楽器は、その他と同じグレーを初期値として出す
                $color = is_hex_color($ins['color']) ? strtolower($ins['color']) : '#6c6a75'; ?>
                <td>
                    <form method="post" id="<?= $form ?>"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="instrument_id" value="<?= $iid ?>">
                    </form>
                    <!-- type="color" = ブラウザのカラーピッカー。値は必ず '#rrggbb'（小文字）で送られる。
                         スマホのピッカーは色コードを打てないことがあるので、隣に色コードの入力欄を置く（name が無いので送信はされない。
                         打った色は assets/app.js の setupInstrumentHex がピッカーに写す＝送られるのはピッカーの値だけ。形の確認はサーバー側の is_hex_color のまま） -->
                    <span class="inst-admin__colorbox">
                        <input type="color" name="color" value="<?= h($color) ?>" form="<?= $form ?>" aria-label="「<?= h($ins['name']) ?>」の色" class="inst-admin__color" data-inst-color>
                        <input type="text" value="<?= h($color) ?>" maxlength="7" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="「<?= h($ins['name']) ?>」の色コード" class="inst-admin__hex" data-inst-hex>
                    </span>
                </td>
                <td><input autocomplete="off" name="short_name" value="<?= h($ins['short_name']) ?>" maxlength="10" required form="<?= $form ?>" aria-label="略称" class="inst-admin__short"></td>
                <td><input autocomplete="off" name="name" value="<?= h($ins['name']) ?>" maxlength="30" required form="<?= $form ?>" aria-label="正式名称"></td>
                <td><button class="btn btn--ghost btn--sm" type="submit" form="<?= $form ?>">保存</button></td>
                <td class="num"><?= $used ?></td>
                <td class="num">
                    <?php if ($used === 0): ?>
                        <form method="post" class="inline-form" data-confirm="「<?= h($ins['name']) ?>（<?= h($ins['short_name']) ?>）」を削除します。よろしいですか？"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="instrument_id" value="<?= $iid ?>">
                            <button class="btn-trash" type="submit" aria-label="「<?= h($ins['name']) ?>」を削除" title="削除"><?= icon('delete') ?></button>
                        </form>
                    <?php else: ?>
                        <!-- 使われている楽器: 統合先を選んでから削除（出演記録・TAB 譜は統合先に付け替わる） -->
                        <form method="post" class="row-form inst-admin__merge" data-confirm="「<?= h($ins['name']) ?>」の <?= $used ?> 件を選んだ楽器に付け替えて削除します。元に戻せません。よろしいですか？"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="instrument_id" value="<?= $iid ?>">
                            <select name="to_id" required aria-label="統合先の楽器">
                                <option value="">統合先</option>
                                <?php foreach ($list as $to): if ((int)$to['instrument_id'] === $iid) continue; ?>
                                    <option value="<?= (int)$to['instrument_id'] ?>"><?= h($to['short_name'] . ' ' . $to['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn-trash" type="submit" aria-label="「<?= h($ins['name']) ?>」を統合して削除" title="統合して削除"><?= icon('delete') ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php render_footer();
