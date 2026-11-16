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
 *    追加 … 表の一番下の行（略称・正式名称・色を入れて ＋）。create_instrument（lib/repository.php）で作る
    保存 … 保存ボタンは無く、色（'#rrggbb'）・略称（UNIQUE。10文字まで）・正式名称（30文字まで）は
 *            入力が止まって約0.8秒後に1項目ずつ自動保存（api_instrument_save.php / app.js の setupInstrumentAutosave）
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

    if ($action === 'add') {
        // ---- 楽器の追加（api_instrument_save.php と同じ検証。ここは1回で全項目を送る） ----
        $short = trim(tt_width((string)($_POST['short_name'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $color = strtolower((string)($_POST['color'] ?? ''));
        $dup = $pdo->prepare('SELECT 1 FROM instrument WHERE short_name = ?');
        $dup->execute([$short]);
        if ($short === '' || mb_strlen($short) > 10 || $name === '' || mb_strlen($name) > 30) {
            flash('略称は1〜10文字、正式名称は1〜30文字で入力してください', 'error');
        } elseif (instrument_locked($short)) {
            flash("略称「{$short}」は使えません", 'error'); // Vo などと同じ略称は、プログラムがどちらを探しているか分からなくなる
        } elseif ($dup->fetchColumn()) {
            flash("略称「{$short}」の楽器はすでにあります", 'error');
        } elseif (!is_hex_color($color)) {
            flash('色が正しくありません', 'error');
        } else {
            $new = create_instrument($pdo, $short, $name); // 表示順は「その他」の手前に入る
            $pdo->prepare('UPDATE instrument SET color = ? WHERE instrument_id = ?')->execute([$color, $new['instrument_id']]);
            flash("「{$name}（{$short}）」を追加しました");
            redirect('instruments#row-' . $new['instrument_id']);
        }
        redirect('instruments');
    }

    $st = $pdo->prepare('SELECT i.instrument_id, i.short_name, i.name, i.color, ' . INSTRUMENT_USED_SQL . ' AS used
        FROM instrument i WHERE i.instrument_id = ?');
    $st->execute([$id]);
    $target = $st->fetch();

    if (!$target) {
        flash('楽器が見つかりません', 'error');
    } elseif (instrument_locked($target['short_name'])) {
        // 画面ではボタンを出していないが、フォームを書き換えて送られても止める（サーバー側でも必ず確かめる）
        flash("「{$target['name']}」は変更・削除できません", 'error');
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
        <thead><tr><th>表示</th><th>色</th><th>略称</th><th>正式名称</th><th class="num">使用数</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $ins):
            $iid = (int)$ins['instrument_id'];
            $used = (int)$ins['used'];
            $cls = instrument_class($ins['short_name']);
        ?>
            <tr id="row-<?= $iid ?>">
            <?php if (instrument_locked($ins['short_name'])): ?>
                <!-- 固定楽器のプレビュー: ダーク側は data-theme="dark" を付けて、app.css の [data-theme="dark"] の本物の色を使う
                     （ライト側は app.css の .inst-pv__bg--light で、ダーク表示中でも明るい色に固定している） -->
                <td>
                    <span class="inst-pv" aria-hidden="true">
                        <span class="inst-pv__bg inst-pv__bg--light"><span class="part part--<?= h($cls) ?>"><?= h($ins['short_name']) ?></span></span>
                        <span class="inst-pv__bg inst-pv__bg--dark" data-theme="dark"><span class="part part--<?= h($cls) ?>"><?= h($ins['short_name']) ?></span></span>
                    </span>
                </td>
                <td></td>
                <td class="strong"><?= h($ins['short_name']) ?></td>
                <td><?= h($ins['name']) ?></td>
                <td class="num"><?= $used ?></td>
                <td class="num"><?= locked_mark('変更・削除不可') ?></td>
            <?php else:
                // 色が未設定（NULL）の楽器は、その他と同じグレーを初期値として出す
                $color = is_hex_color($ins['color']) ? strtolower($ins['color']) : '#6c6a75'; ?>
                <!-- 表示（プレビュー）: 実際の楽器ラベル（.part）を、ライト・ダークの両方で出す。色・略称を変えると app.js の setupInstrumentPreview がその場で更新する。
                     --pv に色を入れるだけ。ダークの自動調整（明るさを .78 以上に）は CSS 側で、本番のラベルと同じ式（lib/bootstrap.php の instrument_color_css）にしている -->
                <td>
                    <span class="inst-pv inst-pv--custom" data-inst-preview style="--pv:<?= h($color) ?>" aria-hidden="true">
                        <span class="inst-pv__bg inst-pv__bg--light"><span class="part" data-inst-preview-label><?= h($ins['short_name']) ?></span></span>
                        <span class="inst-pv__bg inst-pv__bg--dark"><span class="part" data-inst-preview-label><?= h($ins['short_name']) ?></span></span>
                    </span>
                </td>
                <td>
                    <!-- type="color" = ブラウザのカラーピッカー。値は必ず '#rrggbb'（小文字）で送られる。
                         スマホのピッカーは色コードを打てないことがあるので、隣に色コードの入力欄を置く（name が無いので送信はされない。
                         打った色は assets/app.js の setupInstrumentHex がピッカーに写す＝送られるのはピッカーの値だけ。形の確認はサーバー側の is_hex_color のまま） -->
                    <span class="inst-admin__colorbox">
                        <input type="color" value="<?= h($color) ?>" aria-label="「<?= h($ins['name']) ?>」の色" class="inst-admin__color" data-inst-color data-inst-save="color" data-inst-id="<?= $iid ?>">
                        <input type="text" value="<?= h($color) ?>" maxlength="7" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="「<?= h($ins['name']) ?>」の色コード" class="inst-admin__hex" data-inst-hex>
                    </span>
                </td>
                <td><input autocomplete="off" name="short_name" value="<?= h($ins['short_name']) ?>" maxlength="10" required aria-label="略称" class="inst-admin__short autosave" data-inst-save="short_name" data-inst-id="<?= $iid ?>"></td>
                <td><input autocomplete="off" name="name" value="<?= h($ins['name']) ?>" maxlength="30" required aria-label="正式名称" class="inst-admin__name autosave" data-inst-save="name" data-inst-id="<?= $iid ?>"></td>
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
        <!-- 追加の行: 入力欄は form="inst-add" 属性で、下のフォームに属させる（表の中にフォームを広げられないため）。
             プレビューは編集の行と同じ部品（setupInstrumentPreview が色・略称の入力に追従させる） -->
        <tr id="row-new">
            <td>
                <span class="inst-pv inst-pv--custom" data-inst-preview style="--pv:#6c6a75" aria-hidden="true">
                    <span class="inst-pv__bg inst-pv__bg--light"><span class="part" data-inst-preview-label>—</span></span>
                    <span class="inst-pv__bg inst-pv__bg--dark"><span class="part" data-inst-preview-label>—</span></span>
                </span>
            </td>
            <td>
                <span class="inst-admin__colorbox">
                    <input type="color" name="color" value="#6c6a75" form="inst-add" aria-label="追加する楽器の色" class="inst-admin__color" data-inst-color>
                    <input type="text" value="#6c6a75" maxlength="7" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="追加する楽器の色コード" class="inst-admin__hex" data-inst-hex>
                </span>
            </td>
            <td><input autocomplete="off" name="short_name" maxlength="10" required form="inst-add" placeholder="略称" aria-label="追加する楽器の略称" class="inst-admin__short"></td>
            <td><input autocomplete="off" name="name" maxlength="30" required form="inst-add" placeholder="正式名称" aria-label="追加する楽器の正式名称" class="inst-admin__name"></td>
            <td></td>
            <td class="num"><button class="btn btn--sm" type="submit" form="inst-add" aria-label="楽器を追加" title="追加"><?= icon('add') ?></button></td>
        </tr>
        </tbody>
    </table>
    <form method="post" id="inst-add"><?= csrf_field() ?><input type="hidden" name="action" value="add"></form>
    </div>
</div>
<?php render_footer();
