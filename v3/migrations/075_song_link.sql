-- =====================================================================
--  曲ごとの TAB 譜・コード譜のリンク（tab.php?song= / ?key= の「TAB譜・コード」）
-- =====================================================================
--  074_venue_note.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  U-FRET・楽器.me・Songsterr・YouTube などのページへのリンクを、曲（song_key）ごとに登録する。
--  中身はコピーしない（リンクを張るだけ）。site はリンクの種類（lib/tabs.php の TAB_LINK_SITES のキー）
--  title / artist_name は表示用の控え（song_tab と同じ。TAB が無くリンクだけの曲でも tab.php?key= が開けるように）
--  url は ASCII だけ（日本語はパーセントエンコード済みの形で保存）。UNIQUE (song_key, url) で同じリンクの二重登録を防ぐ
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE song_link (
    link_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    song_key   VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    site       VARCHAR(20)  NOT NULL,
    url        VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    label      VARCHAR(100) NULL,
    title      VARCHAR(100) NOT NULL,
    artist_name VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (link_id),
    UNIQUE KEY uq_link_url (song_key, url),
    CONSTRAINT fk_link_created FOREIGN KEY (created_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  song_link_practice — YouTube リンクの練習プレイヤーの設定（自分だけ。人ごと・リンクごとに1行）
--    data … {"m": [{"t": ミリ秒, "e": null, "l": "ラベル"}], "ab": [A, B] or null, "rate": 0.25〜2, "back": 戻す秒数}
--    m のラベルにイントロ・サビなどを付けると曲構成になる。検証は lib/tabs.php の tab_sanitize_link_practice()
--    link_id に外部キーを張る: リンクを削除したら練習データも消える（リンクには復元が無いため）
-- ---------------------------------------------------------------------
CREATE TABLE song_link_practice (
    link_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    data    JSON         NOT NULL,
    PRIMARY KEY (link_id, user_id),
    CONSTRAINT fk_linkprac_link FOREIGN KEY (link_id) REFERENCES song_link (link_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_linkprac_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
