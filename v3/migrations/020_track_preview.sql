-- =====================================================================
--  セットリストの曲の「30秒試聴」の音源 URL を覚えておく（band.php の ▶ ボタン。api_track_preview.php）
--  019_login_attempt.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  試聴の音源は iTunes（Apple）のものだけ。Spotify の API は試聴の URL を返さなくなったので、
--  Spotify で紐付けた曲は iTunes で同じ曲を探して、その試聴を使う。
--  preview_url が NULL = 探したけど無かった（30日たったら探し直す。track_link_cache と同じ）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE track_preview (
    source      VARCHAR(10)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    track_id    VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    preview_url VARCHAR(500) NULL,                                           -- 試聴の音源（https://*.apple.com の m4a）。NULL = 無かった
    checked_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (source, track_id),
    CONSTRAINT fk_tp_track FOREIGN KEY (source, track_id) REFERENCES track (source, track_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
