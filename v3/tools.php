<?php
/**
 * =====================================================================
 *  tools.php — ツール（バンドやライブに紐づかない道具の入口）
 * =====================================================================
 *  今あるもの:
 *    ・セッティングシート（白紙から）… setting_sheet.php を band なしで開く
 *  道具が増えたら、下の $tools に1行足すだけでカードが増える。
 *
 *  ここは表示するだけ（POST を受けない・DB も読まない）ので CSRF 対策は不要。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();

// [行き先, アイコン, 名前, 説明]
$tools = [
    ['setting_sheet', 'description', 'セッティングシート',
        '会場のシートに、バンド名・メンバー・セット図・セットリストを書き込んで PDF にします。DBにライブ情報が登録されていなくても作成できます。'],
    // 型紙（会場ごとの PDF と座標）の登録。誰でも作れる（編集・削除は作った人と管理者だけ）
    ['sheet_templates', 'edit_document', 'セッティングシート編集（PC推奨）',
        '新しい会場のシートの PDF を登録して、どこに何を書くか（座標）を決めます。'],
];

render_header('ツール', 'tools');
?>
<h1 class="display display--sm"><?= icon('build') ?> ツール</h1>
<div class="tool-list">
    <?php foreach ($tools as [$href, $ic, $name, $desc]): ?>
        <a class="card tool-card" href="<?= h($href) ?>">
            <span class="tool-card__icon"><?= icon($ic) ?></span>
            <span class="tool-card__body">
                <span class="tool-card__name"><?= h($name) ?></span>
                <span class="muted small"><?= h($desc) ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</div>
<p class="muted small">登録済みのバンドのシートは、バンドのページの「セッティングシート作成」から作ると、メンバーと曲が最初から入ります。</p>
<?php render_footer();
