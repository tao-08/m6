-- =====================================================================
--  135_special_content.sql — スペシャルコンテンツ（年度・ライブに付けるリンクとファイル）
-- =====================================================================
--  134_drop_audio_wave.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--
--  1行 = 1件。url か file_name のどちらか一方が入る（special.php が確かめる）
--    live_id が NULL … その年度全体に付けたもの（ライブ一覧の年度の見出しの右に出る）
--    live_id あり   … そのライブに付けたもの（fiscal_year はライブの年度を写して持つ）
--  live_id に外部キーは付けない: ライブを削除して変更履歴から戻すと同じ live_id で戻るため（song_tab_image と同じ）
--  ファイルは storage/live_images/ に置き、attach_image.php?k=special で出す
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE special_content (
    special_id  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    fiscal_year SMALLINT UNSIGNED NOT NULL,
    live_id     INT UNSIGNED      NULL,
    title       VARCHAR(100)      NOT NULL,
    url         VARCHAR(500)      NULL,
    file_name   VARCHAR(40)       NULL,
    user_id     INT UNSIGNED      NULL,
    created_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (special_id),
    KEY idx_special_year (fiscal_year),
    KEY idx_special_live (live_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
