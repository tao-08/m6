-- =====================================================================
--  曲ページを「曲全体」＋「パート（Gt1・Ba など）ごと」のカードに分ける（tab.php）
-- =====================================================================
--  075_song_link.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  パートは「パート名」（song_tab.part と同じ。Gt1 / Ba …）で結び付ける。part = '' は曲全体。
--    song_part  … 自作 TAB がまだ無いパートのカード（「楽器を追加」で作る）。TAB があるパートは song_tab から出るので、ここに行が無くてもよい
--    song_link  … リンク（YouTube・U-FRET など）。part でどのカードのものかを決める
--    song_meta  … メモ・画像（meta_id に画像がぶら下がる）。(song_key, part) で1行
--  TAB の編集画面でパート名を変えたら、api_tab_save.php が song_part・song_link・song_meta の part も一緒に変える
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE song_part (
    part_id       INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    song_key      VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    part          VARCHAR(20)      NOT NULL,
    instrument_id TINYINT UNSIGNED NOT NULL,
    created_by    INT UNSIGNED     NULL,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (part_id),
    UNIQUE KEY uq_part (song_key, part),
    CONSTRAINT fk_part_instrument FOREIGN KEY (instrument_id) REFERENCES instrument (instrument_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_part_created FOREIGN KEY (created_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE song_link
    ADD COLUMN part VARCHAR(20) NOT NULL DEFAULT '' AFTER song_key,
    DROP INDEX uq_link_url,
    ADD UNIQUE KEY uq_link_url (song_key, part, url);

ALTER TABLE song_meta
    ADD COLUMN part VARCHAR(20) NOT NULL DEFAULT '' AFTER song_key,
    DROP INDEX uq_meta_key,
    ADD UNIQUE KEY uq_meta_key (song_key, part);
