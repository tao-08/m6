-- =====================================================================
--  AbbeyRoad.online v3 — 「一番合理的な構成」で設計し直したスキーマ
-- =====================================================================
--  local_abbeydb とは別の DB に流すこと（例: abbey_v3）。
--    phpMyAdmin で DB を作成 → 「インポート」でこのファイルを選ぶ
--
--  ER 図（1 ─< 多）
--
--    live ─< live_day ─< band >─ band_member ─< member ── user_account
--               │          │   │       │   ╲
--             venue   artist   │  instrument ╲
--                              └─< song ─< song_performer（曲ごとに誰が何を弾いたか）
--
--  設計の方針（なぜこうしたか）
--    1. 1つの事実は1か所にだけ書く（正規化）
--         - 「誰がどのバンドで何を弾いたか」は band_member の1テーブルだけ。
--           local_abbeydb の band_member + band_member_instrument の二重管理をやめた。
--         - 「ギターボーカル」は「Vo」と「Gt」の2行で表す（楽器の組み合わせを楽器マスタに入れない）。
--         - 「何のコピーか」を artist テーブルに分けた。
--           「ヨルシカ（安田）」「ヨルシカ（八木）」が同じヨルシカだと DB が分かるので、
--           「よくコピーされるアーティスト」が GROUP BY 1発で出せる。
--    2. 間違ったデータを DB が拒否する（制約）
--         - 主キー / UNIQUE / FOREIGN KEY / CHECK を全テーブルに付けた。
--         - 中間テーブルは複合主キーで「同じ行2回」を防ぐ。
--    3. 「分からない」は NULL で表す
--         - 日付不明を '0000-00-00'、年度不明を 0000 で表さない（NULL にする）。
--    4. 消すときの動きを決めておく（ON DELETE）
--         - ライブを消したら日程・バンド・出演記録も一緒に消える（CASCADE）。
--         - 出演記録があるメンバーは消せない（RESTRICT）→ 統合ツールを使う。
--
--  照合順序は utf8mb4_general_ci（XAMPP の MariaDB 10.4 で使え、
--  「は」と「ば」を別の文字として扱う。unicode_ci だと同じ扱いになり、名前の UNIQUE が誤爆する）
-- =====================================================================

SET NAMES utf8mb4;
-- 作り直し用: すでにテーブルがあれば消してから作る。
-- まっさらな DB に流すと「Note: #1051 '〜' は不明な表です」が表の数だけ出るが、
-- これは「消そうとした表が最初から無かった」というだけの“お知らせ”で、エラーではない。
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS song_link_practice, song_link, song_part, song_work_primary, venue_image, song_catalog, song_tab_ai_usage, song_meta_image, song_tab_image, song_meta, song_tab_practice, song_tab_revision, song_tab, timetable_draft_presence, timetable_draft, band_image, notice_image, board_post_image, live_image, push_subscription, comment_like, comment_reply, comment, board_thread_mute, board_notify_category, board_notify, board_post_like, board_post_reply, board_read, board_post, board_thread, board_category, notice_read, notice, notice_category, change_unit, change_op, inquiry, band_like, app_setting, login_attempt, track_preview, track_link_cache, album_link_cache,
    check_dismiss, band_check_member, member_role, role, artist_alias, member_favorite_album, song_performer, song, track, band_member, live_break, band,
    artist, live_day, live, venue, instrument, user_account, member;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
--  member — サークル員（出演する人）
-- ---------------------------------------------------------------------
CREATE TABLE member (
    member_id  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    name       VARCHAR(50)       NOT NULL,             -- 表示名（空白なしで統一して保存）
    name_kana  VARCHAR(50)       NULL,                 -- 不明なら NULL（'' で代用しない）
    bio        VARCHAR(100)      NULL,                 -- プロフィールメッセージ（改行可）。書いていなければ NULL
    entry_year SMALLINT UNSIGNED NULL,                 -- 入部年度
    faculty    VARCHAR(20)       NULL,                 -- 学部（FACULTIES のどれか）。NULL = 選んでいない
    music_app  VARCHAR(20) CHARACTER SET ascii NULL,  -- 使っている音楽アプリ（マイアルバムのリンクをこれで開く）。NULL = 選んでいない
    retired    TINYINT(1)        NOT NULL DEFAULT 0,   -- 1 = 退会済み（行は消さずに印だけ。出演履歴を残すため）
    created_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (member_id),
    UNIQUE KEY uq_member_name (name),
    CONSTRAINT ck_member_entry CHECK (entry_year IS NULL OR entry_year BETWEEN 1950 AND 2100),
    CONSTRAINT ck_member_music_app
        CHECK (music_app IS NULL OR music_app IN ('spotify', 'apple_music', 'youtube_music', 'line_music')),
    CONSTRAINT ck_member_faculty CHECK (faculty IS NULL OR faculty IN
        ('法学部', '政治経済学部', '商学部', '経営学部', '文学部', '情報コミュニケーション学部',
         '総合数理学部', '理工学部', '農学部', '国際日本学部'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  role — 係（会計・PA など）のマスタ。プロフィールの「新しい係」で増える
--  member_role — 誰がどの係か（1人で複数の係を持てるので中間テーブル）
--    主キー (member_id, role_id): 同じ人に同じ係を2回付けられない
--    使われている係は消せない（ON DELETE RESTRICT。消すなら masters.php で統合）
-- ---------------------------------------------------------------------
CREATE TABLE role (
    role_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name    VARCHAR(30)  NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,          -- 並び順（masters.php の ↑↓）
    PRIMARY KEY (role_id),
    UNIQUE KEY uq_role_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE member_role (
    member_id INT UNSIGNED NOT NULL,
    role_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (member_id, role_id),
    CONSTRAINT fk_mr_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_mr_role FOREIGN KEY (role_id) REFERENCES role (role_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  user_account — ログインするアカウント
--    メンバーと 1対1（または未紐付け）。member_id を UNIQUE にして
--    「1人のメンバーに2つのアカウント」を DB レベルで防ぐ。
-- ---------------------------------------------------------------------
CREATE TABLE user_account (
    user_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    login_id      VARCHAR(25)  NOT NULL,
    name          VARCHAR(50)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,               -- password_hash() の結果だけ
    password_changed_at DATETIME NULL,                 -- パスワードを変えた日時。セッションの写しと違う端末はログアウト（require_login。migrations/065）
    is_admin      BOOLEAN      NOT NULL DEFAULT FALSE, -- NULL を許さない（「NULL=管理者じゃない？」の曖昧さを消す）
    is_executive  BOOLEAN      NOT NULL DEFAULT FALSE, -- 幹部（管理者の権限の一部が使える。migrations/063）
    email         VARCHAR(254) NULL,                    -- 問い合わせの通知先。管理者だけが登録できる（contact.php）
    member_id     INT UNSIGNED NULL,
    tutorial_seen_at DATETIME  NULL,                    -- チュートリアルを閉じた日時。NULL = まだ見ていない（partials/tutorial.php）
    hide_retired_suggest BOOLEAN NOT NULL DEFAULT TRUE, -- 退会した人をメンバー名の候補に出さない（account.php）
    show_back_button BOOLEAN NOT NULL DEFAULT TRUE,     -- 左下の戻るボタンを出す（account.php・assets/app.js の setupBackButton）
    count_upcoming BOOLEAN NOT NULL DEFAULT FALSE,      -- まだ来ていない日程のバンドもメンバーページの集計に数える（account.php・member.php）
    push_board     BOOLEAN NOT NULL DEFAULT FALSE,      -- 掲示板をアプリ通知にする（account.php・lib/push.php。migrations/054）
    push_important BOOLEAN NOT NULL DEFAULT FALSE,      -- 重要なお知らせをアプリ通知にする
    push_notice    BOOLEAN NOT NULL DEFAULT FALSE,      -- その他のお知らせをアプリ通知にする
    practice_back  TINYINT UNSIGNED NULL,               -- 練習プレイヤーの戻す・進める秒数 1〜60（全動画共通。NULL = 動画ごとの値。migrations/081）
    practice_pad   TINYINT UNSIGNED NULL,               -- 練習プレイヤーのリピート前後幅 0〜10 秒（同上）
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_user_login (login_id),
    UNIQUE KEY uq_user_member (member_id),
    CONSTRAINT fk_user_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE SET NULL           -- メンバーが統合で消えても、アカウントは残る
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  venue — 会場
-- ---------------------------------------------------------------------
CREATE TABLE venue (
    venue_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(50)  NOT NULL,
    address     VARCHAR(255) NULL,
    website_url VARCHAR(255) NULL,
    note        VARCHAR(1000) NULL,                    -- メモ（搬入口・注意事項など。migrations/074）
    sort_order  INT UNSIGNED NOT NULL DEFAULT 0,       -- 並び順（masters.php の ↑↓）
    PRIMARY KEY (venue_id),
    UNIQUE KEY uq_venue_name (name)                    -- 同じ会場が2つできない
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  live — ライブ（例: 2025年度 文化祭ライブ）
-- ---------------------------------------------------------------------
CREATE TABLE live (
    live_id     INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    fiscal_year SMALLINT UNSIGNED NOT NULL,            -- 年度（4月始まり）。YEAR 型は 1901〜2155 しか入らず 0000 も入るので使わない
    name        VARCHAR(50)       NOT NULL,
    youtube_url VARCHAR(500)      NULL,                -- ライブ映像などの YouTube のリンク。無ければ NULL（migrations/008）
    PRIMARY KEY (live_id),
    UNIQUE KEY uq_live (fiscal_year, name),
    CONSTRAINT ck_live_year CHECK (fiscal_year BETWEEN 1990 AND 2100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  live_day — ライブの各日程（1日目 / 2日目 / 教室ライブ ...）
-- ---------------------------------------------------------------------
CREATE TABLE live_day (
    live_day_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    live_id      INT UNSIGNED NOT NULL,
    label        VARCHAR(50)  NOT NULL,                -- 「1日目」「教室ライブ」
    held_on      DATE         NULL,                    -- 開催日。不明なら NULL
    venue_id     INT UNSIGNED NULL,                    -- 不明なら NULL
    note         TEXT         NULL,
    total_bands  SMALLINT UNSIGNED NULL,               -- 総バンド数（手入力）。NULL = 登録済みのバンド数を使う。登録済みより少ないとトリ無し（migrations/013）
    PRIMARY KEY (live_day_id),
    UNIQUE KEY uq_live_day_label (live_id, label),     -- 同じライブに「1日目」が2つできない
    KEY idx_live_day_held_on (held_on),
    CONSTRAINT fk_day_live  FOREIGN KEY (live_id)  REFERENCES live (live_id)
        ON UPDATE CASCADE ON DELETE CASCADE,           -- ライブを消すと日程も消える
    CONSTRAINT fk_day_venue FOREIGN KEY (venue_id) REFERENCES venue (venue_id)
        ON UPDATE CASCADE ON DELETE SET NULL           -- 会場を消しても日程は残る
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  venue_station — 会場の最寄り駅（1会場に複数。venue_list.php / venue.php。migrations/069）
--    station_name は「駅」を付けずに保存。会場を消すと一緒に消える
-- ---------------------------------------------------------------------
CREATE TABLE venue_station (
    venue_station_id INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    venue_id         INT UNSIGNED      NOT NULL,
    station_name     VARCHAR(50)       NOT NULL,           -- 「駅」は付けずに保存（画面で「◯◯駅」と出す）
    walk_minutes     SMALLINT UNSIGNED NOT NULL,           -- 徒歩の分数
    sort_order       INT UNSIGNED      NOT NULL DEFAULT 0,
    PRIMARY KEY (venue_station_id),
    KEY idx_vs_venue (venue_id),
    CONSTRAINT fk_vs_venue FOREIGN KEY (venue_id) REFERENCES venue (venue_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  venue_equipment — 会場の機材（区分 + 機種名 + 台数。venue.php。migrations/069）
--    category の選択肢は venue.php の VENUE_EQUIP_CATEGORIES
-- ---------------------------------------------------------------------
CREATE TABLE venue_equipment (
    venue_equipment_id INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    venue_id           INT UNSIGNED      NOT NULL,
    category           VARCHAR(20)       NOT NULL,         -- 区分（ギターアンプ / ベースアンプ / ドラム / キーボード / PA / その他。一覧は venue.php の VENUE_EQUIP_CATEGORIES）
    model_name         VARCHAR(100)      NOT NULL,
    quantity           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    sort_order         INT UNSIGNED      NOT NULL DEFAULT 0,
    PRIMARY KEY (venue_equipment_id),
    KEY idx_ve_venue (venue_id),
    CONSTRAINT fk_ve_venue FOREIGN KEY (venue_id) REFERENCES venue (venue_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  venue_image — 会場の添付画像（7枚まで。venue.php。migrations/071）
-- ---------------------------------------------------------------------
CREATE TABLE venue_image (
    image_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    venue_id   INT UNSIGNED NOT NULL,
    file_name  VARCHAR(40)  NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (image_id),
    KEY idx_vi_venue (venue_id, sort_order),
    CONSTRAINT fk_vi_venue FOREIGN KEY (venue_id) REFERENCES venue (venue_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  artist — コピー元のアーティスト（ヨルシカ、King Gnu ...）
-- ---------------------------------------------------------------------
CREATE TABLE artist (
    artist_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name      VARCHAR(100) NOT NULL,
    name_kana VARCHAR(100) NULL,       -- ふりがな。不明なら NULL（'' で代用しない）
    PRIMARY KEY (artist_id),
    UNIQUE KEY uq_artist_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  artist_alias — アーティストの別名（Oasis ⇔ オアシス など）
--    マイアルバムの artist_name は Spotify / iTunes の表記そのまま（Apple はカタカナのことがある）。
--    文字列の比較だけでは結び付けられないので、管理者が artist.php で別名を登録する。
--    主キー name: 1つの別名は1アーティストだけを指す
--    外部キー artist_id → artist: アーティストが消えたら別名も消える（統合時は artist.php が先に付け替える）
-- ---------------------------------------------------------------------
CREATE TABLE artist_alias (
    name      VARCHAR(255) NOT NULL,   -- 別名。マイアルバムの artist_name と同じ長さ
    artist_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (name),
    KEY idx_alias_artist (artist_id),
    CONSTRAINT fk_alias_artist FOREIGN KEY (artist_id) REFERENCES artist (artist_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  band — その日に出演したコピーバンド1組
--    name は「タイムテーブルに書いてあった名前」（ヨルシカ(安田) など）をそのまま残す。
--    artist_id は「何のコピーか」。
-- ---------------------------------------------------------------------
CREATE TABLE band (
    band_id     INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    live_day_id INT UNSIGNED      NOT NULL,
    artist_id   INT UNSIGNED      NULL,                -- オリジナル曲のバンドなどは NULL
    name        VARCHAR(100)      NOT NULL,
    play_order  SMALLINT UNSIGNED NOT NULL,            -- 出演順。その日の最大がトリ
    start_time  TIME              NULL,
    end_time    TIME              NULL,
    song_count  TINYINT UNSIGNED  NULL,                -- 曲数。NULL = 不明（未入力）。0 = 0曲（migrations/014）
    note        VARCHAR(255)      NULL,                -- 鍵盤の私物/貸出など
    youtube_url VARCHAR(500)      NULL,                -- このバンドの演奏動画の YouTube のリンク。無ければ NULL（migrations/012）
    is_omnibus  TINYINT(1)        NOT NULL DEFAULT 0,  -- 1 = いろんなアーティストの曲をやるバンド（曲ごとに song.artist_id を持つ。artist_id は NULL）
    needs_check TINYINT UNSIGNED  NOT NULL DEFAULT 0,  -- 🚩 確認依頼の理由のビット（0 = なし / 1 担当楽器 / 2 Vo か Vo/Gt / 4 メンバー。migrations/015, 026）
    setlist_private TINYINT(1)    NOT NULL DEFAULT 0,  -- 1 = セットリストを終演30分後まで非公開（メンバーと管理者には見える。migrations/023）
    PRIMARY KEY (band_id),
    UNIQUE KEY uq_band_order (live_day_id, play_order), -- 同じ日に「3番目」が2組できない
    KEY idx_band_artist (artist_id),
    CONSTRAINT fk_band_day    FOREIGN KEY (live_day_id) REFERENCES live_day (live_day_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_band_artist FOREIGN KEY (artist_id) REFERENCES artist (artist_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_band_order CHECK (play_order >= 1),
    CONSTRAINT ck_band_time  CHECK (start_time IS NULL OR end_time IS NULL OR end_time > start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  live_break — タイムテーブルの「休憩・転換・撤収」などバンドではない枠
--    band に混ぜると、バンドを数える所すべてで「休憩を除く」条件が要るので別テーブル。
--    並び: 「出演順が after_order のバンドの後」（0 = 最初のバンドより前）。同じ場所に複数なら seq 順
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
--  instrument — 楽器マスタ
--    sort_order で表示順（Vo → Gt → Ba → Dr → Key ...）を DB が持つ。
--    「ギターボーカル」のような組み合わせは作らない（band_member に Vo と Gt の2行を入れる）。
-- ---------------------------------------------------------------------
CREATE TABLE instrument (
    instrument_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(30)      NOT NULL,           -- ボーカル
    short_name    VARCHAR(10)      NOT NULL,           -- Vo
    sort_order    TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (instrument_id),
    UNIQUE KEY uq_instrument_short (short_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO instrument (instrument_id, name, short_name, sort_order) VALUES
(1, 'ボーカル',       'Vo',   1),
(2, 'ギター',         'Gt',   2),
(3, 'ベース',         'Ba',   3),
(4, 'ドラム',         'Dr',   4),
(5, 'キーボード',     'Key',  5),
(6, 'コーラス',       'Cho',  6),
(7, 'パーカッション', 'Perc', 7),
(8, 'ヴァイオリン',   'Vn',   8),
(9, 'サックス',       'Sax',  9),
(11, '同期音源',      'Prog', 10),
(12, 'ハーモニカ',    'Harm', 11),
(10, 'その他',        'etc',  99);

-- ---------------------------------------------------------------------
--  band_member — 誰がどのバンドで何を担当したか（中間テーブル）
--    band と member の many-to-many を解決し、さらに楽器も持つ。
--    複合主キー (band_id, member_id, instrument_id):
--      ・同じ人・同じ楽器の重複を DB が拒否する
--      ・同じ人が Vo と Ba を兼任 → 2行（instrument_id が違うので OK）
-- ---------------------------------------------------------------------
CREATE TABLE band_member (
    band_id       INT UNSIGNED     NOT NULL,
    member_id     INT UNSIGNED     NOT NULL,
    instrument_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (band_id, member_id, instrument_id),
    KEY idx_bm_member (member_id),                     -- 「この人の出演履歴」を速く引くため
    KEY idx_bm_instrument (instrument_id),
    CONSTRAINT fk_bm_band       FOREIGN KEY (band_id)       REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,           -- バンドを消すと出演記録も消える
    CONSTRAINT fk_bm_member     FOREIGN KEY (member_id)     REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,          -- 出演記録がある人は消せない
    CONSTRAINT fk_bm_instrument FOREIGN KEY (instrument_id) REFERENCES instrument (instrument_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  band_check_member — 🚩 確認依頼で「誰について確認してほしいか」（バンド × メンバー。migrations/026）
-- ---------------------------------------------------------------------
CREATE TABLE band_check_member (
    band_id   INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (band_id, member_id),
    KEY idx_bcm_member (member_id),
    CONSTRAINT fk_bcm_band   FOREIGN KEY (band_id)   REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bcm_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  check_dismiss — 🚩 確認依頼のお知らせを ✕ で消した記録（ユーザー × バンド。migrations/027）
--    旗を外すと、そのバンドの行は消す（立て直したらまた届くように）
-- ---------------------------------------------------------------------
CREATE TABLE check_dismiss (
    user_id INT UNSIGNED NOT NULL,
    band_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, band_id),
    KEY idx_cd_band (band_id),
    CONSTRAINT fk_cd_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_cd_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  track — Spotify / iTunes の曲1つ（曲の編集画面で🔍検索して紐付けたもの）
--    登録した瞬間の内容を保存する（スナップショット）。表示のたびに外部 API へ聞きに行かない。
--    主キー (source, track_id): 「どのサービスの何番の曲か」。同じ曲を何バンドが紐付けても1行
--    source / track_id は ascii_bin（Spotify の ID は大文字小文字を区別するため。004 と同じ理由）
-- ---------------------------------------------------------------------
CREATE TABLE track (
    source       VARCHAR(10)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- 'itunes' / 'spotify'
    track_id     VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- そのサービスでの曲ID
    title        VARCHAR(255)      NOT NULL,                                  -- 曲名（サービスでの表記）
    artist_name  VARCHAR(255)      NOT NULL,
    album_title  VARCHAR(255)      NOT NULL,
    artwork_url  VARCHAR(500)      NOT NULL,                                  -- ジャケット（小さめ）
    release_year SMALLINT UNSIGNED NULL,
    PRIMARY KEY (source, track_id),
    CONSTRAINT ck_track_source CHECK (source IN ('itunes', 'spotify'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  song — バンドが演奏した曲（セットリスト）
--    UNIQUE (band_id, track_no): 同じバンドの「2曲目」が2つできない
--    UNIQUE (song_id, band_id) : song_id だけで一意なので意味は同じだが、
--      下の song_performer から「(曲, バンド) の組」で外部キーを張るために必要
--    artist_id: オムニバスのバンド（band.is_omnibus = 1）のときだけ入れる。
--      NULL = バンドのアーティスト（band.artist_id）と同じ。同じ値を全曲にコピーしないため
--    (track_source, track_id): 紐付けた Spotify / iTunes の曲。両方 NULL = 紐付けなし
-- ---------------------------------------------------------------------
CREATE TABLE song (
    song_id      INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    band_id      INT UNSIGNED     NOT NULL,
    track_no     TINYINT UNSIGNED NOT NULL,            -- 何曲目か
    title        VARCHAR(100)     NOT NULL,
    is_se        TINYINT(1)       NOT NULL DEFAULT 0,  -- 1 = SE（曲数・演奏者・統計に数えない。migrations/057）
    artist_id    INT UNSIGNED     NULL,                -- その曲のアーティスト（NULL = バンドと同じ）
    track_source VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
    track_id     VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (song_id),
    UNIQUE KEY uq_song_track (band_id, track_no),
    UNIQUE KEY uq_song_band (song_id, band_id),
    KEY idx_song_artist (artist_id),
    KEY idx_song_ext_track (track_source, track_id),
    CONSTRAINT fk_song_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_song_artist FOREIGN KEY (artist_id) REFERENCES artist (artist_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_song_ext_track FOREIGN KEY (track_source, track_id) REFERENCES track (source, track_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_song_ext_track CHECK ((track_source IS NULL) = (track_id IS NULL)), -- 片方だけ入っている行を作らない
    CONSTRAINT ck_song_track CHECK (track_no >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  song_performer — その曲で、誰が、何の楽器を演奏したか
--
--    外部キーを「(band_id, member_id, instrument_id) → band_member」に張っているのがポイント。
--    → 「そのバンドのメンバーとして登録されていない人・楽器」は曲の演奏者にできない、を DB が保証する。
--      （曲だけ別の楽器を弾いた場合は、先に band_member にその楽器を足す。songs_edit.php が自動でやる。
--        逆に、曲に出ている人がどの曲でも弾いていない楽器は band_member から消す = 担当楽器はセトリの実績どおりになる）
--    → バンドからメンバーを外すと（band_member の行を消すと）、その人の曲ごとの記録も CASCADE で消える。
--
--    (song_id, band_id) も外部キーにしているので、
--    「Aバンドの曲に、Bバンドのメンバーを登録する」ような食い違いも起きない。
-- ---------------------------------------------------------------------
CREATE TABLE song_performer (
    song_id       INT UNSIGNED     NOT NULL,
    band_id       INT UNSIGNED     NOT NULL,
    member_id     INT UNSIGNED     NOT NULL,
    instrument_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (song_id, member_id, instrument_id),
    KEY idx_sp_member (member_id),
    KEY idx_sp_role (band_id, member_id, instrument_id),
    CONSTRAINT fk_sp_song FOREIGN KEY (song_id, band_id) REFERENCES song (song_id, band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sp_role FOREIGN KEY (band_id, member_id, instrument_id)
        REFERENCES band_member (band_id, member_id, instrument_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------
--  member_favorite_album — メンバーが登録した「好きなアルバム」
--
--    ・1人で最大30枚（上限はアプリ側 member_album_save.php で数えて止める）
--    ・アルバムの情報は Spotify（無ければ iTunes）から取ってきて「登録した瞬間の内容」を保存する
--      （スナップショット保存。ページを開くたびに外部サービスに聞きに行かない
--        → 表示が速い & 外部サービスが止まってもページは壊れない）
--
--    主キー (member_id, sort_order)
--      → 「同じ人の3枚目」が2つできない。並び順そのものが行の住所になる。
--      ※ 列名を rank にしなかったのは、RANK が MySQL 8 で予約語（関数名）だから。
--        予約語を列名にすると毎回 `rank` とバッククォートで囲む必要があり、事故の元。
--        instrument テーブルの sort_order と名前を揃えた。
--
--    UNIQUE (member_id, source, album_id)
--      → 同じ人が同じアルバムを2回登録できない、を DB が保証する。
--      source = どのサービスから取ったか（'itunes' / 'spotify'）。ID の形がサービスごとに違うので2列で決める。
--      album_id は ascii_bin: Spotify の ID は大文字小文字を区別するので、区別して比べる照合順序にする
--
--    外部キー member_id → member
--      ON DELETE CASCADE: メンバーが消えたら（統合など）アルバムも一緒に消える（孤児データを残さない）
--      ON UPDATE CASCADE: member_id が変わったらこっちも追従する
-- ---------------------------------------------------------------------
CREATE TABLE member_favorite_album (
    member_id            INT UNSIGNED      NOT NULL,
    sort_order           SMALLINT UNSIGNED NOT NULL,          -- 表示順 1, 2, 3...（小さいほど前）
    source               VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- 'itunes' か 'spotify'
    album_id             VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- そのサービスでのアルバムID
    title                VARCHAR(255)      NOT NULL,          -- アルバム名
    artist_name          VARCHAR(255)      NOT NULL,          -- アーティスト名
    artwork_url          VARCHAR(500)      NOT NULL,          -- ジャケット画像のURL（600x600）
    release_year         SMALLINT UNSIGNED NULL,              -- 発売年。分からなければ NULL
    created_at           DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (member_id, sort_order),
    UNIQUE KEY uq_fav_album (member_id, source, album_id),
    CONSTRAINT fk_fav_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_fav_order CHECK (sort_order >= 1),
    CONSTRAINT ck_fav_source CHECK (source IN ('itunes', 'spotify'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  album_link_cache — 「このアルバムは、このアプリだとこの URL」の覚え書き（キャッシュ）
--
--    例: Spotify から登録したアルバムを Apple Music の人が押したとき、
--        iTunes で検索して見つけた Apple Music の URL をここに保存しておく。
--        → 2回目からは検索しないで済む（速い & 外部 API を叩きすぎない）
--
--    主キー (source, album_id, app): 「どのサービスの何番のアルバムを、どのアプリで開くか」で1行
--    url が NULL = 探したけど見つからなかった（そのときは検索ページを開く。毎回探し直さないために記録する）
--
--    ※ 外部キーは張っていない。source + album_id は Spotify / iTunes 側の ID で、
--      このDBに「アルバム」の親テーブルは無いため（member_favorite_album は「誰が登録したか」の表で、
--      同じアルバムが何人分も入るので参照先にできない）。消えても困らない「覚え書き」なのでこれで良い。
--    ※ source / album_id は 004 と同じく ascii_bin（Spotify の ID は大文字小文字を区別するため）
-- ---------------------------------------------------------------------
CREATE TABLE album_link_cache (
    source     VARCHAR(10)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- 'itunes' / 'spotify'
    album_id   VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- そのサービスでのアルバムID
    app        VARCHAR(20)  CHARACTER SET ascii NOT NULL,                   -- 開くアプリ（member.music_app と同じ値）
    url        VARCHAR(500) NULL,                                           -- 見つかった URL。NULL = 見つからなかった
    checked_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,             -- 調べた日時
    PRIMARY KEY (source, album_id, app),
    CONSTRAINT ck_alc_source CHECK (source IN ('itunes', 'spotify')),
    CONSTRAINT ck_alc_app CHECK (app IN ('spotify', 'apple_music', 'youtube_music', 'line_music'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  track_link_cache — 「この曲は、このアプリだとこの URL」の覚え書き（album_link_cache の曲版）
--    Spotify で紐付けた曲を Apple Music の人が押したとき、iTunes で探した結果を覚えておく（song_go.php）。
--    url が NULL = 探したけど見つからなかった。
--    こちらは親の track テーブルがあるので外部キーを張る（曲が消えたら覚え書きも消える）
-- ---------------------------------------------------------------------
CREATE TABLE track_link_cache (
    source     VARCHAR(10)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    track_id   VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    app        VARCHAR(20)  CHARACTER SET ascii NOT NULL,                   -- 開くアプリ（member.music_app と同じ値）
    url        VARCHAR(500) NULL,                                           -- 見つかった URL。NULL = 見つからなかった
    checked_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (source, track_id, app),
    CONSTRAINT fk_tlc_track FOREIGN KEY (source, track_id) REFERENCES track (source, track_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_tlc_app CHECK (app IN ('spotify', 'apple_music', 'youtube_music', 'line_music'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  track_preview — セットリストの曲の「30秒試聴」の音源 URL（band.php の ▶ ボタン。api_track_preview.php）
--    試聴は iTunes のものだけ（Spotify の API は試聴の URL を返さなくなった）。
--    Spotify で紐付けた曲は iTunes で同じ曲を探して、その試聴を使う。
--    preview_url が NULL = 探したけど無かった
-- ---------------------------------------------------------------------
CREATE TABLE track_preview (
    source      VARCHAR(10)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    track_id    VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    preview_url VARCHAR(500) NULL,                                           -- 試聴の音源（https://*.apple.com の m4a）
    checked_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (source, track_id),
    CONSTRAINT fk_tp_track FOREIGN KEY (source, track_id) REFERENCES track (source, track_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  login_attempt — ログイン・招待コードの失敗の記録（総当たり攻撃を止める。lib/bootstrap.php の too_many_failures）
--    login_id に外部キーは付けない: 「存在しないログインID」での失敗も数えたいので、user_account に無い値も入る
--    パスワードは入れない
-- ---------------------------------------------------------------------
CREATE TABLE login_attempt (
    attempt_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip           VARCHAR(45)     NOT NULL,
    login_id     VARCHAR(25)     NOT NULL,             -- 招待コードの失敗は '#invite'
    attempted_at DATETIME        NOT NULL,
    PRIMARY KEY (attempt_id),
    KEY idx_attempt_ip (ip, attempted_at),
    KEY idx_attempt_login (login_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  app_setting — サイトの設定（いまは招待コードだけ。users.php で管理者が変える）
--    行が無い設定は config.php の値を使う（lib/bootstrap.php の invite_code()）
-- ---------------------------------------------------------------------
CREATE TABLE app_setting (
    setting_key   VARCHAR(50)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- 'invite_code' など
    setting_value VARCHAR(255) NOT NULL,
    updated_by    INT UNSIGNED NULL,                                         -- 最後に変えた管理者
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_setting_user FOREIGN KEY (updated_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL                                   -- 変えた人のアカウントが消えても設定は残す
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  band_like — バンドのお気に入り（❤）。画面には数だけ出す（誰が押したかは出さない = 匿名。migrations/022）
--    複合主キー (band_id, user_id): 同じ人が同じバンドに2回付けられない
-- ---------------------------------------------------------------------
CREATE TABLE band_like (
    band_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (band_id, user_id),
    KEY idx_like_user (user_id),
    CONSTRAINT fk_like_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_like_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  inquiry — 問い合わせ・要望（contact.php。migrations/025）
--    メールが届かなかったときも消えないように DB にも残す。mailed = 1人以上に送れたか
-- ---------------------------------------------------------------------
CREATE TABLE inquiry (
    inquiry_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NULL,          -- NULL = 匿名（誰が送ったかを残さない）
    sender_name VARCHAR(50)  NULL,         -- ログインできない人が書いた氏名（contact_guest.php。migrations/067）
    contact    VARCHAR(100) NULL,          -- その人への返信先（メールアドレスなど）
    category   VARCHAR(20)  NOT NULL,
    body       TEXT         NOT NULL,
    mailed     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (inquiry_id),
    KEY idx_inquiry_user (user_id, created_at),
    CONSTRAINT fk_inquiry_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  change_op / change_unit — 変更履歴と「元に戻す」（history.php・lib/history.php。migrations/028）
--    change_op   … 1回の操作（保存ボタン1回 = 1行）。誰が・いつ・何をしたか
--    change_unit … その操作で変わったもの（ライブ / 日程 / バンド）1つ分の「変更前の状態」。NULL = 変更前は無かった
-- ---------------------------------------------------------------------
CREATE TABLE change_op (
    op_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL,          -- 誰が（アカウントを消したら NULL = 削除済みのユーザー）
    action      VARCHAR(30)  NOT NULL,      -- band_edit / timetable / import ...（lib/history.php の CHANGE_ACTIONS）
    summary     VARCHAR(255) NOT NULL,
    live_id     INT UNSIGNED NULL,          -- 絞り込み用。ライブが消えても履歴は残すので外部キーにしない
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    restored_at DATETIME     NULL,          -- 「元に戻す」をした日時
    restored_by INT UNSIGNED NULL,
    PRIMARY KEY (op_id),
    KEY idx_op_created (created_at),
    KEY idx_op_live (live_id),
    CONSTRAINT fk_op_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_op_restored_by FOREIGN KEY (restored_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE change_unit (
    op_id     INT UNSIGNED                 NOT NULL,
    unit_type ENUM('live', 'day', 'band')  NOT NULL,
    unit_id   INT UNSIGNED                 NOT NULL,
    label     VARCHAR(150)                 NOT NULL,  -- 表示用（2024年度 2月ライブ 1日目「King Gnu」）
    snapshot  MEDIUMBLOB                   NULL,      -- 変更前の行の JSON（圧縮）。NULL = 変更前は無かった
    PRIMARY KEY (op_id, unit_type, unit_id),
    KEY idx_unit_target (unit_type, unit_id),
    CONSTRAINT fk_unit_op FOREIGN KEY (op_id) REFERENCES change_op (op_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  sheet_template — 画面から追加したセッティングシートの型紙（migrations/031）
-- ---------------------------------------------------------------------
CREATE TABLE sheet_template (
    template_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    label       VARCHAR(100) NOT NULL,             -- プルダウンに出す名前
    pdf_path    VARCHAR(255) NOT NULL,             -- assets/sheets/uploads/xxx.pdf
    config      JSON         NOT NULL,
    created_by  INT UNSIGNED NULL,                 -- アカウントを消しても型紙は残す（NULL になる）
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (template_id),
    CONSTRAINT fk_sheet_template_user FOREIGN KEY (created_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  sheet_template_venue — 型紙 ↔ 会場（この会場のバンドは、この型紙を最初から選ぶ）（migrations/033）
-- ---------------------------------------------------------------------
CREATE TABLE sheet_template_venue (
    template_id INT UNSIGNED NOT NULL,
    venue_id    INT UNSIGNED NOT NULL,
    PRIMARY KEY (template_id, venue_id),
    KEY idx_stv_venue (venue_id),
    CONSTRAINT fk_stv_template FOREIGN KEY (template_id) REFERENCES sheet_template (template_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_stv_venue FOREIGN KEY (venue_id) REFERENCES venue (venue_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  notice / notice_read — 管理者のお知らせと既読（notices.php。migrations/042）
-- ---------------------------------------------------------------------
CREATE TABLE notice_category (
    category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(20)  NOT NULL,
    color       VARCHAR(10)  NOT NULL DEFAULT 'gray',  -- 表示色（NOTICE_COLORS。migrations/044）
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_notice_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO notice_category (name) VALUES ('お知らせ'), ('イベント'), ('システム');
INSERT INTO notice_category (name, color) VALUES ('コメント', 'pink'); -- バンドへのコメントの通知（migrations/052）
INSERT INTO notice_category (name, color) VALUES ('掲示板', 'purple'); -- 掲示板の書き込みの通知（migrations/055）

CREATE TABLE notice (
    notice_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NULL,          -- 書いた管理者。アカウントが消えても残す
    target_user_id INT UNSIGNED NULL,      -- 宛先。NULL = 全員。入っていればその人にだけ見える（migrations/052）
    category_id  INT UNSIGNED NOT NULL,
    is_important TINYINT(1)   NOT NULL DEFAULT 0,  -- 重要: 一覧で上に固定・ホームにバナー（migrations/043）
    title      VARCHAR(100) NOT NULL,
    body       TEXT         NOT NULL,
    link_url   VARCHAR(255) NULL,          -- お知らせから飛ぶ先（サイト内の相対 URL。migrations/052）
    publish_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- 公開日時。未来なら予約（migrations/045）
    pushed_at  DATETIME     NULL,          -- プッシュ通知を処理した日時。NULL = まだ（migrations/053）
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notice_id),
    KEY idx_notice_publish (publish_at),
    KEY idx_notice_pushed (pushed_at, publish_at),
    KEY idx_notice_important (is_important, notice_id),
    KEY idx_notice_target (target_user_id),
    CONSTRAINT fk_notice_target FOREIGN KEY (target_user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_notice_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_notice_category FOREIGN KEY (category_id) REFERENCES notice_category (category_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE notice_read (
    user_id   INT UNSIGNED NOT NULL,
    notice_id INT UNSIGNED NOT NULL,
    read_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, notice_id),
    KEY idx_nr_notice (notice_id),
    CONSTRAINT fk_nr_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_nr_notice FOREIGN KEY (notice_id) REFERENCES notice (notice_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- push_subscription — プッシュ通知を受け取る端末（lib/push.php。migrations/053）
CREATE TABLE push_subscription (
    subscription_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    endpoint        VARCHAR(500) NOT NULL,
    endpoint_hash   CHAR(64)     NOT NULL,
    p256dh          VARCHAR(255) NOT NULL,
    auth            VARCHAR(64)  NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (subscription_id),
    UNIQUE KEY uq_ps_endpoint (endpoint_hash),
    KEY idx_ps_user (user_id),
    CONSTRAINT fk_ps_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  board_* — 掲示板（board.php / board_thread.php。migrations/046）
-- ---------------------------------------------------------------------
CREATE TABLE board_category (
    category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(20)  NOT NULL,
    color       VARCHAR(10)  NOT NULL DEFAULT 'gray',  -- 表示色（notices.php の NOTICE_COLORS と同じキー）
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_board_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO board_category (name, color) VALUES ('雑談', 'gray'), ('募集', 'blue'), ('機材', 'green'), ('質問', 'yellow');

CREATE TABLE board_thread (
    thread_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind           VARCHAR(10)  NOT NULL DEFAULT 'free',
    entry_year     SMALLINT UNSIGNED NULL,               -- cohort のときだけ。どの入部年度のスレか
    fixed_key      VARCHAR(20)  NULL,                    -- 'active' / 'cohort:2025'。free は NULL（NULL は UNIQUE でも重複できる）
    category_id    INT UNSIGNED NULL,                    -- free のときだけ
    only_active    TINYINT(1)   NOT NULL DEFAULT 0,      -- free のとき 1 = 現役だけ
    cohort_year    SMALLINT UNSIGNED NULL,               -- free のとき値あり = その入部年度（立てた人の同期）だけ
    user_id       INT UNSIGNED NULL,                    -- 立てた人。active / cohort は NULL
    title          VARCHAR(100) NOT NULL,
    last_post_id   INT UNSIGNED NOT NULL DEFAULT 0,      -- 最後の書き込み（未読の判定用。書き込むたびに更新）
    last_posted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at     DATETIME     NULL,
    deleted_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (thread_id),
    UNIQUE KEY uq_board_fixed (fixed_key),
    KEY idx_board_last (last_posted_at),
    CONSTRAINT ck_board_kind CHECK (kind IN ('free', 'active', 'cohort')),
    CONSTRAINT fk_bt_category FOREIGN KEY (category_id) REFERENCES board_category (category_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_bt_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_bt_deleted_by FOREIGN KEY (deleted_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 自由スレに参加できる入部年度（行なし = 制限なし。migrations/056）
CREATE TABLE board_thread_year (
    thread_id  INT UNSIGNED      NOT NULL,
    entry_year SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (thread_id, entry_year),
    CONSTRAINT fk_bty_thread FOREIGN KEY (thread_id) REFERENCES board_thread (thread_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE board_post (
    post_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_id  INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NULL,
    body       TEXT         NOT NULL,
    anonymous  TINYINT(1)   NOT NULL DEFAULT 0,          -- 1 = 匿名（migrations/061）
    display_name VARCHAR(30) NULL,                       -- 送信元の名義（NULL = 本名。migrations/062）
    deleted_at DATETIME     NULL,
    deleted_by INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (post_id),
    KEY idx_bp_thread (thread_id, post_id),
    CONSTRAINT fk_bp_thread FOREIGN KEY (thread_id) REFERENCES board_thread (thread_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bp_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_bp_deleted_by FOREIGN KEY (deleted_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE board_read (
    user_id      INT UNSIGNED NOT NULL,
    thread_id    INT UNSIGNED NOT NULL,
    last_post_id INT UNSIGNED NOT NULL DEFAULT 0,        -- ここまで読んだ
    PRIMARY KEY (user_id, thread_id),
    KEY idx_br_thread (thread_id),
    CONSTRAINT fk_br_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_br_thread FOREIGN KEY (thread_id) REFERENCES board_thread (thread_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- スレ単位のミュート（migrations/050）。行があるスレはバッジに数えない
CREATE TABLE board_thread_mute (
    user_id   INT UNSIGNED NOT NULL,
    thread_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, thread_id),
    KEY idx_btm_thread (thread_id),
    CONSTRAINT fk_btm_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_btm_thread FOREIGN KEY (thread_id) REFERENCES board_thread (thread_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 通知設定（migrations/049）。board_notify の行が無い人は初期設定（すべて通知）
CREATE TABLE board_notify (
    user_id       INT UNSIGNED NOT NULL,
    cohort_active TINYINT(1)   NOT NULL DEFAULT 1,
    participating TINYINT(1)   NOT NULL DEFAULT 1,       -- 48時間以内に自分が書き込んだスレの新着を通知するか（migrations/050）
    PRIMARY KEY (user_id),
    CONSTRAINT fk_bn_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE board_notify_category (
    user_id     INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, category_id),
    KEY idx_bnc_category (category_id),
    CONSTRAINT fk_bnc_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bnc_category FOREIGN KEY (category_id) REFERENCES board_category (category_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 書き込みのお気に入り（❤）。書き込み × ユーザーの中間テーブル（migrations/048）
CREATE TABLE board_post_like (
    post_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, user_id),
    KEY idx_bpl_user (user_id),
    CONSTRAINT fk_bpl_post FOREIGN KEY (post_id) REFERENCES board_post (post_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bpl_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 書き込みの返信先（1つの書き込みが複数に返信できる中間テーブル。migrations/047）
CREATE TABLE board_post_reply (
    post_id          INT UNSIGNED NOT NULL,
    reply_to_post_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, reply_to_post_id),
    KEY idx_bpr_to (reply_to_post_id),
    CONSTRAINT fk_bpr_post FOREIGN KEY (post_id) REFERENCES board_post (post_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bpr_to FOREIGN KEY (reply_to_post_id) REFERENCES board_post (post_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ライブ・バンドのコメント（migrations/051）
CREATE TABLE comment (
    comment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    live_id    INT UNSIGNED NULL,
    band_id    INT UNSIGNED NULL,
    user_id    INT UNSIGNED NULL,
    body       TEXT         NOT NULL,
    anonymous  TINYINT(1)   NOT NULL DEFAULT 0,          -- 1 = 匿名（migrations/061）
    display_name VARCHAR(30) NULL,                       -- 送信元の名義（NULL = 本名。migrations/062）
    deleted_at DATETIME     NULL,
    deleted_by INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (comment_id),
    KEY idx_comment_live (live_id, comment_id),
    KEY idx_comment_band (band_id, comment_id),
    CONSTRAINT fk_comment_live FOREIGN KEY (live_id) REFERENCES live (live_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_comment_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_comment_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_comment_deleted_by FOREIGN KEY (deleted_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE comment_reply (
    comment_id       INT UNSIGNED NOT NULL,
    reply_to_comment_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (comment_id, reply_to_comment_id),
    KEY idx_cr_to (reply_to_comment_id),
    CONSTRAINT fk_cr_comment FOREIGN KEY (comment_id) REFERENCES comment (comment_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_cr_to FOREIGN KEY (reply_to_comment_id) REFERENCES comment (comment_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE comment_like (
    comment_id INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    PRIMARY KEY (comment_id, user_id),
    KEY idx_cl_user (user_id),
    CONSTRAINT fk_cl_comment FOREIGN KEY (comment_id) REFERENCES comment (comment_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_cl_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ライブの添付画像（migrations/058）。ファイル本体は storage/live_images/
CREATE TABLE live_image (
    image_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    live_id    INT UNSIGNED NOT NULL,
    file_name  VARCHAR(40)  NOT NULL,
    taken_at   DATETIME     NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (image_id),
    KEY idx_live_image_live (live_id, sort_order),
    CONSTRAINT fk_live_image_live FOREIGN KEY (live_id) REFERENCES live (live_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 掲示板・お知らせ・バンドの添付画像（migrations/059）。ファイル本体は storage/live_images/
CREATE TABLE board_post_image (
    image_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id    INT UNSIGNED NOT NULL,
    file_name  VARCHAR(40)  NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (image_id),
    KEY idx_bpi_post (post_id, sort_order),
    CONSTRAINT fk_bpi_post FOREIGN KEY (post_id) REFERENCES board_post (post_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE notice_image (
    image_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    notice_id  INT UNSIGNED NOT NULL,
    file_name  VARCHAR(40)  NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (image_id),
    KEY idx_ni_notice (notice_id, sort_order),
    CONSTRAINT fk_ni_notice FOREIGN KEY (notice_id) REFERENCES notice (notice_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE band_image (
    image_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    band_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NULL,
    file_name  VARCHAR(40)  NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (image_id),
    KEY idx_bi_band (band_id, sort_order),
    CONSTRAINT fk_bi_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bi_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

-- ---------------------------------------------------------------------
--  song_tab — 流れる TAB 譜（曲 × パート。song_key の形など、migrations/068 に説明）
-- ---------------------------------------------------------------------
CREATE TABLE song_tab (
    tab_id        INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    song_key      VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    track_source  VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
    track_id      VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    title         VARCHAR(100)     NOT NULL,
    artist_name   VARCHAR(255)     NULL,
    part          VARCHAR(20)      NOT NULL,                 -- Gt1 / Ba など
    instrument_id TINYINT UNSIGNED NOT NULL,                 -- Gt / Ba / Dr
    kind          ENUM('string', 'drum') NOT NULL DEFAULT 'string', -- drum = ドラム譜（tuning にレーンの略号が入り、fret は奏法になる。lib/tabs.php）
    first_bar     SMALLINT UNSIGNED NOT NULL DEFAULT 1,      -- 小節番号の最初（部分 TAB は途中の小節から。1 = 曲の頭から）
    is_partial    TINYINT(1)       NOT NULL DEFAULT 0,       -- 1 = 部分 TAB（ギターソロだけ等）。offset_ms = その部分が音源のどこから始まるか
    tuning        VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- 低い弦から "E A D G B E"（ドラムは上のレーンから "CR RD HH SD T1 T2 FT BD"）
    bpm           DECIMAL(5,2)     NOT NULL DEFAULT 120,
    beats_per_bar TINYINT UNSIGNED NOT NULL DEFAULT 4,
    steps_per_beat TINYINT UNSIGNED NOT NULL DEFAULT 4,      -- 4 = 16分音符まで
    offset_ms     INT UNSIGNED     NOT NULL DEFAULT 0,       -- 1小節目が始まる位置（音源の何ミリ秒目か）
    capo          TINYINT UNSIGNED NOT NULL DEFAULT 0,       -- カポ（0〜12）。フレットはカポを基準に書く（ふつうの TAB と同じ。カポ2なら、カポの位置が0）
    youtube_id    VARCHAR(11) CHARACTER SET ascii COLLATE ascii_bin NULL,
    links         MEDIUMTEXT       NOT NULL DEFAULT '[]',     -- 参考にする TAB サイトなどのリンク（JSON。[{"u": URL, "l": 表示名}]、最大10個。履歴・復元にも含まれる）
    memo          MEDIUMTEXT       NULL,                      -- この TAB（パート）のメモ（20000文字まで。URL は自動でリンクになる）
    data          MEDIUMTEXT       NOT NULL,
    created_by    INT UNSIGNED     NULL,
    updated_by    INT UNSIGNED     NULL,
    updated_at    DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3), -- ミリ秒まで（同じ1秒に2人が保存しても楽観ロックで気付けるように）
    PRIMARY KEY (tab_id),
    UNIQUE KEY uq_tab_part (song_key, part),
    KEY idx_tab_track (track_source, track_id),
    KEY idx_tab_instrument (instrument_id),
    CONSTRAINT fk_tab_track FOREIGN KEY (track_source, track_id) REFERENCES track (source, track_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_tab_instrument FOREIGN KEY (instrument_id) REFERENCES instrument (instrument_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tab_created FOREIGN KEY (created_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_tab_updated FOREIGN KEY (updated_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_tab_data CHECK (JSON_VALID(data)),
    CONSTRAINT ck_tab_links CHECK (JSON_VALID(links)),
    CONSTRAINT ck_tab_capo CHECK (capo BETWEEN 0 AND 12),
    CONSTRAINT ck_tab_first_bar CHECK (first_bar BETWEEN 1 AND 999),
    CONSTRAINT ck_tab_bpm CHECK (bpm BETWEEN 20 AND 400),
    CONSTRAINT ck_tab_beats CHECK (beats_per_bar BETWEEN 1 AND 16),
    CONSTRAINT ck_tab_steps CHECK (steps_per_beat IN (1, 2, 3, 4, 6, 8))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  song_tab_revision — TAB 譜の編集履歴（作成 / 保存 / 削除 / 復元）。tab.php の「履歴」で見て、戻せる
--    サイト全体の変更履歴（lib/history.php）とは別物。TAB 譜は1つが大きな JSON なので、
--    「その時点の行まるごと」を snapshot に入れておいて、そこへ戻す（差分は持たない）
--    snapshot … 作成・保存・復元は「その操作のあとの状態」、削除は「消す直前の状態」。data は配列にして入れる
--    tab_id に外部キーを張らないのは意図的: song_tab の行が消えても履歴を残して、削除した TAB を復元できるようにするため
--      （復元のときは同じ tab_id で入れ直す）
--    同じ人の10分以内の連続した「保存」は1行にまとめる。1つの TAB につき新しい200件だけ残す（lib/tabs.php の tab_record_revision）
-- ---------------------------------------------------------------------
CREATE TABLE song_tab_revision (
    rev_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tab_id     INT UNSIGNED NOT NULL,
    song_key   VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    part       VARCHAR(20)  NOT NULL,
    action     ENUM('create', 'save', 'delete', 'restore') NOT NULL,
    snapshot   MEDIUMTEXT   NOT NULL,
    user_id    INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rev_id),
    KEY idx_rev_tab (tab_id, rev_id),
    KEY idx_rev_key (song_key, rev_id),
    CONSTRAINT fk_rev_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_rev_snapshot CHECK (JSON_VALID(snapshot))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  song_tab_practice — 練習用マーカー（自分だけに見える。人ごと・TAB ごとに1行）
--    data … {"m": [{"t": ミリ秒, "e": ミリ秒 or null, "l": "ラベル（20文字まで）"}], "ab": [A, B] or null, "bpm": 練習BPM or null}
--    編集履歴（song_tab_revision）には入れない。JS が止まってから約1秒で自動保存する
--    tab_id に外部キーを張らないのは意図的: 履歴と同じ理由で、TAB を削除して復元しても（同じ tab_id で戻る）マーカーが残るように
--      （TAB を消したまま復元しない場合は、行が残り続けるだけで害は無い）
-- ---------------------------------------------------------------------
CREATE TABLE song_tab_practice (
    tab_id     INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    data       MEDIUMTEXT   NOT NULL,
    updated_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (tab_id, user_id),
    CONSTRAINT fk_practice_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_practice_data CHECK (JSON_VALID(data))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- ---------------------------------------------------------------------
--  song_meta — 曲ごとのメモ（全パート共通。曲キー song_key で結び付く。最初に保存したときに作る）
--    song_tab_image / song_meta_image — TAB・曲に添付した画像（耳コピのメモ、手書きの譜面など。lib/attach_image.php の kind）
--      song_tab_image の tab_id に外部キーを張らないのは意図的: TAB を削除して復元しても（同じ tab_id で戻る）画像が残るように
--      （履歴・マーカーと同じ理由。TAB を消したまま復元しなければ、画像のファイルと行が残り続けるだけ）
-- ---------------------------------------------------------------------
CREATE TABLE song_meta (
    meta_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    song_key   VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    part       VARCHAR(20)  NOT NULL DEFAULT '',    -- パート名（Gt1 など）。'' = 曲全体。説明は migrations/076_song_part.sql
    memo       MEDIUMTEXT   NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (meta_id),
    UNIQUE KEY uq_meta_key (song_key, part),
    CONSTRAINT fk_meta_updated FOREIGN KEY (updated_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 曲ごとの TAB 譜・コード譜のリンク（U-FRET・楽器.me・Songsterr・YouTube など。説明は migrations/075_song_link.sql）
-- パートのカード（自作 TAB がまだ無いパート。説明は migrations/076_song_part.sql）
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

CREATE TABLE song_link (
    link_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    song_key   VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    part       VARCHAR(20)  NOT NULL DEFAULT '',    -- パート名（Gt1 など）。'' = 曲全体
    site       VARCHAR(20)  NOT NULL,
    url        VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    label      VARCHAR(100) NULL,
    title      VARCHAR(100) NOT NULL,
    artist_name VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (link_id),
    UNIQUE KEY uq_link_url (song_key, part, url),
    CONSTRAINT fk_link_created FOREIGN KEY (created_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- YouTube リンクの練習プレイヤーの設定（自分だけ。説明は migrations/075_song_link.sql）
CREATE TABLE song_link_practice (
    link_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    data    JSON         NOT NULL,
    practicing TINYINT(1) NOT NULL DEFAULT 1,          -- 1 = 練習中リストに出す / 0 = リストから外した（設定は残す。migrations/078）
    PRIMARY KEY (link_id, user_id),
    CONSTRAINT fk_linkprac_link FOREIGN KEY (link_id) REFERENCES song_link (link_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_linkprac_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- YouTube 動画のチャプター（概要欄のタイムスタンプ）の控え。動画（リンク）ごとに1行・全員共通。説明は migrations/079_link_chapter.sql
CREATE TABLE song_link_chapter (
    link_id    INT UNSIGNED NOT NULL,
    data       JSON         NOT NULL,                  -- [{"t": ミリ秒, "l": ラベル}]。チャプターが無い動画は []
    fetched_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (link_id),
    CONSTRAINT fk_linkchap_link FOREIGN KEY (link_id) REFERENCES song_link (link_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- YouTube 動画の共通マーカー（曲構成のラベル付き。動画ごとに1行・全員共通。説明は migrations/080_link_shared_mark.sql）
CREATE TABLE song_link_shared_mark (
    link_id    INT UNSIGNED NOT NULL,
    data       JSON         NOT NULL,                  -- [{"t": ミリ秒, "l": ラベル}]
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (link_id),
    CONSTRAINT fk_linkshared_link FOREIGN KEY (link_id) REFERENCES song_link (link_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 練習プレイヤーのピン留め（人ごと・曲ごと。説明は migrations/077_practice_pin.sql）
CREATE TABLE practice_pin (
    user_id     INT UNSIGNED NOT NULL,
    song_key    VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    title       VARCHAR(100) NOT NULL,
    artist_name VARCHAR(255) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, song_key),
    CONSTRAINT fk_pracpin_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE song_tab_image (
    image_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tab_id     INT UNSIGNED NOT NULL,
    file_name  VARCHAR(40)  NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (image_id),
    KEY idx_tabimg_tab (tab_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE song_meta_image (
    image_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    meta_id    INT UNSIGNED NOT NULL,
    file_name  VARCHAR(40)  NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (image_id),
    KEY idx_metaimg_meta (meta_id, sort_order),
    CONSTRAINT fk_metaimg_meta FOREIGN KEY (meta_id) REFERENCES song_meta (meta_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- ---------------------------------------------------------------------
--  song_tab_ai_usage — AI で TAB を読み取る回数（ユーザーごと・日ごと。1日の上限は lib/tab_ai.php の TAB_AI_DAILY_LIMIT）
--    AI は1回ごとにお金がかかるので、使いすぎを止めるための小さな表。7日より古い行は呼ぶときに掃除する
-- ---------------------------------------------------------------------
CREATE TABLE song_tab_ai_usage (
    user_id INT UNSIGNED      NOT NULL,
    day     DATE              NOT NULL,
    n       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, day),
    CONSTRAINT fk_aiusage_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- ---------------------------------------------------------------------
--  song_catalog — 曲データベース（songs.php。管理人だけ）に「手動で登録した曲」
--    セットリストに出てこない曲（これから練習する曲など）にも TAB 譜を付けられるようにする入口。
--    セットリストの曲（song）は自動で一覧に載るので、ここに入れるのは手動で足した曲だけ。
--    song_key は song_tab と同じ形（紐付けた曲は "spotify:ID" / "itunes:ID"、紐付けなしは "t:a<artist_id>|<曲名のキー>"）。
--      紐付けなしの手動登録は、アーティストマスタ（artist）にいるアーティストを必ず選ぶ。
--      自由入力のアーティストを許すと "t:b…" とは別の形のキーが要り、表記ゆれで同じ曲が別のキーになってしまうため
--    UNIQUE (song_key): 同じ曲を2回登録できない
--    track_source / track_id … 紐付けた曲のときだけ入る。曲（track）が消えても行は残る（ON DELETE SET NULL）
-- ---------------------------------------------------------------------
CREATE TABLE song_catalog (
    catalog_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    song_key     VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    title        VARCHAR(100) NOT NULL,
    artist_name  VARCHAR(255) NULL,
    artist_id    INT UNSIGNED NULL,
    track_source VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
    track_id     VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (catalog_id),
    UNIQUE KEY uq_catalog_key (song_key),
    KEY idx_catalog_artist (artist_id),
    KEY idx_catalog_track (track_source, track_id),
    CONSTRAINT fk_catalog_artist FOREIGN KEY (artist_id) REFERENCES artist (artist_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_catalog_track FOREIGN KEY (track_source, track_id) REFERENCES track (source, track_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_catalog_created FOREIGN KEY (created_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  song_work_primary — 楽曲データベースの版違い（アルバム違い）のうち、管理人が選んだメイン（migrations/070）
--    行が無い曲は自動（lib/tracks.php の song_version_rank）。song_key は song / song_catalog / song_tab のどれかの曲キー
-- ---------------------------------------------------------------------
CREATE TABLE song_work_primary (
    work_key   VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    song_key   VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (work_key),
    CONSTRAINT fk_swp_updated FOREIGN KEY (updated_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
