-- =====================================================================
--  artist_alias — アーティストの別名（Oasis ⇔ オアシス など）
--  009_sync_account_name.sql まで流した DB（本番も含む）に、1回だけ追加で流す。
--
--  マイアルバム（member_favorite_album）の artist_name は Spotify / iTunes の表記そのまま。
--  Apple はカタカナで登録していることがある（"オアシス"）ので、文字列の比較だけでは
--  アーティストページ（artist.name = "Oasis"）と結び付けられない。
--  → 管理者が artist.php で「オアシス は Oasis の別名」と登録しておく。
--
--  主キー name: 1つの別名は1アーティストだけを指す（"オアシス" が2組のアーティストに付かない）
--  外部キー artist_id → artist: アーティストが消えたら別名も一緒に消える（統合のときは
--    artist.php が先に統合先へ付け替えるので消えない）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE artist_alias (
    name      VARCHAR(255) NOT NULL,   -- 別名。マイアルバムの artist_name と同じ長さ
    artist_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (name),
    KEY idx_alias_artist (artist_id),
    CONSTRAINT fk_alias_artist FOREIGN KEY (artist_id) REFERENCES artist (artist_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
