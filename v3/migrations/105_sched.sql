-- =====================================================================
--  105_sched.sql — スタ連の日程調整（sched.php / sched_poll.php / sched_me.php）
-- =====================================================================
--  104_block_score.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（先にデプロイすると日程調整のページが開けない）
--
--  ■ 全体の考え方
--    「いつ空いていないか（埋まり）」を部員ごとに集めて、調整のたびに計算で空き枠を出す。
--    空き枠そのものは保存しない（＝計算で出せるものは保存しない。保存すると、元データが変わったときに古くなるため）。
--
--  sched_profile … 部員1人に1行の設定（キャンパス・iCal の URL）
--    campus      … 1 = 和泉 / 2 = 駿河台 / NULL = 学年から自動（1・2年 → 和泉、3年以上 → 駿河台）
--    ical_url    … iCal の非公開 URL を「暗号化したもの」（base64）。URL を知っていれば誰でも予定を読めるので、平文では持たない
--    ical_*      … 最後に読んだ日時・読めなかった理由（画面に出す）
--
--  sched_class … 部員の時間割（1コマ = 1行）。授業名は持たない（「埋まり」だけわかればよいので）
--    term    … 1 = 春学期 / 2 = 秋学期
--    weekday … 1 = 月 〜 6 = 土（PHP の date('N') と同じ番号）
--    period  … 1〜6 限
--    → 主キーを (member_id, term, weekday, period) の4列にする（複合主キー）。同じコマを二重に入れられない
--
--  sched_busy … 部員の「埋まり」の時間帯（手入力の NG 枠と、iCal から取り込んだ予定）
--    source … 0 = 手入力 / 1 = iCal（読み直すたびに 1 の行だけ入れ替える）
--    ★ 予定のタイトル・場所は持たない（開始と終了だけ）。持たなければ漏れようがない
--
--  sched_poll … 日程調整1件
--    status … 0 = 調整中 / 1 = 仮決定（日だけ）/ 2 = 仮決定（日時まで）/ 3 = 決定
--    fix_*  … 仮決定・決定した日時とスタジオ（日だけの仮決定は fix_date だけ入る）
--    version … 保存のたびに +1（楽観ロック。古い画面から上書きされるのを防ぐ）
--
--  sched_poll_member … 調整に呼ばれた部員（調整 × 部員の many-to-many → 中間テーブル）
--    answered_at … 「回答済み」を押した日時。NULL = 未回答
--
--  sched_poll_studio … 調整の候補スタジオ（調整 × スタジオの many-to-many → 中間テーブル）
--
--  sched_travel … 移動時間の「上書き」の表。ふだんは住所（緯度・経度）から自動で見積もる（lib/sched.php の sched_travel_estimate）。
--    直線距離の見積もりがズレる組み合わせ（乗り換えが多い等）だけ、幹部が設定画面で分を入れる
--    from_place / to_place … 'campus:和泉' / 'studio:12' のような「場所のキー」。向きは問わない（片方だけ入れればよい）
--
--  studio.sched_default    … 1 = 日程調整を作るとき、最初からチェックが入るスタジオ
--  studio_room.capacity    … 部屋の定員（人）。NULL = 不明（広さ（帖）から目安で判定する）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE sched_profile (
    member_id     INT UNSIGNED NOT NULL,
    campus        TINYINT UNSIGNED NULL,          -- 1 = 和泉 / 2 = 駿河台 / NULL = 学年から自動
    ical_url      VARCHAR(1024) NULL,             -- 暗号化した iCal の URL（lib/sched_ical.php）
    ical_fetched_at DATETIME   NULL,              -- 最後に読めた日時
    ical_error    VARCHAR(100) NULL,              -- 最後に読めなかった理由（読めたら NULL）
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (member_id),
    CONSTRAINT fk_sprof_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_sprof_campus CHECK (campus IS NULL OR campus IN (1, 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE sched_class (
    member_id INT UNSIGNED     NOT NULL,
    term      TINYINT UNSIGNED NOT NULL,          -- 1 = 春 / 2 = 秋
    weekday   TINYINT UNSIGNED NOT NULL,          -- 1 = 月 〜 6 = 土
    period    TINYINT UNSIGNED NOT NULL,          -- 1〜6 限
    PRIMARY KEY (member_id, term, weekday, period),
    CONSTRAINT fk_sclass_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_sclass_range CHECK (term IN (1, 2) AND weekday BETWEEN 1 AND 6 AND period BETWEEN 1 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE sched_busy (
    sched_busy_id INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    member_id     INT UNSIGNED     NOT NULL,
    start_at      DATETIME         NOT NULL,
    end_at        DATETIME         NOT NULL,
    source        TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 0 = 手入力 / 1 = iCal
    PRIMARY KEY (sched_busy_id),
    -- 「この部員の、この期間の埋まり」を探すので (member_id, start_at) の順で索引を張る（複合インデックスは左の列から使われる）
    KEY idx_sbusy_member (member_id, start_at),
    CONSTRAINT fk_sbusy_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_sbusy_range CHECK (end_at > start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE sched_poll (
    sched_poll_id INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    title         VARCHAR(100)      NOT NULL,          -- 例: バンド名
    date_from     DATE              NOT NULL,          -- 候補の期間
    date_to       DATE              NOT NULL,
    day_start     SMALLINT UNSIGNED NOT NULL,          -- 候補の時間帯（0時からの分。例: 600 = 10:00）
    day_end       SMALLINT UNSIGNED NOT NULL,          -- 例: 1380 = 23:00
    length_min    SMALLINT UNSIGNED NOT NULL,          -- 練習の長さ（分）
    late_max      SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- 遅刻を許す上限（分）。0 = 全員そろう枠だけ
    status        TINYINT UNSIGNED  NOT NULL DEFAULT 0, -- 0 = 調整中 / 1 = 仮決定（日）/ 2 = 仮決定（日時）/ 3 = 決定
    fix_date      DATE              NULL,
    fix_start     DATETIME          NULL,
    fix_end       DATETIME          NULL,
    fix_studio_id INT UNSIGNED      NULL,
    fix_room_id   INT UNSIGNED      NULL,
    version       INT UNSIGNED      NOT NULL DEFAULT 0,
    created_by    INT UNSIGNED      NULL,
    created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sched_poll_id),
    KEY idx_spoll_fix (status, fix_start),             -- 「決定済みの練習」を期間で探す
    CONSTRAINT fk_spoll_user FOREIGN KEY (created_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_spoll_studio FOREIGN KEY (fix_studio_id) REFERENCES studio (studio_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_spoll_room FOREIGN KEY (fix_room_id) REFERENCES studio_room (studio_room_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_spoll_dates CHECK (date_to >= date_from),
    CONSTRAINT ck_spoll_times CHECK (day_end > day_start AND day_end <= 1440),
    CONSTRAINT ck_spoll_status CHECK (status BETWEEN 0 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE sched_poll_member (
    sched_poll_id INT UNSIGNED NOT NULL,
    member_id     INT UNSIGNED NOT NULL,
    answered_at   DATETIME     NULL,
    PRIMARY KEY (sched_poll_id, member_id),
    KEY idx_spm_member (member_id),                    -- 「この部員が呼ばれている調整」を探す
    CONSTRAINT fk_spm_poll FOREIGN KEY (sched_poll_id) REFERENCES sched_poll (sched_poll_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_spm_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE sched_poll_studio (
    sched_poll_id INT UNSIGNED NOT NULL,
    studio_id     INT UNSIGNED NOT NULL,
    PRIMARY KEY (sched_poll_id, studio_id),
    KEY idx_sps_studio (studio_id),
    CONSTRAINT fk_sps_poll FOREIGN KEY (sched_poll_id) REFERENCES sched_poll (sched_poll_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sps_studio FOREIGN KEY (studio_id) REFERENCES studio (studio_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE sched_travel (
    from_place VARCHAR(30)       NOT NULL,
    to_place   VARCHAR(30)       NOT NULL,
    minutes    SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (from_place, to_place)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE studio
    ADD COLUMN sched_default TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '1=日程調整の初期候補' AFTER recommended;

ALTER TABLE studio_room
    ADD COLUMN capacity TINYINT UNSIGNED NULL COMMENT '定員（人）。NULL=不明' AFTER area_jo;

-- 新宿ミュージアムとリンキィディンク神保町を初期候補にする（名前に含まれる文字で探す。見つからなければ何も変わらない）
--   本番の名前の書き方がわからないので、両方の表記で探す。外れたら設定画面でチェックを付け直す
UPDATE studio SET sched_default = 1
 WHERE name LIKE '%ミュージアム%' AND (name LIKE '%新宿%' OR area = '新宿')
    OR name LIKE '%リンキィディンク%神保町%' OR name LIKE '%リンキィディンク%' AND area = '神保町'
    OR name LIKE '%Rinky%Dink%' AND (name LIKE '%神保町%' OR area = '神保町');
