-- =====================================================================
--  090_studio_discount_kind.sql — 割引を「金額指定 / ◯円引き / ◯%引き」の3種類で持てるようにする
-- =====================================================================
--  今まで: 割引区分ごとの price = その区分の料金（金額指定のみ）
--  今回  : kind 列を足し、price の意味を kind で切り替える
--    kind 0 = 金額指定 … price = その区分の1時間（パックは1回）の料金（今までと同じ。既存の行はすべてこれ）
--    kind 1 = ◯円引き  … price = 通常料金から引く円
--    kind 2 = ◯%引き   … price = 通常料金から引く%（1〜100）
--  画面の表示・シミュレーションに使う料金は、読み込むときにアプリ側で計算する（lib/studio_info.php の studio_disc_apply）
--
--  実行順: 089 → 090。1回だけ流すこと（2回目は「列が既にある」エラーになるだけで、データは壊れない）
--  ※ このSQLを本番DBに流す前に、090 対応のコードをデプロイしないこと（kind 列が無くてエラーになる）
-- =====================================================================
ALTER TABLE studio_rate_discount
    ADD COLUMN kind TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER price;
ALTER TABLE studio_pack_discount
    ADD COLUMN kind TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER price;
