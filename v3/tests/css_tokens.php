<?php
// CSS の直書き検出（トークン化済みの種類だけ）。run.php から呼ぶ。
// 定義は assets/app.css の :root（--r-* / --fs-* / --t-* / --shadow-* / --ring）
declare(strict_types=1);

// 1つの宣言（プロパティと値）が直書きなら、その値を返す。問題なければ null
function css_token_violation(string $selector, string $prop, string $value): ?string
{
    $prop = strtolower($prop);
    $value = trim($value);

    if ($prop === 'border-radius' || preg_match('/^border-(?:top|bottom)-(?:left|right)-radius$/', $prop)) {
        // 1〜2px は線に近い細部なので対象外。% は形そのものなので対象外
        preg_match_all('/(?<![\w.-])(\d*\.?\d+)px\b/', $value, $m);
        foreach ($m[1] as $n) {
            if ((float)$n > 2) {
                return $value;
            }
        }
        return null;
    }

    if ($prop === 'font-size' || $prop === 'font') {
        // アイコンの大きさ・!important・9px 以下（図の中の小さな文字）は対象外
        // clamp() などの可変サイズも、段階にできないので対象外
        if (str_contains($value, '!important') || str_contains($value, '(') && !str_contains($value, 'var(')
            || preg_match('/\.icon|svg|__icon|icon-|heart|emoji/', $selector)) {
            return null;
        }
        // font の「/」より後ろは行の高さなので見ない
        if (preg_match('/(?<![\w.\/-])(\d*\.?\d+)px\b/', $value, $m) && (float)$m[1] >= 10) {
            return $value;
        }
        return null;
    }

    if (in_array($prop, ['transition', 'transition-duration', 'animation', 'animation-duration'], true)) {
        // 区切りは括弧の外のカンマだけ（cubic-bezier の中で切らない）
        foreach (preg_split('/,(?![^()]*\))/', $value) as $part) {
            // 本体の時間が var(--t-*) なら、続く数字は待ち時間なので見ない
            if (str_contains($part, 'var(--t-')) {
                continue;
            }
            preg_match_all('/(?<![\w.-])(\d*\.?\d+)(ms|s)\b/', $part, $m, PREG_SET_ORDER);
            if (!$m) {
                continue;
            }
            // 「visibility 0s linear .12s」は 2つ目の待ち時間が本体。それ以外は最初が時間で、残りの待ち時間は対象外
            $t = ((float)$m[0][1] === 0.0 && isset($m[1])) ? $m[1] : $m[0];
            $sec = $t[2] === 'ms' ? (float)$t[1] / 1000 : (float)$t[1];
            if ($sec > 0 && $sec < 0.4) { // 0.4秒以上は演出用で対象外
                return $value;
            }
        }
        return null;
    }

    if ($prop === 'box-shadow') {
        // 内側の影・発光・枠線代わり（ぼかしなし）・色変数を使う影は、段階にできないので対象外
        if (str_contains($value, 'inset') || str_contains($value, 'var(')) {
            return null;
        }
        // 「0 8px 24px 黒系」だけ検出する
        if (preg_match('/(?<![\w.-])-?\d*\.?\d+(?:px)?\s+-?\d*\.?\d+px\s+[1-9]\d*px\b[^,]*(?:rgba?\(\s*0[\s,]+0[\s,]+0\b|rgba?\(\s*20[\s,]+18[\s,]+30\b)/', $value)) {
            return $value;
        }
        return null;
    }

    return null;
}

// CSS 全文から直書きの一覧を返す: [['selector' => ..., 'prop' => ..., 'value' => ...], ...]
function css_token_scan(string $css): array
{
    $css = preg_replace('~/\*.*?\*/~s', '', $css);
    $hits = [];
    preg_match_all('/([^{}]*)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
    foreach ($rules as [, $selector, $body]) {
        $selector = trim((string)preg_replace('/\s+/', ' ', $selector));
        preg_match_all('/(?<![-\w])([a-z-]+)\s*:\s*([^;}]+)/', $body, $decls, PREG_SET_ORDER);
        foreach ($decls as [, $prop, $value]) {
            $bad = css_token_violation($selector, $prop, $value);
            if ($bad !== null) {
                $hits[] = ['selector' => $selector, 'prop' => $prop, 'value' => $bad];
            }
        }
    }
    return $hits;
}
