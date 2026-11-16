-- =====================================================================
--  113_sched_google.sql — 日程調整：Google カレンダーとの連携（OAuth）。予定の読み込みと、決定した練習の書き込み
-- =====================================================================
--  112_sched_feed.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（先にデプロイすると、マイスケジュール・日程調整が「テーブルが無い」エラーになる）
--
--  部員が「Google で連携」を押すだけで、次の2つができるようになる（lib/sched_google.php）。
--    1. 読み込み … Google カレンダーの予定を、iCal の取り込みと同じルールで sched_busy（source = 1）に入れる
--    2. 書き込み … 決定（sched_poll.status = 3）した練習を、その部員の「メインのカレンダー」にその場で入れる
--
--  sched_google … 連携している部員（1人1行）。トークンは暗号化して入れる（lib/sched_ical.php の sched_ical_encrypt。iCal の URL と同じ鍵）
--    refresh_token  … 長く使える「合言葉」。これで新しい access_token をもらう。漏れると、その人のカレンダーを触られるので暗号化
--    access_token   … 短い（約1時間）の合言葉。期限は access_expires。切れたら refresh_token でもらい直す
--    email          … 画面に出す連携先のメールアドレス（calendarList の primary の id）
--    fetched_at     … 最後に予定を読めた日時（iCal の sched_profile.ical_fetched_at と同じ役目）
--    error          … 最後に失敗した理由（成功したら NULL）
--
--  sched_google_event … 「どの練習」を「どの部員の Google カレンダー」の「どの予定」として入れたか
--    練習と部員の組み合わせ（many-to-many）を、この中間テーブルで解決している。
--    event_id が無いと、あとで変更・削除（取り消し・欠席・調整の削除）をしに行けない。
--    練習（sched_poll）を消すと行も消える（CASCADE）。Google 側の予定は、消す前に lib/sched_google.php の sched_google_remove_poll で消しておく
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE sched_google (
    member_id      INT UNSIGNED  NOT NULL,
    refresh_token  VARCHAR(1024) NOT NULL,          -- 暗号化
    access_token   VARCHAR(2048) NULL,              -- 暗号化
    access_expires DATETIME      NULL,
    email          VARCHAR(255)  NULL,
    fetched_at     DATETIME      NULL,
    error          VARCHAR(100)  NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (member_id),
    CONSTRAINT fk_sgoogle_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE sched_google_event (
    sched_poll_id INT UNSIGNED  NOT NULL,
    member_id     INT UNSIGNED  NOT NULL,
    event_id      VARCHAR(255)  NOT NULL,           -- Google の予定 ID
    PRIMARY KEY (sched_poll_id, member_id),
    KEY idx_sgevent_member (member_id),             -- 「この部員に入れた予定」を探す（連携の解除）
    CONSTRAINT fk_sgevent_poll FOREIGN KEY (sched_poll_id) REFERENCES sched_poll (sched_poll_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sgevent_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
