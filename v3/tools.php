<?php
/**
 * =====================================================================
 *  tools.php — ツール（バンドやライブに紐づかない道具の入口）
 * =====================================================================
 *  今あるもの:
 *    ・セッティングシート（白紙から）… setting_sheet.php を band なしで開く
 *  道具が増えたら、下の $tools に1行足すだけでカードが増える。
 *
 *  ここは表示するだけ（POST を受けない）ので CSRF 対策は不要。
 *  DB を読むのは「回答受付中のスタジオ割に、自分のバンドがあるか」だけ（あればカードを出す）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();

// [行き先, アイコン, 名前, 説明, タグ（権限のラベル。無ければ省略）]
$tools = [
    // 楽曲データベース・練習プレイヤー（ログインしていれば誰でも。TAB 譜の作成・編集だけ管理人）
    ['songs', 'library_music', '楽曲データベース',
        'これまで演奏された楽曲の情報が確認できます。練習用の動画や耳コピメモなどの登録もできます。'],
    ['practice', 'slow_motion_video', '練習プレイヤー',
        'YouTubeの動画をリピート機能やチャプター機能がついた練習向きのプレイヤーで再生します。'],
    ['setting_sheet', 'description', 'セッティングシート作成',
        'セットリストやセット図を記入し、提出用の PDF を作成します。 ライブデータが登録されていれば、ナビゲーションバーの ライブ→各バンドのページ から自動で作成できます。'],
    // 型紙（会場ごとの PDF と座標）の登録。誰でも作れる（編集・削除は作った人と管理者だけ）
    ['sheet_templates', 'edit_document', 'セッティングシート編集（PC推奨）',
        '新しい会場のセッティングシートの PDF を登録し要素を配置して、セッティングシート作成用のテンプレートを編集します。'],
    // 会場の最寄り駅・機材・過去のライブ（誰でも見られて、誰でも編集できる）
    ['venue_list', 'location_on', '会場リスト', '会場の最寄り駅・機材・過去のライブ'],
];
// 名簿から出順を決める（管理者だけ。公開するまでは部員に見せない）
if (is_executive()) {
    $tools[] = ['tt_builder', 'view_timeline', 'タイムテーブル作成',
        '名簿から日程希望・連続出演・学年を見ながら出順を決め、ライブとして登録します。', '幹部'];
    // ライブ前のスタジオ練習の部屋割り（studio.php）
    $tools[] = ['studio', 'meeting_room', 'スタジオ割',
        '練習の要否を集め、メンバーが重ならないように練習の順番と部屋を決めて画像にします。', '幹部'];
}
// 回答受付中のスタジオ割に自分が出るバンドがあれば、回答ページのカードを先頭に出す
//   user_account → member → band_member → band → live_day → studio_plan と JOIN でたどる（DISTINCT でライブごとに1行）
$st = db()->prepare('SELECT DISTINCT l.live_id, l.name FROM user_account u
    JOIN band_member bm ON bm.member_id = u.member_id
    JOIN band b ON b.band_id = bm.band_id
    JOIN live_day d ON d.live_day_id = b.live_day_id
    JOIN live l ON l.live_id = d.live_id
    JOIN studio_plan p ON p.live_id = l.live_id AND p.answer_open = 1
    WHERE u.user_id = ? AND d.held_on >= ?');
$st->execute([(int)current_user()['user_id'], date('Y-m-d')]);
foreach (array_reverse($st->fetchAll()) as $r) {
    // array_unshift = 配列の先頭に足す（逆順にしてから先頭に足すと、元の順で並ぶ）
    array_unshift($tools, ['studio_answer?live=' . (int)$r['live_id'], 'meeting_room', $r['name'] . ' スタジオ練習', '練習の要否を回答してください。', '回答受付中']);
}

render_header('ツール', 'tools');
?>
<h1 class="display display--sm">ツール</h1>
<div class="tool-list">
    <?php foreach ($tools as $t): [$href, $ic, $name, $desc] = $t; $tag = $t[4] ?? null; ?>
        <a class="card tool-card" href="<?= h($href) ?>">
            <span class="tool-card__icon"><?= icon($ic) ?></span>
            <span class="tool-card__body">
                <span class="tool-card__name"><?= h($name) ?><?= $tag !== null ? ' <span class="tag">' . h($tag) . '</span>' : '' ?></span>
                <span class="muted small"><?= h($desc) ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</div>
<p class="muted small">登録済みのバンドのシートは、バンドのページの「セッティングシート作成」から作ると、メンバーと曲が最初から入ります。</p>
<?php render_footer();
