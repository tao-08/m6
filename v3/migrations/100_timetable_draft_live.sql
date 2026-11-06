-- =====================================================================
--  100_timetable_draft_live.sql — タイムテーブル作成: 登録済みの予定ライブから下書きを作れるようにする
-- =====================================================================
--  下書き（timetable_draft）に「どのライブから作ったか」を持たせる。
--    live_id が入っている下書き … 確定すると、取り込みを通さずそのライブへ直接反映する（lib/tt_live.php）
--    live_id が NULL の下書き   … 今までどおり名簿ファイルから作ったもの（確定すると新しいライブとして登録）
--  published_live_id（確定済みの印）とは別の列。作った時点で live_id は入っていて、確定すると published_live_id も入る。
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（流す前だとタイムテーブル作成の一覧がエラーになる）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE timetable_draft
    ADD COLUMN live_id INT UNSIGNED NULL AFTER published_live_id,
    -- ライブが削除されたら、下書きは残して「元のライブ無し」(NULL) にする（下書きまで道連れに消さない）
    ADD CONSTRAINT fk_ttd_live FOREIGN KEY (live_id) REFERENCES live (live_id)
        ON UPDATE CASCADE ON DELETE SET NULL;
