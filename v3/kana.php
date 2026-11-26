<?php
/**
 * =====================================================================
 *  kana.php?kind=artist|song — AI でふりがなを付ける（管理者だけ）
 * =====================================================================
 *  artists.php / songs.php のボタン（POST action=ai）→ 処理中の画面（app_form.js の data-busy）→ この確認画面 → 保存
 *    アーティスト … artist.name_kana（空のものだけ）
 *    曲名       … song_kana（migrations/151。曲名ごとに1つ）
 *  AI の読み間違いがそのまま DB に入らないよう、結果はセッションに置いて確認・修正させ、「保存」で初めて書き込む
 *  （POST の結果をそのまま描くと、再読み込みでもう一度 AI を呼んでしまうのでリダイレクトする）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_admin();
require_once __DIR__ . '/lib/claude.php';
$pdo = db();

$kind = ($_GET['kind'] ?? '') === 'song' ? 'song' : 'artist';
$back = $kind === 'song' ? 'songs' : 'artists';
$self = 'kana?kind=' . $kind;

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'ai') {
        try {
            $kana = claude_artist_kana(array_slice(kana_needing($pdo, $kind), 0, KANA_AI_BATCH), $kind);
            if (!$kana) {
                flash('ふりがなを付けられませんでした', 'error');
                redirect($back);
            }
            $_SESSION['kana_review'][$kind] = $kana; // 名前 => 読み
            redirect($self);
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'error');
            redirect($back);
        }
    }
    if ($action === 'save') {
        // 名前はセッションに置いたもの（AI に送ったもの）だけ受け付ける。空欄は保存しない。すでに付いているものは上書きしない
        $st = $kind === 'song'
            ? $pdo->prepare('INSERT IGNORE INTO song_kana (kana, title) VALUES (?, ?)')
            : $pdo->prepare('UPDATE artist SET name_kana = ? WHERE name = ? AND name_kana IS NULL');
        $saved = 0;
        foreach (array_keys($_SESSION['kana_review'][$kind] ?? []) as $i => $name) {
            $k = trim((string)($_POST['kana'][$i] ?? ''));
            if ($k !== '' && mb_strlen($k) <= 100) {
                $st->execute([$k, (string)$name]); // 「1975」のような名前はキーが int になる
                $saved += $st->rowCount();
            }
        }
        $left = count(kana_needing($pdo, $kind));
        flash("{$saved}件のふりがなを保存しました" . ($left > 0 ? "。残り {$left} 件" : ''));
    }
    unset($_SESSION['kana_review'][$kind]); // 保存・破棄
    redirect($back);
}

$review = $_SESSION['kana_review'][$kind] ?? [];
if (!$review) {
    redirect($back);
}

render_header('ふりがなの確認', 'tools');
?>
<nav class="crumbs"><a href="<?= h($back) ?>"><?= $kind === 'song' ? '楽曲データベース' : 'アーティスト' ?></a></nav>
<h1 class="display display--sm">ふりがなの確認</h1>
<form method="post" class="card form-grid">
    <?= csrf_field() ?>
    <?php $ki = 0; foreach ($review as $name => $k): ?>
        <label class="field"><span><?= h((string)$name) ?></span><input name="kana[<?= $ki++ ?>]" value="<?= h($k) ?>" maxlength="100" autocomplete="off"></label>
    <?php endforeach; ?>
    <div class="form-actions field--wide">
        <button class="btn btn--sm" type="submit" name="action" value="save">保存</button>
        <button class="btn btn--ghost btn--sm" type="submit" name="action" value="cancel" formnovalidate>破棄</button>
    </div>
</form>
<?php render_footer();
