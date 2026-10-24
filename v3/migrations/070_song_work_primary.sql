-- =====================================================================
--  楽曲データベース（songs.php）で、版違いの「メイン」を管理人が選んだ記録
-- =====================================================================
--  069_venue_info.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  同じ曲（アーティスト + 曲名のキー = work_key）の版違い（アルバム違いの song_key）は一覧で1行にまとめ、
--  メインの版（ふつうはオリジナル・アルバム）を出す。メインは自動で選ぶ（lib/tracks.php の song_version_rank）が、
--  外れたときだけここに1行入れて上書きする。行が無い曲は自動のまま。
--  song_key に外部キーを張らないのは、song_key が song / song_catalog / song_tab のどれから来るか決まっていないため
--  （指す版が無くなった行は、画面では無視して自動に戻る）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE song_work_primary (
    work_key   VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    song_key   VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (work_key),
    CONSTRAINT fk_swp_updated FOREIGN KEY (updated_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
