<?php
/**
 * lyrics_search_test.php — 管理者向けの歌詞検索精度検証画面。
 * 入力したフレーズは外部の lrc.red に送信する。m6側では保存しない。
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();
if (!is_admin()) {
    error_page(403, '歌詞検索の検証は管理者のみ利用できます');
}

render_header('歌詞検索の検証', 'tools');
?>
<h1 class="display display--sm">歌詞検索の検証</h1>
<p class="muted">lrc.red の検索精度と、iTunes での曲情報・試聴可否を確認します。検証結果はこの画面だけに表示し、m6のデータベースには保存しません。</p>

<section class="card" style="padding:16px; margin:16px 0;">
    <form id="lyrics-probe-form" style="display:grid; gap:12px;">
        <label style="display:grid; gap:6px;">
            <span class="small muted">歌詞の一節（必須）</span>
            <input id="lyrics-probe-query" name="q" type="search" maxlength="100" minlength="3" required autocomplete="off" placeholder="検索したい歌詞の一部">
        </label>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr)); gap:10px;">
            <label style="display:grid; gap:6px;">
                <span class="small muted">正解の曲名（任意）</span>
                <input name="expected_title" type="text" maxlength="100" autocomplete="off" placeholder="例：曲名">
            </label>
            <label style="display:grid; gap:6px;">
                <span class="small muted">正解のアーティスト（任意）</span>
                <input name="expected_artist" type="text" maxlength="100" autocomplete="off" placeholder="例：アーティスト名">
            </label>
        </div>
        <p class="small muted" style="margin:0;">正解の曲名・アーティストも入力すると、検索結果の順位とiTunesでの照合可否を確認できます。両方入力してください。</p>
        <button class="btn" id="lyrics-probe-submit" type="submit">検索して検証</button>
        <button class="btn btn--secondary" id="lyrics-gemini-submit" type="button">Geminiでも検索</button>
    </form>
</section>

<p class="small muted">Gemini検証では、入力した歌詞フレーズがGoogle Gemini APIに送信され、Google検索で照合されます。歌詞本文は表示・保存しません。無料枠の入力データはGoogleのサービス改善に使用される場合があります。</p>

<p class="small muted">注意：入力した歌詞フレーズは検索のため lrc.red に送信されます。外部サービス側の取り扱いは lrc.red の規約・プライバシー条件に従います。m6側ではフレーズ・歌詞本文・検証結果を保存しません。</p>

<div id="lyrics-gemini-results" hidden>
    <h2 class="display display--xs" style="margin-top:22px;">Gemini検索結果</h2>
    <div id="lyrics-gemini-summary" class="card" style="padding:12px; margin:10px 0;"></div>
    <div id="lyrics-gemini-hits" style="display:grid; gap:8px;"></div>
    <div id="lyrics-gemini-sources" class="small muted" style="margin-top:10px;"></div>
</div>

<div id="lyrics-probe-status" class="card" role="status" aria-live="polite" style="padding:12px; margin:12px 0;" hidden></div>
<section id="lyrics-probe-results" hidden>
    <h2 class="display display--xs">検索結果</h2>
    <p class="small muted">Powered by <a href="https://lrc.red/" target="_blank" rel="noopener noreferrer">lrc.red</a>（歌詞本文は取得せず、曲のメタデータのみ表示）</p>
    <div id="lyrics-probe-summary" class="card" style="padding:12px; margin:10px 0;"></div>
    <div id="lyrics-probe-hits" style="display:grid; gap:8px;"></div>
    <h2 class="display display--xs" style="margin-top:22px;">iTunes照合</h2>
    <div id="lyrics-probe-itunes" class="card" style="padding:12px;"></div>
</section>

<script>
(() => {
    const form = document.getElementById('lyrics-probe-form');
    if (!form) return;
    const button = document.getElementById('lyrics-probe-submit');
    const status = document.getElementById('lyrics-probe-status');
    const results = document.getElementById('lyrics-probe-results');
    const summary = document.getElementById('lyrics-probe-summary');
    const hitsBox = document.getElementById('lyrics-probe-hits');
    const itunesBox = document.getElementById('lyrics-probe-itunes');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const geminiButton = document.getElementById('lyrics-gemini-submit');
    const geminiResults = document.getElementById('lyrics-gemini-results');
    const geminiSummary = document.getElementById('lyrics-gemini-summary');
    const geminiHits = document.getElementById('lyrics-gemini-hits');
    const geminiSources = document.getElementById('lyrics-gemini-sources');

    geminiButton?.addEventListener('click', async () => {
        if (!form.reportValidity()) return;
        geminiButton.disabled = true;
        geminiResults.hidden = true;
        setMessage('Gemini + Google検索で候補を調べています…');
        const values = Object.fromEntries(new FormData(form).entries());
        try {
            const response = await fetch('api_lyrics_search_gemini_probe', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify(values),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.error || 'Gemini検索に失敗しました');

            geminiSummary.replaceChildren();
            appendText(geminiSummary, 'strong', '', data.expected_match_rank
                ? '正解候補を' + data.expected_match_rank + '位で発見'
                : (values.expected_title && values.expected_artist
                    ? '入力した正解曲は候補に見つかりませんでした'
                    : '正解曲名・アーティストを入力すると順位を判定できます'));
            appendText(geminiSummary, 'p', 'small muted', 'モデル: ' + data.model + ' / 候補 ' + data.candidate_count + '件');

            geminiHits.replaceChildren();
            if (!Array.isArray(data.candidates) || data.candidates.length === 0) {
                appendText(geminiHits, 'p', 'muted', '候補が見つかりませんでした。');
            } else {
                data.candidates.forEach((hit, index) => {
                    const card = document.createElement('article');
                    card.className = 'card';
                    card.style.padding = '12px';
                    appendText(card, 'strong', '', (index + 1) + '. ' + hit.title);
                    appendText(card, 'div', '', hit.artist);
                    appendText(card, 'div', 'small muted', [hit.album, hit.year || '', hit.confidence == null ? '' : '確信度 ' + Math.round(hit.confidence * 100) + '%'].filter(Boolean).join(' ・ '));
                    if (hit.expected_match) appendText(card, 'div', 'small', '正解候補');
                    geminiHits.append(card);
                });
            }
            geminiSources.replaceChildren();
            appendText(geminiSources, 'strong', '', 'Google検索の参照元');
            (data.sources || []).forEach((source) => {
                const link = document.createElement('a');
                link.href = source.url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = source.title;
                link.style.display = 'block';
                geminiSources.append(link);
            });
            geminiResults.hidden = false;
            setMessage('Geminiの検証が完了しました。結果は保存されていません。');
        } catch (error) {
            setMessage(error.message || 'Gemini検索に失敗しました');
        } finally {
            geminiButton.disabled = false;
        }
    });


    const setMessage = (message) => {
        status.hidden = false;
        status.textContent = message;
    };
    const appendText = (parent, tag, className, value) => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        element.textContent = value == null || value === '' ? '—' : String(value);
        parent.append(element);
        return element;
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        button.disabled = true;
        results.hidden = true;
        setMessage('lrc.redで検索しています…');
        const values = Object.fromEntries(new FormData(form).entries());
        try {
            const response = await fetch('api_lyrics_search_probe', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify(values),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.error || '検索に失敗しました');

            summary.replaceChildren();
            const expected = values.expected_title && values.expected_artist;
            const summaryLine = expected
                ? (data.expected_match_rank
                    ? '正解候補を' + data.expected_match_rank + '位で発見'
                    : '入力した正解曲は上位検索結果に見つかりませんでした')
                : '正解曲名・アーティストを入力すると、検索順位を判定できます';
            appendText(summary, 'strong', '', summaryLine);
            appendText(summary, 'p', 'small muted', '候補 ' + data.hit_count + '件 / 検索パターン ' + data.variants_used + '回。歌詞本文は取得していません。');

            hitsBox.replaceChildren();
            if (!Array.isArray(data.hits) || data.hits.length === 0) {
                appendText(hitsBox, 'p', 'muted', '候補が見つかりませんでした。別の箇所や表記で検索してください。');
            } else {
                data.hits.forEach((hit, index) => {
                    const card = document.createElement('article');
                    card.className = 'card';
                    card.style.padding = '12px';
                    const top = document.createElement('div');
                    top.style.display = 'flex';
                    top.style.justifyContent = 'space-between';
                    top.style.gap = '10px';
                    appendText(top, 'strong', '', (index + 1) + '. ' + hit.title);
                    appendText(top, 'span', 'tag', hit.query_variant || '');
                    card.append(top);
                    appendText(card, 'div', '', hit.artist);
                    appendText(card, 'div', 'small muted', [hit.album, hit.year, hit.duration ? Math.round(hit.duration) + '秒' : ''].filter(Boolean).join(' ・ '));
                    appendText(card, 'div', 'small muted', 'ISRC: ' + (hit.isrc || 'なし'));
                    if (expected && data.expected_match_rank === index + 1) {
                        appendText(card, 'div', 'small', '正解候補');
                    }
                    hitsBox.append(card);
                });
            }

            itunesBox.replaceChildren();
            if (!data.itunes_checked) {
                appendText(itunesBox, 'p', 'muted', expected
                    ? 'iTunes照合に失敗しました。検索自体の結果とは分けて確認してください。'
                    : '正解の曲名・アーティストを入力すると、iTunesの照合を実行します。');
            } else {
                const itunesLine = data.itunes_expected_match
                    ? (data.itunes_preview_available ? '正解曲を照合でき、プレビューもあります。' : '正解曲は照合できましたが、プレビューはありません。')
                    : 'iTunesでは正解曲を照合できませんでした。';
                appendText(itunesBox, 'strong', '', itunesLine);
                (data.itunes_candidates || []).forEach((candidate) => {
                    const line = candidate.title + ' / ' + candidate.artist
                        + (candidate.preview_available ? ' ・プレビューあり' : ' ・プレビューなし')
                        + (candidate.expected_match ? ' ・正解候補' : '');
                    appendText(itunesBox, 'p', 'small muted', line);
                });
            }

            results.hidden = false;
            setMessage('検証が完了しました。結果は保存されていません。');
        } catch (error) {
            setMessage(error.message || '検索に失敗しました');
        } finally {
            button.disabled = false;
        }
    });
})();
</script>
<?php render_footer(); ?>
