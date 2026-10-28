<?php
/**
 * =====================================================================
 *  lib/import/attach.php — 登録済みの日程に、名簿・タイムテーブルをあとから追加する
 * =====================================================================
 *  ライブ詳細（live.php）の「名簿を追加」「タイムテーブルを追加」→ import?day=日程ID で始まる。
 *  画面（アップロード → プレビュー → 登録）は新規追加と同じ import.php を使い回し、
 *  セッションの計画（$plan）に 'attach' があるときだけ、ここの関数で動きを変える。
 *
 *  ■ 名簿だけ追加（タイムテーブルのファイルが無い）
 *    DB にある「メンバーが0人のバンド」を、タイムテーブルの枠の代わりに並べる（＝疑似タイムテーブル）。
 *    → 新規追加と同じしくみ（finish_import_plan）で、名簿のバンドとバンド名で自動の対応付けができる。
 *    メンバー登録済みのバンドには触らない（上書き事故を防ぐ）。
 *
 *  ■ タイムテーブルを追加（名簿は一緒にあっても無くてもいい）
 *    タイムテーブルの各枠を、DB の既存バンドとバンド名で対応付ける（プレビューで直せる）。
 *      対応する既存バンド  … 出演順・時間を書き換える（名前・メンバー・セトリはそのまま）
 *      対応なし            … 新しいバンドとして追加
 *      タイムテーブルに無い既存バンド … 消さずに、最後に回す
 *    休憩はタイムテーブルの内容で入れ直す。
 *
 *  どちらも「変更履歴」に変更前の日程を残すので、管理者は元に戻せる（lib/history.php）。
 * =====================================================================
 */
declare(strict_types=1);

/**
 * 追加先の日程（とライブ）を取ってくる。無ければ null。
 *   フォームや URL の日程 ID は書き換えられる可能性があるので、必ず DB で存在を確かめてから使う。
 */
function attach_target(PDO $pdo, int $dayId): ?array
{
    $st = $pdo->prepare('SELECT d.live_day_id, d.label, d.held_on, l.live_id, l.fiscal_year, l.name AS live_name
        FROM live_day d JOIN live l ON l.live_id = d.live_id WHERE d.live_day_id = ?');
    $st->execute([$dayId]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/**
 * 日程の既存バンドを出演順で取る。has_members = メンバーが1人以上登録されているか（1 / 0）。
 *   EXISTS (サブクエリ) … 条件に合う行が1つでもあれば 1。COUNT(*) より速い（1行見つかった時点で止まる）
 */
function attach_day_bands(PDO $pdo, int $dayId): array
{
    $st = $pdo->prepare('SELECT b.band_id, b.name, b.play_order, b.start_time, b.end_time, b.song_count,
            EXISTS (SELECT 1 FROM band_member bm WHERE bm.band_id = b.band_id) AS has_members
        FROM band b WHERE b.live_day_id = ? ORDER BY b.play_order');
    $st->execute([$dayId]);
    return $st->fetchAll();
}

/** プレビューの見出しに出す「2025年度 文化祭ライブ 1日目」 */
function attach_title(array $attach): string
{
    return "{$attach['fiscal_year']}年度 {$attach['live_name']} {$attach['label']}";
}

/**
 * build_import_plan() の結果を「登録済みの日程への追加」用に作り直す。
 *
 * @param array $target attach_target() の結果
 * @return array $plan（'attach' キー付き）。エラーは $plan['errors'] に入れる（新規追加と同じ出し方）
 */
function build_attach_plan(PDO $pdo, array $plan, array $target): array
{
    $plan['attach'] = $target + ['mode' => $plan['timetables'] ? 'timetable' : 'roster'];
    $bands = attach_day_bands($pdo, (int)$target['live_day_id']);

    if ($plan['attach']['mode'] === 'roster') {
        // ---------- 名簿だけ: メンバー未登録のバンドを「タイムテーブルの枠」として並べる ----------
        if (!$plan['rosters']) {
            $plan['errors'][] = '名簿かタイムテーブルのファイルを選んでください。';
            return $plan;
        }
        $slots = [];
        foreach ($bands as $b) {
            if ($b['has_members']) {
                continue; // 登録済みのバンドは対象外（名簿で上書きしない）
            }
            $slots[] = [
                'is_band'      => true,
                'band_name'    => $b['name'],
                // TIME 型は「18:30:00」で返ってくるので、画面の表記（18:30）に合わせて秒を落とす
                'start_time'   => $b['start_time'] !== null ? substr($b['start_time'], 0, 5) : null,
                'end_time'     => $b['end_time'] !== null ? substr($b['end_time'], 0, 5) : null,
                'song_count'   => $b['song_count'] !== null ? (int)$b['song_count'] : null,
                'member_count' => null,
                'key_note'     => '',
                'band_id'      => (int)$b['band_id'], // ← 登録時はこのバンドにメンバーを入れる
            ];
        }
        if (!$slots) {
            $plan['errors'][] = 'この日程には、メンバー未登録のバンドがありません。';
        }
        $plan['timetables'] = [[
            'title' => '', 'live_name' => '', 'label' => $target['label'], 'month' => null, 'day' => null,
            'venue' => '', 'slots' => $slots, 'file' => '登録済みのバンド',
        ]];
        // 疑似タイムテーブルを入れ直したので、名簿 ↔ 枠の自動対応付けをやり直す
        return finish_import_plan($plan);
    }

    // ---------- タイムテーブルあり: 1日程分だけ受け付ける ----------
    if (count($plan['timetables']) > 1) {
        $plan['errors'][] = 'タイムテーブルは1日程分（1ファイル）だけ選んでください。';
        $plan['timetables'] = array_slice($plan['timetables'], 0, 1);
    }
    // 各枠を既存バンドとバンド名で対応付ける。名簿の自動対応付け（match_roster_band）を使い回すので、形をそろえる
    $candidates = array_map(static fn($b) => [
        'band_name' => $b['name'],
        'song_count' => $b['song_count'] !== null ? (int)$b['song_count'] : null,
        'member_count' => null,
        'members' => [],
    ], $bands);
    $taken = []; // 既存バンド1つは1枠にだけ対応させる（先に見つかった枠が優先）
    foreach ($plan['timetables'][0]['slots'] as $si => &$slot) {
        $slot['band_id'] = null;
        if (!$slot['is_band']) {
            continue;
        }
        $i = match_roster_band($slot, $candidates);
        if ($i !== null && !isset($taken[$i])) {
            $taken[$i] = true;
            $slot['band_id'] = (int)$bands[$i]['band_id'];
        }
    }
    unset($slot); // foreach の参照(&)は使い終わったら必ず unset
    return $plan;
}

/**
 * 登録済みの日程への追加を DB に書き込む（1つのトランザクション）。
 *
 * @return int 追加先の live_id
 * @throws RuntimeException 入力ミス・対応付けのミス（メッセージはそのまま画面に出す）
 */
function commit_attach_plan(PDO $pdo, array $plan, array $input): int
{
    $attach = $plan['attach'];
    // セッションの日程がまだ DB にあるか（プレビュー中に誰かが消したかもしれない）
    $target = attach_target($pdo, (int)$attach['live_day_id']);
    if ($target === null) {
        throw new RuntimeException('追加先の日程が見つかりません（削除された可能性があります）');
    }
    $dayId = (int)$target['live_day_id'];
    $liveId = (int)$target['live_id'];
    $tt = $plan['timetables'][0] ?? null;
    if ($tt === null) {
        throw new RuntimeException('追加する内容がありません');
    }
    $form = $input['tt'][0] ?? [];
    $rosterChoices = roster_choices($plan);
    assert_roster_unique($plan, $input);

    // 既存バンド: band_id => 行。フォームの band_id はこの中にあるものだけ受け付ける（他の日程のバンドを書き換えさせない）
    $existing = array_column(attach_day_bands($pdo, $dayId), null, 'band_id');
    [$rows, $breakRows] = collect_slot_rows($tt, $form, $rosterChoices, '');
    $used = [];
    foreach ($rows as $r) {
        if ($r['band_id'] === null) {
            continue;
        }
        if (!isset($existing[$r['band_id']])) {
            throw new RuntimeException('対応させたバンドがこの日程にありません。プレビューを開き直してください');
        }
        if (isset($used[$r['band_id']])) {
            throw new RuntimeException("「{$existing[$r['band_id']]['name']}」を2つの枠に対応させています。1つの枠だけにしてください");
        }
        $used[$r['band_id']] = true;
    }

    $pdo->beginTransaction();
    try {
        $isTimetable = $attach['mode'] === 'timetable';
        $op = change_begin($pdo, 'import', $isTimetable ? 'タイムテーブルを追加' : '名簿を追加', $liveId);
        change_capture_day($pdo, $op, $dayId); // 変更前の日程（休憩・全バンド・メンバー）を履歴に残す
        $bandRosters = [];

        if ($isTimetable) {
            // ---- 1. 出演順を振り直す ----
            // (live_day_id, play_order) は UNIQUE なので、いきなり 1, 2, 3… を入れると「2番が2つ」になりうる。
            // いったん全部を 1000 番台に逃がしてから入れ直す（timetable_edit.php と同じやり方）
            $pdo->prepare('UPDATE band SET play_order = play_order + 1000 WHERE live_day_id = ?')->execute([$dayId]);
            $update = $pdo->prepare('UPDATE band SET play_order = ?, start_time = ?, end_time = ?, song_count = COALESCE(?, song_count) WHERE band_id = ?');
            $insert = $pdo->prepare('INSERT INTO band (live_day_id, artist_id, name, play_order, start_time, end_time, song_count, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $order = 0;
            foreach ($rows as $r) {
                $order++;
                if ($r['band_id'] !== null) {
                    // 既存バンド: 名前・メンバー・セトリはそのまま、順番と時間だけタイムテーブルに合わせる
                    // COALESCE(?, song_count): 曲数が空欄なら今の値を残す（NULL で消さない）
                    $update->execute([$order, $r['start'], $r['end'], $r['songs'], $r['band_id']]);
                    $bandId = $r['band_id'];
                    $hasMembers = (bool)$existing[$bandId]['has_members'];
                } else {
                    $insert->execute([$dayId, find_or_create_artist($pdo, $r['name']), $r['name'], $order,
                        $r['start'], $r['end'], $r['songs'], $r['note'] !== '' ? $r['note'] : null]);
                    $bandId = (int)$pdo->lastInsertId();
                    change_created($pdo, $op, 'band', $bandId); // 元に戻すと、追加したバンドを消す
                    $hasMembers = false;
                }
                // 名簿はメンバー未登録のバンドにだけ入れる
                if ($r['roster'] !== null && !$hasMembers) {
                    $bandRosters[] = [$bandId, $r['roster']];
                }
            }
            // タイムテーブルに無かった既存バンドは消さずに最後へ（元の順番のまま）
            $rest = $pdo->prepare('UPDATE band SET play_order = ? WHERE band_id = ?');
            foreach ($existing as $bandId => $b) {
                if (!isset($used[$bandId])) {
                    $rest->execute([++$order, $bandId]);
                }
            }

            // ---- 2. 休憩はタイムテーブルの内容で入れ直す ----
            $pdo->prepare('DELETE FROM live_break WHERE live_day_id = ?')->execute([$dayId]);
            insert_breaks($pdo, $dayId, $rows, $breakRows);
        } else {
            // ---- 名簿だけ: 対応付けたバンドにメンバーを入れる（順番・時間は触らない） ----
            foreach ($rows as $r) {
                if ($r['band_id'] !== null && $r['roster'] !== null && !$existing[$r['band_id']]['has_members']) {
                    $bandRosters[] = [$r['band_id'], $r['roster']];
                }
            }
        }

        register_roster_members($pdo, $plan, $input, $bandRosters);
        link_users_to_members($pdo);
        change_set_summary($pdo, $op, attach_title($attach) . ($isTimetable ? ' にタイムテーブルを追加' : ' に名簿を追加'), $liveId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $liveId;
}
