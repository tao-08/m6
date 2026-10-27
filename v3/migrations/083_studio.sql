-- =====================================================================
--  スタジオ割（studio.php）— ライブ前の練習場所の割り当て
-- =====================================================================
--  082_admin_see_anonymous.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  studio_plan … ライブ1つにつき1枚の「スタジオ割」。
--    部屋・枠の長さ・時間の行・どの枠のどの部屋にどのバンドか、を JSON（state）1個で持つ。
--    なぜ JSON？ → 画面の「表」をまるごと1つの文書として保存・同期するため（timetable_draft と同じ考え方）。
--      部屋割りは「確定した記録」ではなく作業中の下書きで、他の表から検索・集計しない。
--      検索・集計するデータ（＝練習が必要かの回答）は、下の studio_need に正規化して持つ。
--    version … 保存のたびに +1。2人が同時に保存したとき、古い画面からの上書きを防ぐ（楽観ロック）
--    share_token … 共有リンク（studio_answer?t=…）の合言葉。推測できない32文字。作り直すと古いリンクは使えなくなる
--    answer_open … 1 = 回答受付中 / 0 = 締切（回答ページで押せなくなる）
--
--  studio_need … バンドごとの「練習が必要か」の回答（バンド1組に1行）
--    band を消すと回答も消える（ON DELETE CASCADE）
--    answered_by … 回答したログイン中のユーザー。共有リンクから未ログインで答えたときは NULL
--    via_link    … 1 = 共有リンクからの回答
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE studio_plan (
    studio_plan_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    live_id        INT UNSIGNED NOT NULL,
    state          MEDIUMTEXT   NOT NULL,
    version        INT UNSIGNED NOT NULL DEFAULT 0,
    share_token    CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    answer_open    TINYINT(1)   NOT NULL DEFAULT 1,
    created_by     INT UNSIGNED NULL,
    updated_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (studio_plan_id),
    UNIQUE KEY uq_studio_live (live_id),         -- 1つのライブにスタジオ割は1枚
    UNIQUE KEY uq_studio_token (share_token),    -- 合言葉から1枚を探すので一意 + インデックス
    CONSTRAINT fk_studio_live FOREIGN KEY (live_id) REFERENCES live (live_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_studio_created FOREIGN KEY (created_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_studio_updated FOREIGN KEY (updated_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE studio_need (
    band_id     INT UNSIGNED NOT NULL,
    need        TINYINT(1)   NOT NULL,             -- 1 = 必要 / 0 = 不要（未回答は「行が無い」で表す）
    answered_by INT UNSIGNED NULL,
    via_link    TINYINT(1)   NOT NULL DEFAULT 0,
    answered_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (band_id),
    CONSTRAINT fk_sneed_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sneed_user FOREIGN KEY (answered_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
