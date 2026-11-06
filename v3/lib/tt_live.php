<?php
/**
 * =====================================================================
 *  tt_live.php — タイムテーブル作成: 登録済みの「予定のライブ」から下書きを作り、確定したそのライブへ反映する
 * =====================================================================
 *  名簿ファイルから作る今までの流れ（tt_builder.php の upload → publish → import.php）とは別に、
 *  すでに DB にある予定のライブ（バンド・メンバーが登録済み）を材料にする。
 *
 *    ① 作成   tt_state_from_live() … DB のバンド・メンバー・休憩から下書き（state）を作る。
 *             下書きに state['live'] を付けて「どのライブのどのバンドか」を覚えておく:
 *               state['live'] = ['id' => live_id, 'days' => [live_day_id, ...（下書きの1日目, 2日目…の順）],
 *                                'bands' => ['b0' => band_id, ...]]
 *    ② 編集   今までと同じ画面。日程希望から日をまたいで並べ替えられる（日数だけはライブの日程の数で固定）
 *    ③ 反映   tt_live_apply() … 取り込み（import.php）を通さず、そのライブの出演順・時間・休憩を直接書き換える。
 *             バンドが別の日へ動いていれば日程も付け替える。変更履歴に変更前を残すので、管理者は元に戻せる。
 *
 *  ■ 設計メモ（なぜ分けたか）
 *    「DB から材料を集める」「材料から state を作る」「state から書き込む内容を作る」は別の関数にした。
 *    真ん中と最後は DB に触らない（＝同じ入力なら必ず同じ結果になる「純粋関数」）ので、
 *    tests/run.php で DB なしでテストできる。DB に触る部分は薄く保つ、というのは実務でもよくある分け方。
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/tt_builder.php';

/** 'HH:MM:SS'（TIME 型の戻り値）→ 'HH:MM'。無ければ null */
function tt_hm(?string $time): ?string
{
    return $time !== null && $time !== '' ? substr($time, 0, 5) : null;
}

/** 2つの時刻の間の分数。どちらかが無い・終了が開始以前・10時間超えなら null（持ち時間として使えない） */
function tt_diff_min(?string $start, ?string $end): ?int
{
    $a = tt_hm($start);
    $b = tt_hm($end);
    if ($a === null || $b === null) {
        return null;
    }
    [$ah, $am] = array_map('intval', explode(':', $a));
    [$bh, $bm] = array_map('intval', explode(':', $b));
    $min = ($bh * 60 + $bm) - ($ah * 60 + $am);
    return $min > 0 && $min <= 600 ? $min : null;
}

/* =====================================================================
 *  ① 作成
 * ===================================================================== */

/**
 * 下書きを作れる「予定のライブ」の一覧。条件:
 *   ・最終日が今日以降          ・バンドが1組以上（名簿が空のライブは出さない）
 *   ・日程が1〜3日（下書きは3日まで）  ・すでに下書きがあるライブは除く（一覧の下書きから開ける）
 * @return array<int, array{live_id:int, name:string, first_day:?string, day_cnt:int, band_cnt:int}>
 */
function tt_live_candidates(PDO $pdo): array
{
    // SQL は集計だけ（GROUP BY + 集計関数）。絞り込みと並べ替えは PHP でやる（studio.php と同じ理由: 環境差で落ちにくい）
    //   JOIN live_day の後に LEFT JOIN band すると日程の行がバンド数だけ増えるので、日程の数は COUNT(DISTINCT) で数える
    $rows = $pdo->query('SELECT l.live_id, l.name, MIN(d.held_on) AS first_day, MAX(d.held_on) AS last_day,
            COUNT(DISTINCT d.live_day_id) AS day_cnt, COUNT(b.band_id) AS band_cnt
        FROM live l
        JOIN live_day d ON d.live_id = l.live_id
        LEFT JOIN band b ON b.live_day_id = d.live_day_id
        GROUP BY l.live_id, l.name')->fetchAll();
    $done = tt_live_draft_ids($pdo);
    $today = date('Y-m-d'); // 日付 'YYYY-MM-DD' は文字列のまま比べて大小が合う
    $list = array_filter($rows, static fn($l) => $l['last_day'] !== null && $l['last_day'] >= $today
        && (int)$l['band_cnt'] >= 1 && (int)$l['day_cnt'] >= 1 && (int)$l['day_cnt'] <= TT_MAX_DAYS
        && !isset($done[(int)$l['live_id']]));
    usort($list, static fn($a, $b) => [(string)$a['first_day'], (int)$a['live_id']] <=> [(string)$b['first_day'], (int)$b['live_id']]);
    return array_values($list);
}

/** 予定のライブから作った下書き: live_id => draft_id（1ライブに1つ。複数あれば新しい方） */
function tt_live_draft_ids(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT live_id, MAX(draft_id) AS draft_id FROM timetable_draft WHERE live_id IS NOT NULL GROUP BY live_id') as $r) {
        $out[(int)$r['live_id']] = (int)$r['draft_id'];
    }
    return $out;
}

/**
 * ライブ（live_id）の今の登録内容から、下書きの材料を作る。ライブが無い・日程が0日か4日以上なら null。
 * @return array{title:string, state:array, source:array}|null  source = 名簿の形に直したもの（timetable_draft.source に入れる）
 */
function tt_state_from_live(PDO $pdo, int $liveId): ?array
{
    $st = $pdo->prepare('SELECT name FROM live WHERE live_id = ?');
    $st->execute([$liveId]);
    $name = $st->fetchColumn();
    if ($name === false) {
        return null;
    }
    // 日程は開催日の順（live.php と同じ並び）。下書きの1日目・2日目…はこの順に対応させる
    $st = $pdo->prepare('SELECT live_day_id FROM live_day WHERE live_id = ? ORDER BY held_on IS NULL, held_on, live_day_id');
    $st->execute([$liveId]);
    $dayIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$dayIds || count($dayIds) > TT_MAX_DAYS) {
        return null;
    }
    // IN (?, ?, ...) の ? の数は日程の数だけ。値は必ず ? に渡す（SQL に直接つなげない = SQL インジェクション対策）
    $in = implode(',', array_fill(0, count($dayIds), '?'));

    $st = $pdo->prepare("SELECT band_id, live_day_id, name, play_order, song_count, start_time, end_time
        FROM band WHERE live_day_id IN ($in) ORDER BY live_day_id, play_order");
    $st->execute($dayIds);
    $bands = $st->fetchAll();

    // メンバー（band_member は 人 × 楽器 の行なので、同じ人が2行になりうる。まとめるのは tt_live_state_from_rows）
    $st = $pdo->prepare("SELECT bm.band_id, m.name, i.short_name
        FROM band_member bm
        JOIN band b ON b.band_id = bm.band_id
        JOIN member m ON m.member_id = bm.member_id
        JOIN instrument i ON i.instrument_id = bm.instrument_id
        WHERE b.live_day_id IN ($in) ORDER BY i.sort_order, m.name");
    $st->execute($dayIds);
    $members = $st->fetchAll();

    $st = $pdo->prepare("SELECT live_day_id, after_order, seq, name, start_time, end_time
        FROM live_break WHERE live_day_id IN ($in) ORDER BY live_day_id, after_order, seq");
    $st->execute($dayIds);
    $breaks = $st->fetchAll();

    return tt_live_state_from_rows($liveId, (string)$name, $dayIds, $bands, $members, $breaks);
}

/**
 * DB から集めた行 → 下書き（state）。DB には触らない（テストしやすい）。
 *   バンドは今の出演順のまま、休憩は今の位置のまま、持ち時間・集合時間は今の開始・終了時刻から逆算して入れる。
 *   （反映すると休憩を入れ直すので、今ある休憩・時間を下書きに引き継いでおかないと消えてしまうため）
 *
 * @param int[]   $dayIds  下書きの1日目, 2日目…に対応する live_day_id
 * @param array[] $bands   band_id, live_day_id, name, play_order, song_count, start_time, end_time（日程・出演順の順）
 * @param array[] $members band_id, name, short_name
 * @param array[] $breaks  live_day_id, after_order, seq, name, start_time, end_time（日程・位置の順）
 */
function tt_live_state_from_rows(int $liveId, string $name, array $dayIds, array $bands, array $members, array $breaks): array
{
    // メンバー: band_id => [名前 => [楽器の略称, ...]]。同じ人が Vo と Gt の2行でも1人にして、パートは「Vo/Gt」とつなぐ
    $people = [];
    foreach ($members as $m) {
        $people[(int)$m['band_id']][(string)$m['name']][] = (string)$m['short_name'];
    }
    $bandsOfDay = [];
    foreach ($bands as $b) {
        $bandsOfDay[(int)$b['live_day_id']][] = $b;
    }
    $breaksOfDay = [];
    foreach ($breaks as $k) {
        $breaksOfDay[(int)$k['live_day_id']][] = $k;
    }

    // 名簿の形（tt_new_state が受け取る形）に直す。日程ごとに1つの名簿 = 1日目の名簿, 2日目の名簿…
    $rosters = [];
    $idOf = [];  // band_id => 下書きでのバンド id（'b0', 'b1', …）
    $map = [];   // 下書きでのバンド id => band_id
    $n = 0;      // tt_new_state は名簿の順・バンドの順に b0, b1, … と振るので、同じ順で数える
    foreach ($dayIds as $dayId) {
        $list = [];
        foreach ($bandsOfDay[$dayId] ?? [] as $b) {
            $crew = []; // このバンドのメンバー（引数の $members と名前がかぶらないように別名）
            foreach ($people[(int)$b['band_id']] ?? [] as $personName => $parts) {
                $crew[] = ['name' => (string)$personName, 'part' => implode('/', array_unique($parts))];
            }
            $list[] = ['band_name' => (string)$b['name'],
                'song_count' => $b['song_count'] !== null ? (int)$b['song_count'] : null,
                'member_count' => $crew ? count($crew) : null, // メンバー未登録は空欄（0人と書かない）
                'members' => $crew, 'key_note' => ''];
            $idOf[(int)$b['band_id']] = 'b' . $n;
            $map['b' . $n] = (int)$b['band_id'];
            $n++;
        }
        $rosters[] = ['file' => '登録済みのバンド', 'bands' => $list];
    }

    $state = tt_new_state($name, $rosters, count($dayIds));
    $state['live'] = ['id' => $liveId, 'days' => array_values($dayIds), 'bands' => $map];

    // 各日の行を作り直す: 集合 → バンドと休憩（今の並び）。tt_new_state が作った行は「集合」だけ残す
    foreach (array_values($dayIds) as $di => $dayId) {
        $rows = [$state['days'][$di]['rows'][0]];
        // 休憩の位置: after_order = 「◯番のバンドの後」。◯番より後ろのバンドの前に置く
        $items = [];
        $pending = $breaksOfDay[$dayId] ?? [];
        foreach ($bandsOfDay[$dayId] ?? [] as $b) {
            while ($pending && (int)$pending[0]['after_order'] < (int)$b['play_order']) {
                $items[] = ['break', array_shift($pending)];
            }
            $items[] = ['band', $b];
        }
        foreach ($pending as $k) { // 最後のバンドより後ろの休憩（撤収など）
            $items[] = ['break', $k];
        }
        foreach ($items as $i => [$kind, $r]) {
            $min = tt_diff_min($r['start_time'], $r['end_time']);
            $rows[] = $kind === 'band'
                ? ['id' => $idOf[(int)$r['band_id']], 'min' => $min]
                // 休憩の行 id は「x」で始まる決まり（TT_ROW_ID）。日と番号で一意にする
                : ['id' => "xl{$di}k{$i}", 't' => 'break', 'x' => mb_substr((string)$r['name'], 0, 50), 'min' => $min];
        }
        $state['days'][$di]['rows'] = $rows;
        // 集合時間 = 最初の行の開始の30分前（先頭の「集合」行が30分なので、それで今の開始時刻に合う）
        $first = $items ? tt_hm($items[0][1]['start_time']) : null;
        $state['days'][$di]['call'] = $first !== null ? tt_add_min($first, 1440 - 30) : ''; // 24時間-30分 を足す = 30分引く（負の剰余を避ける）
    }
    return ['title' => $name, 'state' => $state, 'source' => $rosters];
}

/* =====================================================================
 *  ③ 反映
 * ===================================================================== */

/**
 * 下書きの並びを「DB に書き込む内容」にする。DB には触らない（テストしやすい）。
 *   バンド … 日ごとに [band_id, 開始, 終了, 曲数]（出演順 = 並び順）
 *   休憩   … 日ごとに [after（何組の後か）, seq, 名前, 開始, 終了]
 *   placed … 下書きに入っているバンドの band_id（これ以外の既存バンドは触らず最後へ回す）
 * 非表示のバンドと、下書きに対応するバンドが無い行は入れない。休憩は「集合」と名前の無い行を除く（tt_import_plan と同じ）。
 * 終了が開始より前（日付またぎ）は CHECK 制約に引っかかるので終了を捨てる（collect_slot_rows と同じ）。
 */
function tt_live_arrangement(array $state): array
{
    $map = $state['live']['bands'] ?? [];
    $validEnd = static fn(?string $s, ?string $e): ?string => ($s !== null && $e !== null && $e > $s) ? $e : null;
    $days = [];
    $placed = [];
    for ($d = 0; $d < $state['dayCount']; $d++) {
        $bands = [];
        $breaks = [];
        $seq = [];
        foreach (tt_day_rows($state, $d) as $x) {
            $b = $x['band'];
            if ($b !== null) {
                $id = $x['row']['id'];
                if (!empty($b['off']) || !isset($map[$id])) {
                    continue;
                }
                $bands[] = ['band_id' => (int)$map[$id], 'start' => $x['start'], 'end' => $validEnd($x['start'], $x['end']),
                    'songs' => $b['songs'] ?? null];
                $placed[] = (int)$map[$id];
                continue;
            }
            $label = trim((string)$x['row']['x']);
            if (in_array($label, ['', '集合'], true)) {
                continue;
            }
            $after = count($bands); // ここまでに置いたバンドの数 = 「◯組目の後」
            $seq[$after] ??= 0;
            $breaks[] = ['after' => $after, 'seq' => $seq[$after]++, 'name' => mb_substr($label, 0, 50),
                'start' => $x['start'], 'end' => $validEnd($x['start'], $x['end'])];
        }
        $days[] = ['bands' => $bands, 'breaks' => $breaks];
    }
    return ['days' => $days, 'placed' => $placed];
}

/**
 * 下書きをそのライブへ反映する（1つのトランザクション）。出演順・時間・休憩を書き換え、バンドが別の日へ動いていれば日程も付け替える。
 *   触らないもの … バンド名・メンバー・セトリ・曲数の空欄（空欄は今の値を残す）
 *   下書きに無い既存バンド（下書きを作った後に追加された・非表示にした）… 消さずに、今の日程の最後へ
 * 変更前は change_capture_live で「ライブ全体」を履歴に残す（日をまたいで動くので、全日程・全バンドが対象）。
 *
 * @return int 反映先の live_id
 * @throws RuntimeException 反映できないとき（メッセージはそのまま画面に出す）
 */
function tt_live_apply(PDO $pdo, array $draft): int
{
    $liveId = (int)($draft['live_id'] ?? 0);
    $pdo->beginTransaction();
    try {
        // 反映の直前の下書きを、行ロック（FOR UPDATE）つきで読み直す。同時に編集された変更を取りこぼさない
        $draft = tt_load($pdo, (int)$draft['draft_id'], true);
        $state = $draft['state'] ?? [];
        $link = $state['live'] ?? null;
        if ($draft === null || $liveId <= 0 || !is_array($link) || (int)($link['id'] ?? 0) !== $liveId) {
            throw new RuntimeException('この下書きは予定のライブから作ったものではありません');
        }

        $st = $pdo->prepare('SELECT name FROM live WHERE live_id = ? FOR UPDATE');
        $st->execute([$liveId]);
        $liveName = $st->fetchColumn();
        if ($liveName === false) {
            throw new RuntimeException('反映先のライブが見つかりません（削除された可能性があります）');
        }

        // 日程が下書きを作った後に増減していないか。下書きの日数 = ライブの日程の数、の前提で反映する
        $dayIds = array_map('intval', $link['days'] ?? []);
        $st = $pdo->prepare('SELECT live_day_id FROM live_day WHERE live_id = ?');
        $st->execute([$liveId]);
        $nowDays = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        sort($nowDays);
        $want = $dayIds;
        sort($want);
        if ($want !== $nowDays || count($dayIds) !== (int)$state['dayCount']) {
            throw new RuntimeException('ライブの日程が下書きを作った後に変わりました。下書きを作り直してください');
        }
        $in = implode(',', array_fill(0, count($dayIds), '?'));

        // 今のバンド: band_id => 今の日程。下書きが指すバンドは、必ずこの中にあるものだけ（他のライブのバンドを書き換えない）
        $st = $pdo->prepare("SELECT band_id, live_day_id FROM band WHERE live_day_id IN ($in) ORDER BY live_day_id, play_order FOR UPDATE");
        $st->execute($dayIds);
        $existing = [];
        foreach ($st->fetchAll() as $r) {
            $existing[(int)$r['band_id']] = (int)$r['live_day_id'];
        }

        $arr = tt_live_arrangement($state);
        foreach ($arr['placed'] as $bandId) {
            if (!isset($existing[$bandId])) {
                throw new RuntimeException('下書きのバンドがライブに見つかりません（削除された可能性があります）。下書きを作り直してください');
            }
        }
        // 下書きに入っていない既存バンド: 今の日程ごとに、今の出演順のまま（$existing は出演順で取ってある）
        $rest = [];
        foreach ($existing as $bandId => $dayId) {
            if (!in_array($bandId, $arr['placed'], true)) {
                $rest[$dayId][] = $bandId;
            }
        }

        // ---- 履歴: 変更前のライブ全体を残す ----
        $op = change_begin($pdo, 'import', "{$liveName} にタイムテーブルを反映", $liveId);
        change_capture_live($pdo, $op, $liveId);

        // ---- 出演順を振り直す ----
        // (live_day_id, play_order) は UNIQUE。いきなり 1, 2, 3… を入れると途中で「2番が2組」になりうるので、
        // いったん全部を 1000 番台へ逃がしてから入れ直す（renumber_bands と同じやり方）。日をまたいで動いても、新しい番号（1〜）は逃がした番号（1001〜）とぶつからない
        $pdo->prepare("UPDATE band SET play_order = play_order + 1000 WHERE live_day_id IN ($in)")->execute($dayIds);
        // COALESCE(?, song_count) … 曲数が空欄（NULL）なら今の値を残す
        $place = $pdo->prepare('UPDATE band SET live_day_id = ?, play_order = ?, start_time = ?, end_time = ?, song_count = COALESCE(?, song_count) WHERE band_id = ?');
        $keep = $pdo->prepare('UPDATE band SET live_day_id = ?, play_order = ? WHERE band_id = ?');
        $clearBreaks = $pdo->prepare('DELETE FROM live_break WHERE live_day_id = ?');
        $addBreak = $pdo->prepare('INSERT INTO live_break (live_day_id, after_order, seq, name, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?)');
        $ordered = $pdo->prepare('UPDATE live_day SET order_unset = 0 WHERE live_day_id = ?'); // 出演順が決まったので、トリを付けられるようにする

        foreach ($arr['days'] as $d => $day) {
            $dayId = $dayIds[$d];
            $order = 0;
            foreach ($day['bands'] as $b) {
                $place->execute([$dayId, ++$order, $b['start'], $b['end'], $b['songs'], $b['band_id']]);
            }
            foreach ($rest[$dayId] ?? [] as $bandId) {
                $keep->execute([$dayId, ++$order, $bandId]);
            }
            // 休憩は下書きの内容で入れ直す（今ある休憩は下書きを作るときに引き継いである）
            $clearBreaks->execute([$dayId]);
            foreach ($day['breaks'] as $k) {
                $addBreak->execute([$dayId, $k['after'], $k['seq'], $k['name'], $k['start'], $k['end']]);
            }
            $ordered->execute([$dayId]);
            sync_day_total_bands($pdo, $dayId); // 別の日から移ってきて、総バンド数が登録数より少なくなっていたら追いつかせる
        }

        $pdo->prepare('UPDATE timetable_draft SET published_live_id = ? WHERE draft_id = ?')->execute([$liveId, (int)$draft['draft_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $liveId;
}
