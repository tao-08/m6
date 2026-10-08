-- =====================================================================
--  band.needs_check を追加する（1 = 楽器の登録があっているか確認してほしいバンド）
--  014_band_song_count_null.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  取り込み（import.php）の名簿で 🚩 を付けたバンドが 1 になる。
--  バンドページの「確認した」か、バンド編集で保存すると 0 に戻る。
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE band
    ADD COLUMN needs_check TINYINT(1) NOT NULL DEFAULT 0 AFTER is_omnibus;
