-- =====================================================================
--  アプリ通知（プッシュ通知）の種類ごとのオン / オフ（account.php の「アプリ通知」）
--  053_push.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  push_board     … 掲示板（アプリ内の通知設定で通知される書き込み）をアプリ通知にするか
--  push_important … 重要なお知らせをアプリ通知にするか
--  push_notice    … その他のお知らせ（宛先つきのお知らせを含む）をアプリ通知にするか
--  どれも初期値は OFF（動作確認が終わるまで、全員に飛ばない。各自が account.php で ON にする）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE user_account
    ADD COLUMN push_board     BOOLEAN NOT NULL DEFAULT FALSE AFTER count_upcoming,
    ADD COLUMN push_important BOOLEAN NOT NULL DEFAULT FALSE AFTER push_board,
    ADD COLUMN push_notice    BOOLEAN NOT NULL DEFAULT FALSE AFTER push_important;
