-- =====================================================================
--  live_break — タイムテーブルの「休憩・転換・撤収」などバンドではない枠
--  010_artist_alias.sql まで流した DB（本番も含む）に、1回だけ追加で流す。
--
--  バンド（band）とは別のテーブルにする。band に混ぜると、統計・検索・メンバーの出演回数など
--  バンドを数える所すべてで「休憩を除く」条件が要るようになるため。
--
--  並び: 「出演順が after_order のバンドの後」に入る（0 = 最初のバンドより前）。
--    同じ場所に2つ以上あるときは seq の小さい順。
--    バンドの出演順を「何番目の行か」で持つと、バンドを足したり消したりしたときに休憩の位置がずれるので、
--    「どのバンドの後か」で持つ。
--  外部キー live_day_id → live_day: 日程が消えたら休憩も一緒に消える
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE live_break (
    break_id    INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    live_day_id INT UNSIGNED      NOT NULL,
    after_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- この出演順のバンドの後（0 = 最初のバンドより前）
    seq         TINYINT UNSIGNED  NOT NULL DEFAULT 0,  -- 同じ場所に2つ以上あるときの並び
    name        VARCHAR(50)       NOT NULL,            -- 休憩 / 転換 / 撤収 など
    start_time  TIME              NULL,
    end_time    TIME              NULL,
    PRIMARY KEY (break_id),
    KEY idx_break_day (live_day_id, after_order, seq),
    CONSTRAINT fk_break_day FOREIGN KEY (live_day_id) REFERENCES live_day (live_day_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_break_time CHECK (start_time IS NULL OR end_time IS NULL OR end_time > start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
