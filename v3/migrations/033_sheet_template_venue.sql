-- =====================================================================
--  セッティングシートの型紙 ↔ 会場 をつなぐ中間テーブル（sheet_templates.php の「自動で選ぶ会場」）
--  032_show_back_button.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  前は sheet_template.venue_words（会場名の一部を改行区切りで手書き）だったが、
--  会場の一覧（venue）から選ぶ形に変えた。venue_id でつなぐので、会場の名前を変えてもつながったまま。
--  1つの型紙を複数の会場で使える・1つの会場に複数の型紙もありうる → many-to-many なので中間テーブル。
--  型紙か会場を消したら、つながりも一緒に消える（ON DELETE CASCADE）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE sheet_template_venue (
    template_id INT UNSIGNED NOT NULL,
    venue_id    INT UNSIGNED NOT NULL,
    PRIMARY KEY (template_id, venue_id),               -- 同じ組み合わせは1回だけ
    KEY idx_stv_venue (venue_id),
    CONSTRAINT fk_stv_template FOREIGN KEY (template_id) REFERENCES sheet_template (template_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_stv_venue FOREIGN KEY (venue_id) REFERENCES venue (venue_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE sheet_template DROP COLUMN venue_words;
