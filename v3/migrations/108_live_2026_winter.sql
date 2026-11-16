-- 2026年度の今後のライブ（12月ライブ〜卒業ライブ）を登録する。本番DBで手で実行する（デプロイ対象外）
-- 何回流しても二重にならない（live は (年度, 名前)、live_day は (live, 日程名)、venue は名前が一意）。
-- 会場は名前が同じものがあればそれを使い、無ければ作る

INSERT IGNORE INTO venue (name) VALUES ('下北沢Waver'), ('大塚meets'), ('ゆうげん荘');

INSERT IGNORE INTO live (fiscal_year, name) VALUES
  (2026, '12月ライブ'), (2026, '合同ライブ'), (2026, '冬合宿ライブ'), (2026, '2月ライブ'), (2026, '卒業ライブ');

INSERT IGNORE INTO live_day (live_id, label, held_on, venue_id)
SELECT l.live_id, d.label, d.held_on, (SELECT v.venue_id FROM venue v WHERE v.name = d.venue)
FROM (
  SELECT '12月ライブ' AS live, '1日目' AS label, '2026-12-19' AS held_on, '下北沢Waver' AS venue
  UNION ALL SELECT '12月ライブ', '2日目', '2026-12-20', '下北沢Waver'
  UNION ALL SELECT '合同ライブ', '1日目', '2027-01-15', '大塚meets'
  UNION ALL SELECT '冬合宿ライブ', '1日目', '2027-02-05', 'ゆうげん荘'
  UNION ALL SELECT '冬合宿ライブ', '2日目', '2027-02-06', 'ゆうげん荘'
  UNION ALL SELECT '2月ライブ', '1日目', '2027-02-18', '下北沢Waver'
  UNION ALL SELECT '2月ライブ', '2日目', '2027-02-19', '下北沢Waver'
  UNION ALL SELECT '卒業ライブ', '1日目', '2027-03-06', '下北沢Waver'
  UNION ALL SELECT '卒業ライブ', '2日目', '2027-03-07', '下北沢Waver'
) d
JOIN live l ON l.fiscal_year = 2026 AND l.name = d.live;
