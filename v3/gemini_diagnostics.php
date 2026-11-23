<?php
/**
 * gemini_diagnostics.php — 管理者限定のGemini接続診断画面。
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();
if (!is_admin()) {
    error_page(403, 'Gemini接続診断は管理者のみ利用できます');
}

render_header('Gemini接続診断', 'tools');
?>
<h1 class="display display--sm">Gemini接続診断</h1>
<p class="muted">サーバーからGoogle Gemini APIまでの接続・認証を確認します。実行すると短い固定文をGeminiへ1回送信するため、APIの利用回数・上限に影響します。</p>
<section class="card" style="padding:16px; margin:16px 0;">
    <p class="small muted">APIキーはサーバー内でのみ使用し、画面には表示しません。Googleから返るレスポンス本文も表示・保存しません。</p>
    <button type="button" class="btn btn--primary" id="gemini-diagnostics-run">接続診断を実行</button>
    <p id="gemini-diagnostics-status" role="status" aria-live="polite" class="muted small" style="margin:12px 0 0;"></p>
    <dl id="gemini-diagnostics-results" style="display:grid; grid-template-columns:minmax(120px, .7fr) 1.3fr; gap:10px 14px; margin-top:16px;" hidden></dl>
</section>
<script>
(() => {
    const button = document.getElementById('gemini-diagnostics-run');
    const status = document.getElementById('gemini-diagnostics-status');
    const results = document.getElementById('gemini-diagnostics-results');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const labels = {
        php_version: 'PHPバージョン',
        curl_available: 'cURL拡張',
        openssl_available: 'OpenSSL拡張',
        allow_url_fopen: 'allow_url_fopen',
        dns_ok: 'Google APIのDNS解決',
        dns_ip: '解決したIPアドレス',
        api_key_configured: 'APIキー設定',
        model: 'モデル',
        http_status: 'HTTPステータス',
        latency_ms: '応答時間',
        curl_errno: 'cURLエラー番号',
        category: '診断結果',
        message: '判定'
    };
    const display = (key, value) => {
        if (typeof value === 'boolean') return value ? '有効' : '無効';
        if (value === null || value === undefined || value === '') return '—';
        if (key === 'latency_ms') return value + ' ms';
        return String(value);
    };
    button.addEventListener('click', async () => {
        button.disabled = true;
        results.hidden = true;
        status.textContent = 'Google APIへ接続しています。最大25秒ほどかかります…';
        try {
            const response = await fetch('api_gemini_diagnostics', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: '{}',
                cache: 'no-store'
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.error || '診断APIの実行に失敗しました');
            results.replaceChildren();
            Object.entries(labels).forEach(([key, label]) => {
                if (!(key in data)) return;
                const dt = document.createElement('dt');
                dt.className = 'small muted';
                dt.textContent = label;
                const dd = document.createElement('dd');
                dd.style.margin = '0';
                dd.textContent = display(key, data[key]);
                results.append(dt, dd);
            });
            results.hidden = false;
            status.textContent = '診断が完了しました。';
        } catch (error) {
            status.textContent = error.message || '診断に失敗しました。';
        } finally {
            button.disabled = false;
        }
    });
})();
</script>
<?php render_footer();
