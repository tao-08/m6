<?php
/**
 * =====================================================================
 *  partials/add_to_home.php — 「ホーム画面に追加する」の説明（モーダル）
 * =====================================================================
 *  partials/footer.php がログイン中の全ページで require する。
 *  開くのは右上メニューの「ホーム画面に追加する」（[data-a2hs-open]。スマホ・タブレットだけ出す: app.css の .touch-only）。
 *  Safari / Chrome の切り替え・最初にどちらを出すか・Android の「今すぐインストール」は assets/app.js の setupAddToHome。
 *  （a2hs = Add To Home Screen の略）
 * =====================================================================
 */
?>
<dialog class="modal a2hs" data-a2hs-dialog aria-labelledby="a2hs-title">
    <div class="modal__body">
        <h2 class="modal__title" id="a2hs-title">ホーム画面に追加する</h2>
        <p class="muted small">ホーム画面のアイコンから、アプリのように開けるようになります。</p>

        <div class="a2hs__switch" role="tablist" aria-label="使っているブラウザ">
            <button type="button" class="a2hs__tab" role="tab" id="a2hs-tab-safari" aria-controls="a2hs-safari" aria-selected="true" data-a2hs-tab="safari">Safari<small>iPhone・iPad</small></button>
            <button type="button" class="a2hs__tab" role="tab" id="a2hs-tab-chrome" aria-controls="a2hs-chrome" aria-selected="false" data-a2hs-tab="chrome">Chrome<small>Android</small></button>
        </div>

        <div class="a2hs__panel" role="tabpanel" id="a2hs-safari" aria-labelledby="a2hs-tab-safari" data-a2hs-panel="safari">
            <ol class="a2hs__steps">
                <li>
                    <div>画面下の <b class="a2hs__key"><?= icon('ios_share') ?>共有</b> をタップ
                        <span class="a2hs__sub">見つからないときは、右下の <b class="a2hs__key"><?= icon('more_horiz') ?></b> →「共有」</span></div>
                </li>
                <li>
                    <div>メニューを下にスクロールして <b class="a2hs__key"><?= icon('add_box') ?>ホーム画面に追加</b> をタップ</div>
                </li>
                <li>
                    <div>「Webアプリとして開く」がオンになっているのを確かめて、右上の <b class="a2hs__key">追加</b> をタップ</div>
                </li>
            </ol>
        </div>

        <div class="a2hs__panel" role="tabpanel" id="a2hs-chrome" aria-labelledby="a2hs-tab-chrome" data-a2hs-panel="chrome" hidden>
            <!-- Android の Chrome がインストールできると判断したときだけ出す（beforeinstallprompt。app.js の setupAddToHome） -->
            <button type="button" class="btn btn--primary a2hs__install" data-a2hs-install hidden><?= icon('install_mobile') ?>今すぐインストール</button>
            <ol class="a2hs__steps">
                <li>
                    <div>右上の <b class="a2hs__key"><?= icon('more_vert') ?></b>（縦に並んだ3つの点）をタップ</div>
                </li>
                <li>
                    <div><b class="a2hs__key"><?= icon('add_to_home_screen') ?>ホーム画面に追加</b> をタップ
                        <span class="a2hs__sub">「アプリをインストール」と出ていたら、そちらをタップ</span></div>
                </li>
                <li>
                    <div><b class="a2hs__key">インストール</b> をタップ</div>
                </li>
            </ol>
            <p class="a2hs__note muted small">iPhone の Chrome のときは、アドレスバーの右の <?= icon('ios_share') ?> →「ホーム画面に追加」</p>
        </div>

        <div class="form-actions">
            <button type="button" class="btn" data-a2hs-close>閉じる</button>
        </div>
    </div>
</dialog>
