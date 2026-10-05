<?php
/**
 * =====================================================================
 *  planner.php — 取り込みの「計画づくり」と「DBへの書き込み」
 * =====================================================================
 *  取り込みは2段階に分けている。
 *
 *   ① build_import_plan()  … ファイルを読んで「こう登録するつもり」という計画（配列）を作る。
 *                             DB にはまだ何も書かない。計画はセッションに置いてプレビュー画面で見せる。
 *   ② commit_import_plan() … プレビューで人間が直した値（フォームの送信内容）を使って DB に書く。
 *
 *  なぜ分ける？ → ファイルの読み取りは100%正しくはできない。
 *  間違ったまま DB に入ると直すのが大変なので、必ず人の目で確認してから登録する。
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/parsers.php';
require_once __DIR__ . '/../repository.php';

/** 4月始まりの年度。1〜3月は前の年の年度になる（2026年1月のライブ → 2025年度） */
function academic_year(int $month, int $year): int
{
    return $month >= 4 ? $year : $year - 1;
}

/**
 * パート（'Vo' 'Gt'…）→ instrument_id の初期値。
 * instrument.short_name（'Vo' 'Gt'…）と比べる。'Other' は「その他(etc)」にする。
 */
function default_instrument_id(string $part): ?int
{
    foreach (instruments() as $ins) {
        if (strtolower($ins['short_name']) === strtolower($part === 'Other' ? 'etc' : $part)) {
            return (int)$ins['instrument_id'];
        }
    }
    return null;
}

/**
 * 「Key/その他」列で選べる楽器 = Vo / Gt / Ba / Dr 以外の楽器（キーボード、ヴァイオリン、サックス…）。
 * 名簿の Key 列には「鍵盤以外の人」もまとめて書かれることがあるので、セルごとに選べるようにする。
 */
function extra_instruments(): array
{
    return array_values(array_filter(instruments(), static fn($i) => !in_array($i['short_name'], ['Vo', 'Gt', 'Ba', 'Dr'], true)));
}

/**
 * 名前の後ろに楽器を書く書き方に対応する: 「丸野友多郎(Sax)」「村田侑斗（キーボード）」
 *   → ['丸野友多郎', サックスの instrument_id]
 * 括弧の中が楽器名でなければ、ただの名前として扱う。
 * 1つのセルに「村田侑斗、丸野友多郎(Sax)」と書けば、1人ずつ違う楽器にできる。
 */
function parse_name_instrument(string $name): array
{
    if (preg_match('/^(.+?)[(（]([^()（）]+)[)）]$/u', $name, $m)) {
        $label = mb_strtolower(trim(tt_width($m[2])));
        foreach (instruments() as $ins) {
            if ($label === mb_strtolower($ins['short_name']) || $label === mb_strtolower($ins['name'])) {
                return [$m[1], (int)$ins['instrument_id']];
            }
        }
    }
    return [$name, null];
}

/**
 * ① ファイル群 → 取り込み計画
 *
 * @param array $files [['path' => 一時ファイル, 'name' => 元のファイル名], ...]
 * @return array [
 *   'timetables' => [ 1日分ずつ（parse_timetable の結果 + file, year, date） ],
 *   'rosters'    => [ 名簿1ファイルずつ（parse_roster の結果 + file）],
 *   'errors'     => [ 読めなかったファイルのメッセージ ],
 * ]
 */
function build_import_plan(array $files): array
{
    $plan = ['timetables' => [], 'rosters' => [], 'errors' => []];

    // ---- 1. 1ファイルずつ読んで、タイムテーブルか名簿かで振り分け ----
    foreach ($files as $f) {
        try {
            $rows = read_table_file($f['path'], $f['name']);
            if (detect_table_kind($rows) === 'timetable') {
                $tt = parse_timetable($rows);
                $tt['file'] = $f['name'];
                $plan['timetables'][] = $tt;
            } else {
                $roster = parse_roster($rows);
                $roster['file'] = $f['name'];
                foreach ($roster['columns'] as &$col) {
                    $col['instrument_id'] = default_instrument_id($col['part']);
                }
                unset($col); // foreach の参照(&)は使い終わったら必ず unset（後で事故る）
                $plan['rosters'][] = $roster;
            }
        } catch (Throwable $e) {
            // 1ファイル読めなくても他のファイルは続ける
            $plan['errors'][] = $f['name'] . ': ' . $e->getMessage();
        }
    }

    // ---- 2. 日付と年度の初期値 ----
    // タイムテーブルには「年」が書かれていないので、今日に一番近い過去の年を仮で入れる
    foreach ($plan['timetables'] as &$tt) {
        $tt['date'] = '';
        $tt['year'] = academic_year((int)date('n'), (int)date('Y'));
        if ($tt['month'] && $tt['day']) {
            $y = (int)date('Y');
            // 例: 今が10月で「1月5日」と書いてあったら、たぶん今年の1月（未来の1月ではない）
            if (mktime(0, 0, 0, $tt['month'], $tt['day'], $y) > time() + 86400 * 60) {
                $y--;
            }
            if (checkdate($tt['month'], $tt['day'], $y)) {
                $tt['date'] = sprintf('%04d-%02d-%02d', $y, $tt['month'], $tt['day']);
                $tt['year'] = academic_year($tt['month'], $y);
            }
        }
        // バンドの枠は「取り込む」にチェック、休憩などは外しておく
        $order = 0;
        foreach ($tt['slots'] as &$slot) {
            $slot['include'] = $slot['is_band'];
            $slot['order'] = $slot['is_band'] ? ++$order : null;
        }
        unset($slot);
    }
    unset($tt);

    // ---- 3. 名簿のバンド ↔ タイムテーブルの枠 を自動で対応付け ----
    // 名簿が複数ファイルでも探せるように、いったん1本の配列にする（$ref で元の位置を覚えておく）
    $flat = [];
    $ref = [];
    foreach ($plan['rosters'] as $ri => $roster) {
        foreach ($roster['bands'] as $bi => $band) {
            $flat[] = $band;
            $ref[] = [$ri, $bi];
        }
    }
    foreach ($plan['rosters'] as &$roster) {
        foreach ($roster['bands'] as &$band) {
            $band['slot'] = ''; // まだどの枠にも対応していない
        }
        unset($band);
    }
    unset($roster);

    foreach ($plan['timetables'] as $ti => $tt) {
        foreach ($tt['slots'] as $si => $slot) {
            if (!$slot['is_band']) {
                continue;
            }
            $i = match_roster_band($slot, $flat);
            if ($i === null) {
                continue;
            }
            [$ri, $bi] = $ref[$i];
            // 名簿の1バンドは1枠にだけ対応させる（先に見つかった枠が優先）
            if ($plan['rosters'][$ri]['bands'][$bi]['slot'] === '') {
                $plan['rosters'][$ri]['bands'][$bi]['slot'] = "$ti:$si"; // 「何日目の何番目の枠か」
            }
        }
    }
    return $plan;
}

/**
 * 名簿のセル（「村田侑斗、丸野友多郎」など）が DB の誰と一致するかを調べる。
 * プレビューの入力欄の色分けに使う（緑=登録済み / 黄=似た人がいる / 赤=新しい人）。
 *
 * @param string[] $cells セルの文字列の配列
 * @return array 同じ順番で ['status' => 'ok'|'similar'|'new'|'', 'hint' => 説明文]
 */
function classify_cells(PDO $pdo, array $cells): array
{
    $index = load_member_index($pdo);

    // 今回の取り込みで新しく出てくる名前（DBにない名前）を集めておく
    // → 「清水啓之介」と「清水啓乃介」のように、今回の中で表記がブレているのも見つけるため
    $newKeys = [];
    foreach ($cells as $cell) {
        foreach (split_member_names((string)$cell) as $name) {
            [$name] = parse_name_instrument($name); // 「名前(Sax)」の楽器部分は外して照合
            $key = member_key($name);
            if (!isset($index[$key])) {
                $newKeys[$key] = $name;
            }
        }
    }

    $result = [];
    foreach ($cells as $cell) {
        $names = array_map(static fn($n) => parse_name_instrument($n)[0], split_member_names((string)$cell));
        if ($names === []) {
            $result[] = ['status' => '', 'hint' => ''];
            continue;
        }
        $statuses = [];
        $hints = [];
        foreach ($names as $name) {
            $key = member_key($name);
            if (isset($index[$key])) {
                $statuses[] = 'ok';
                continue;
            }
            // DB の人と似ている？
            $similar = [];
            foreach ($index as $eKey => $e) {
                if (names_look_similar($key, $eKey)) {
                    $similar[] = $e['name'];
                }
            }
            if ($similar) {
                $statuses[] = 'similar';
                $hints[] = "「{$name}」は未登録。登録済みの「" . implode('」「', array_slice($similar, 0, 3)) . '」の書き間違い？';
                continue;
            }
            // 今回の取り込みの中の別の新しい名前と似ている？
            $similarNew = [];
            foreach ($newKeys as $nKey => $nName) {
                if (names_look_similar($key, $nKey)) {
                    $similarNew[] = $nName;
                }
            }
            if ($similarNew) {
                $statuses[] = 'similar';
                $hints[] = "「{$name}」は未登録。今回の「" . implode('」「', $similarNew) . '」と表記ゆれ？';
                continue;
            }
            $statuses[] = 'new';
            $hints[] = "「{$name}」は未登録 → 新しいメンバーとして登録されます";
        }
        // 1つのセルに複数人いるときは、一番注意が必要な色にする
        $status = in_array('new', $statuses, true) ? 'new' : (in_array('similar', $statuses, true) ? 'similar' : 'ok');
        $result[] = ['status' => $status, 'hint' => implode("\n", $hints)];
    }
    return $result;
}

/**
 * ② プレビューで確定した内容を DB に書き込む。
 *
 * 全体を1つのトランザクションにしている。
 *   トランザクション = 「全部成功したら確定(commit)、途中で1つでも失敗したら全部取り消し(rollBack)」
 *   → 途中でエラーになって「バンドは入ったけどメンバーは入っていない」という中途半端な状態を防ぐ。
 *
 * @param array $plan  build_import_plan() の結果（セッションに入っていたもの）
 * @param array $input プレビューのフォームの送信内容
 * @return int[] 登録した live_id
 */
function commit_import_plan(PDO $pdo, array $plan, array $input): array
{
    $memberIndex = load_member_index($pdo);
    $validInstruments = array_map('intval', array_column(instruments(), 'instrument_id'));
    $bandIds = [];   // "日程番号:枠番号" → 登録した band_id
    $liveIds = [];

    $pdo->beginTransaction();
    try {
        // ================= 1. ライブ・日程・バンド =================
        foreach ($plan['timetables'] as $ti => $tt) {
            $form = $input['tt'][$ti] ?? [];
            if (!empty($form['skip'])) {
                continue; // 「この日程は取り込まない」
            }
            $where = "「{$tt['file']}」";

            // ---- 入力チェック（DB の型・制約に合わせる） ----
            $year     = (int)($form['year'] ?? 0);
            $liveName = trim((string)($form['live_name'] ?? ''));
            $label    = trim((string)($form['label'] ?? ''));
            $date     = (string)($form['date'] ?? '');
            $venue    = trim((string)($form['venue'] ?? ''));
            $meeting  = (string)($form['meeting_time'] ?? '');

            if ($year < 1990 || $year > 2100) { // live.fiscal_year の CHECK 制約と同じ範囲
                throw new RuntimeException("{$where} 年度が正しくありません");
            }
            if ($liveName === '' || mb_strlen($liveName) > 50) {
                throw new RuntimeException("{$where} ライブ名は1〜50文字で入力してください");
            }
            if ($label === '' || mb_strlen($label) > 50) {
                throw new RuntimeException("{$where} 日程（1日目など）は1〜50文字で入力してください");
            }
            // 日付は空なら NULL（分からないものは NULL）。入っているなら実在する日付か確認
            if ($date !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $d) || !checkdate((int)$d[2], (int)$d[3], (int)$d[1]))) {
                throw new RuntimeException("{$where} 開催日が正しくありません");
            }
            if (mb_strlen($venue) > 50) {
                throw new RuntimeException("{$where} 会場は50文字以内で入力してください");
            }

            // ---- 同じ日程が登録済みか ----
            $liveId = find_or_create_live($pdo, $year, $liveName);
            $st = $pdo->prepare('SELECT live_day_id FROM live_day WHERE live_id = ? AND label = ?');
            $st->execute([$liveId, $label]);
            $existing = $st->fetchColumn();
            if ($existing !== false) {
                if (empty($form['overwrite'])) {
                    throw new RuntimeException("{$year}年度「{$liveName}」{$label} は登録済みです。置き換えるなら「上書き」にチェックしてください");
                }
                // 日程を消せば、バンド・出演記録は ON DELETE CASCADE で DB が消してくれる
                delete_live_day($pdo, (int)$existing);
                $liveId = find_or_create_live($pdo, $year, $liveName); // ライブごと消えた場合に作り直す
            }

            // ---- live_day ----
            $pdo->prepare('INSERT INTO live_day (live_id, label, held_on, venue_id, meeting_time) VALUES (?, ?, ?, ?, ?)')
                ->execute([$liveId, $label, $date !== '' ? $date : null, find_or_create_venue($pdo, $venue),
                    preg_match('/^\d{2}:\d{2}$/', $meeting) ? $meeting : null]);
            $dayId = (int)$pdo->lastInsertId();

            // ---- band ----
            // 「取り込む」にチェックがある枠だけ集めて、入力された出演順で並べ替える
            $rows = [];
            foreach ($tt['slots'] as $si => $slot) {
                $s = $form['s'][$si] ?? [];
                if (empty($s['include'])) {
                    continue;
                }
                $name = trim((string)($s['name'] ?? ''));
                $songs = (string)($s['songs'] ?? '');
                if ($name === '' || mb_strlen($name) > 100) {
                    throw new RuntimeException("{$where} バンド名は1〜100文字で入力してください");
                }
                if ($songs !== '' && (!ctype_digit($songs) || (int)$songs > 255)) {
                    throw new RuntimeException("{$where}「{$name}」の曲数は0〜255の数字で入力してください");
                }
                $start = $slot['start_time'];
                $end = $slot['end_time'];
                $rows[] = [
                    'si' => $si,
                    'order' => (int)($s['order'] ?? 999),
                    'name' => $name,
                    'songs' => (int)$songs,
                    'note' => mb_substr(trim((string)($s['note'] ?? '')), 0, 255),
                    // 終了が開始より前（日付またぎ・書き間違い）は CHECK 制約に引っかかるので終了を捨てる
                    'start' => $start,
                    'end' => ($start && $end && $end > $start) ? $end : null,
                ];
            }
            // 出演順 → 同じ番号なら元の並び順、で並べる。<=> は「宇宙船演算子」（大小比較で -1/0/1 を返す）
            usort($rows, static fn($a, $b) => [$a['order'], $a['si']] <=> [$b['order'], $b['si']]);

            $insBand = $pdo->prepare('INSERT INTO band (live_day_id, artist_id, name, play_order, start_time, end_time, song_count, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($rows as $n => $r) {
                // 出演順は 1,2,3... と振り直す（(live_day_id, play_order) が UNIQUE なので重複させない）
                $insBand->execute([$dayId, find_or_create_artist($pdo, $r['name']), $r['name'], $n + 1,
                    $r['start'], $r['end'], $r['songs'], $r['note'] !== '' ? $r['note'] : null]);
                $bandIds["$ti:{$r['si']}"] = (int)$pdo->lastInsertId();
            }
            $liveIds[] = $liveId;
        }

        // ================= 2. 名簿 → メンバー =================
        foreach ($plan['rosters'] as $ri => $roster) {
            // 列ごとの楽器（プレビューの見出しのセレクトボックス）
            $colInstrument = [];
            foreach ($roster['columns'] as $col => $_) {
                $id = (int)($input['inst'][$ri][$col] ?? 0);
                $colInstrument[$col] = in_array($id, $validInstruments, true) ? $id : null;
            }
            foreach ($roster['bands'] as $bi => $band) {
                $rb = $input['rb'][$ri][$bi] ?? [];
                $slot = (string)($rb['slot'] ?? '');
                if ($slot === '' || !isset($bandIds[$slot])) {
                    continue; // どの出演バンドにも対応させなかった行 / 取り込まなかった枠
                }
                $assignments = [];
                foreach ($roster['columns'] as $col => $_) {
                    // 楽器の決め方（優先順）: ① 名前の後ろの (Sax) → ② セルごとの選択（Key/その他列） → ③ 列の楽器
                    $cellInstrument = (int)($rb['ci'][$col] ?? 0);
                    $cellInstrument = in_array($cellInstrument, $validInstruments, true) ? $cellInstrument : $colInstrument[$col];
                    foreach (split_member_names((string)($rb['c'][$col] ?? '')) as $name) {
                        [$name, $named] = parse_name_instrument($name);
                        $assignments[] = [$name, $named ?? $cellInstrument];
                    }
                }
                attach_members($pdo, $memberIndex, $bandIds[$slot], $assignments);
            }
        }

        // ================= 3. アカウントとメンバーの自動紐付け =================
        // 先にアカウントだけ作っていた人を、今回できたメンバーと名前で紐付ける
        link_users_to_members($pdo);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack(); // 途中までの INSERT を全部なかったことにする
        throw $e;
    }
    return array_values(array_unique($liveIds));
}
