-- =====================================================================
--  タイムテーブル作成ツール（tt_builder.php）の下書きと、同時編集の在席
-- =====================================================================
--  059_attach_image.sql まで流した DB（本番も含む）に、追加で流す。
--  下書きは「1つの文書」として JSON で持つ（state）。公開すると import.php の取り込みで
--  live / live_day / band / band_member に正規化して登録されるので、ここは JSON のままでいい。
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE timetable_draft (
    draft_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title             VARCHAR(100) NOT NULL,
    state             MEDIUMTEXT   NOT NULL,           -- JSON（日数・バンド・日程希望・並び・持ち時間など。同時編集で書き換わる）
    source            MEDIUMTEXT   NOT NULL,           -- JSON（読み取った名簿そのまま。公開時に取り込みへ渡す。編集しない）
    version           INT UNSIGNED NOT NULL DEFAULT 0, -- 変更のたびに +1（同時編集の同期用）
    created_by        INT UNSIGNED NULL,
    updated_by        INT UNSIGNED NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_live_id INT UNSIGNED NULL,
    PRIMARY KEY (draft_id),
    CONSTRAINT fk_ttd_created FOREIGN KEY (created_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_ttd_updated FOREIGN KEY (updated_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 誰が開いているか（下書き × ユーザーの多対多なので中間テーブル）
CREATE TABLE timetable_draft_presence (
    draft_id  INT UNSIGNED NOT NULL,
    user_id   INT UNSIGNED NOT NULL,
    focus     VARCHAR(30)  NULL,                       -- 操作中の行の id
    last_seen DATETIME     NOT NULL,
    PRIMARY KEY (draft_id, user_id),
    CONSTRAINT fk_ttp_draft FOREIGN KEY (draft_id) REFERENCES timetable_draft (draft_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_ttp_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
