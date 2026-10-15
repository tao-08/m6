<?php
/**
 * =====================================================================
 *  index.php — ライブ一覧（トップページ）
 * =====================================================================
 *  このページの PHP がやるのは「ログイン確認」と「確認依頼のお知らせ（人ごとに違う部分）」だけ。
 *  ライブ一覧そのもの（重い集計）は、次の流れで端末の DB（IndexedDB）を使って出す。
 *    api_lives.php        … ライブ・日程・バンド数を JSON で返す（元はこのファイルにあった集計と並べ替え）
 *    assets/local_cache.js … その JSON を端末に保存し、2回目以降は保存データで先に表示 → 裏で最新か確認
 *    assets/live_list.js   … JSON から カード・年度スロット・絞り込み欄 を作って、下の [data-live-app] に入れる
 *  ※ 確認依頼のお知らせは人ごとに内容が違うので、端末には保存せず、いつも PHP が作る。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();

$pdo = db();

// 🚩 確認依頼のお知らせ。出すのは次のどちらか（✕ で消したもの = check_dismiss にあるものは出さない）
//   ・自分が出たバンド（アカウントがメンバーと紐付いている人だけ）
//   ・メンバーが1人も登録されていないバンド → 知らせる相手がいないので、全員に出す
//   about_me: 「誰について」に自分が選ばれているか / no_members: メンバーが1人もいないか
//   member_id が NULL（紐付いていない人）でも、「= NULL」はどの行とも一致しないので、全員向けの分だけになる
$st = $pdo->prepare('SELECT b.band_id, b.name, b.needs_check, l.fiscal_year, l.name AS live_name, d.label,
        EXISTS (SELECT 1 FROM band_check_member c WHERE c.band_id = b.band_id AND c.member_id = ?) AS about_me,
        NOT EXISTS (SELECT 1 FROM band_member bm WHERE bm.band_id = b.band_id) AS no_members
    FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    JOIN live l ON l.live_id = d.live_id
    WHERE b.needs_check > 0
      AND (EXISTS (SELECT 1 FROM band_member bm WHERE bm.band_id = b.band_id AND bm.member_id = ?)
           OR NOT EXISTS (SELECT 1 FROM band_member bm WHERE bm.band_id = b.band_id))
      AND NOT EXISTS (SELECT 1 FROM check_dismiss x WHERE x.band_id = b.band_id AND x.user_id = ?)
    ORDER BY about_me DESC, no_members, l.fiscal_year DESC, d.held_on DESC, b.play_order');
$st->execute([$user['member_id'], $user['member_id'], $user['user_id']]);
$flagged = $st->fetchAll();

render_header('ライブ一覧', 'lives');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Live Archive</p>
        <h1 class="display">ライブ</h1>
    </div>
    <dl class="stats">
        <div><dt>ライブ</dt><dd data-live-stat="lives">–</dd></div>
        <div><dt>バンド</dt><dd data-live-stat="bands">–</dd></div>
        <div><dt>出演者</dt><dd data-live-stat="members">–</dd></div>
    </dl>
</section>

<?php if ($flagged): ?>
    <section class="card check-list" data-check-list>
        <h2 class="section-title section-title--card"><?= icon('flag', 'icon--fill flag-icon') ?> 確認依頼が届いています</h2>
        <p class="muted small">登録内容の確認依頼が届いているバンドです。バンド詳細ページから確認をお願いします。<br>メンバーが未登録のバンドは全員に表示されています。 情報をお持ちの方は情報提供にご協力お願いします。</p>
        <ul>
            <?php foreach ($flagged as $f): ?>
                <li data-check-item><div class="check-list__text"><a href="band?id=<?= (int)$f['band_id'] ?>"><?= h($f['name']) ?></a>
                    <span class="muted small"><?= (int)$f['fiscal_year'] ?>年度 <?= h($f['live_name']) ?> <?= h($f['label']) ?></span>
                    <?php // 理由（楽器など）はここでは出さない。中身はバンドページで見てもらう ?>
                    <?php if ($f['no_members']): ?>
                        <span class="chip chip--warn chip--xs"><?= icon('person_off') ?>メンバー未登録</span>
                    <?php elseif ($f['about_me']): ?>
                        <span class="chip chip--me chip--xs">あなたについて</span>
                    <?php endif; ?></div>
                    <button type="button" class="check-list__close" data-check-dismiss="<?= (int)$f['band_id'] ?>" aria-label="「<?= h($f['name']) ?>」のお知らせを消す" title="消す"><?= icon('close') ?></button></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<!-- ライブ一覧の入れ物。中身（ツールバー・年度ごとのカード）は assets/live_list.js が作る。
     JS が動かない環境では下の noscript が出る -->
<div data-live-app></div>
<noscript><div class="empty card"><p class="empty__title">JavaScript を有効にしてください</p></div></noscript>
<?php render_footer();
