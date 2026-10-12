/**
 * =====================================================================
 *  fontkit-shim.mjs — assets/vendor/fontkit-shim.min.js の元のソース
 * =====================================================================
 *  なぜこれが要るのか:
 *    pdf-lib が公式に勧めている @pdf-lib/fontkit（fontkit v1 の改造版）は、
 *    日本語フォントを「使った文字だけ埋め込む（subset）」と、一部の文字が消えるバグがある。
 *    （BIZ UDゴシック / Noto Sans JP / M PLUS 1p で「ヨルシカ」が「ヨ　カ」になった）
 *    新しい fontkit v2 ではこのバグが直っているが、pdf-lib が呼ぶ encodeStream() が無くなり encode() に変わった。
 *    そこで「v2 に encodeStream() を足して v1 のふりをさせる」薄い変換（シム / アダプタ と呼ぶ）を挟む。
 *
 *  作り直し方（fontkit を新しくしたいとき。Node.js が入っている PC で）:
 *      npm i fontkit@2 esbuild
 *      npx esbuild fontkit-shim.mjs --bundle --minify --format=iife --global-name=fontkit \
 *          --platform=browser --target=es2019 --outfile=../fontkit-shim.min.js
 *    esbuild = 依存しているライブラリを全部1つの JS にまとめる道具（バンドラ）。
 *    --global-name=fontkit で、ブラウザでは window.fontkit として使えるようになる。
 * =====================================================================
 */
import * as fk from 'fontkit';

/** pdf-lib の doc.registerFontkit() に渡すと、pdf-lib はこの create() を呼ぶ */
export function create(buf) {
    const font = fk.create(new Uint8Array(buf));
    const origCreateSubset = font.createSubset.bind(font);
    font.createSubset = () => {
        const subset = origCreateSubset();
        // pdf-lib は subset.encodeStream().on('data', …).on('end', …) という「ストリーム」の形で受け取る。
        // v2 の encode() は一発でバイト列を返すので、それを data → end の順に流すだけのニセのストリームを作る
        subset.encodeStream = () => {
            const handlers = {};
            const stream = {
                on(event, fn) {
                    handlers[event] = fn;
                    return stream; // .on().on() とつなげて書けるように自分を返す（メソッドチェーン）
                },
            };
            // setTimeout(…, 0): on() の登録が全部終わってから実行する（すぐ実行すると handlers が空）
            setTimeout(() => {
                try {
                    handlers.data?.(subset.encode());
                    handlers.end?.();
                } catch (e) {
                    handlers.error?.(e);
                }
            }, 0);
            return stream;
        };
        return subset;
    };
    return font;
}
