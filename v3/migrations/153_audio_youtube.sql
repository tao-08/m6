-- =====================================================================
--  153_audio_youtube.sql — 音源のファイルに「YouTube」の区分（その動画の音声）を付けられるようにする
-- =====================================================================
--  152_audio_chapter_end.sql まで流した DB（本番も含む）に、追加で流す。
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  video_id … バンドの YouTube（band.youtube_url・band_video）のどの動画の音声か。NULL = YouTube ではない
--    YouTube の区分のファイルは MIX ではない（band_audio は MIX の印から外す。band_drive_file は is_mix = 0）
--    動画と同じ尺なので、シャッフル再生（api_shuffle.php）は動画のチャプター（band_audio_chapter の 'y:動画ID'）で区切ってこのファイルを流す
--    バンドのリンクから外れた動画の ID が残ったときは、区分なしとして扱う
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE band_audio
    ADD COLUMN video_id CHAR(11) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER title;

ALTER TABLE band_drive_file
    ADD COLUMN video_id CHAR(11) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER is_mix;
