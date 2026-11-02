-- =====================================================================
--  楽器ごとの色を DB に持たせる（楽器の管理ページ instruments.php で変更できるように）
-- =====================================================================
--  083_studio.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  instrument.color
--    '#rrggbb'（ライトモードでの色）。NULL = 色なし（グレー = その他と同じ色）
--    ダークモードの色は保存しない。表示するときに CSS で明るく補正する（lib/bootstrap.php の instrument_color_css）
--
--  Vo / Gt / Ba / Dr / Key / Cho / etc（変更できない楽器）の色は、これまでどおり assets/app.css に書いてある色を使う。
--  ここでは、それ以外の「最初から入っている楽器」に、今 app.css で付いている色を入れておく（見た目が変わらないように）。
--  名簿の取り込みで追加された楽器は NULL のまま（今までどおりグレー）。
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE instrument
    ADD COLUMN color CHAR(7) NULL AFTER short_name;

-- 今の app.css の色（--dr / --vn / --sax / --prog / --harm）。Perc はこれまで Dr と同じ色だった
UPDATE instrument SET color = '#d90f0f' WHERE short_name = 'Perc';
UPDATE instrument SET color = '#e8590c' WHERE short_name = 'Vn';
UPDATE instrument SET color = '#9c7a2e' WHERE short_name = 'Sax';
UPDATE instrument SET color = '#1d6f96' WHERE short_name = 'Prog';
UPDATE instrument SET color = '#4a6b85' WHERE short_name = 'Harm';
