<?php
declare(strict_types=1);

require_once __DIR__ . '/parsers.php';

/** 4月始まりの年度 */
function academic_year(?int $month = null, ?int $year = null): int
{
    $month ??= (int)date('n');
    $year ??= (int)date('Y');
    return $month >= 4 ? $year : $year - 1;
}

/** 既存メンバーを比較キーで引ける形に */
function load_member_index(PDO $pdo): array
{
    $index = [];
    foreach ($pdo->query('SELECT member_id, member_name FROM member') as $row) {
        $index[member_key($row['member_name'])] = ['id' => (int)$row['member_id'], 'name' => $row['member_name']];
    }
    return $index;
}

/**
 * アップロードされたファイル群からプレビュー用の取り込み計画を作る。
 * @param array $files [['path' => tmp, 'name' => 元のファイル名], ...]
 */
function build_import_plan(PDO $pdo, array $files): array
{
    $plan = ['timetables' => [], 'roster' => [], 'names' => [], 'errors' => []];

    foreach ($files as $f) {
        try {
            $rows = read_table_file($f['path'], $f['name']);
            if (detect_table_kind($rows) === 'timetable') {
                $tt = parse_timetable($rows);
                $tt['file'] = $f['name'];
                $plan['timetables'][] = $tt;
            } else {
                foreach (parse_roster($rows) as $band) {
                    $band['file'] = $f['name'];
                    $plan['roster'][] = $band;
                }
            }
        } catch (Throwable $e) {
            $plan['errors'][] = $f['name'] . ': ' . $e->getMessage();
        }
    }

    $year = academic_year();
    foreach ($plan['timetables'] as &$tt) {
        $tt['year'] = $year;
        $tt['date'] = '';
        if ($tt['month'] && $tt['day'] && checkdate($tt['month'], $tt['day'], $year)) {
            $tt['date'] = sprintf('%04d-%02d-%02d', $tt['month'] >= 4 ? $year : $year + 1, $tt['month'], $tt['day']);
        }
        foreach ($tt['slots'] as &$slot) {
            $slot['roster_index'] = $slot['is_band'] ? match_roster_band($slot, $plan['roster']) : null;
        }
        unset($slot);
    }
    unset($tt);

    // ---- 人名の名寄せ候補 ----
    $existing = load_member_index($pdo);
    $names = [];
    foreach ($plan['roster'] as $band) {
        foreach ($band['members'] as $m) {
            $names[member_key($m['name'])] ??= $m['name'];
        }
    }
    foreach ($names as $key => $name) {
        $entry = ['name' => $name, 'existing' => $existing[$key] ?? null, 'suggest' => []];
        if ($entry['existing'] === null) {
            foreach ($existing as $eKey => $e) {
                if (names_look_similar($key, $eKey)) {
                    $entry['suggest'][] = ['value' => 'db:' . $e['id'], 'label' => $e['name'] . '（登録済み）'];
                }
            }
            foreach ($names as $oKey => $oName) {
                if (!isset($existing[$oKey]) && names_look_similar($key, $oKey)) {
                    $entry['suggest'][] = ['value' => 'same:' . $oKey, 'label' => $oName . '（今回の取り込み内）'];
                }
            }
        }
        $plan['names'][$key] = $entry;
    }
    return $plan;
}

/**
 * プレビューで確定した内容をDBへ書き込む。全部成功するか全部やめるか（トランザクション）。
 * @return int[] 登録した live_id
 */
function commit_import_plan(PDO $pdo, array $plan, array $input): array
{
    $members = load_member_index($pdo);
    $choices = $input['name'] ?? [];
    $resolving = [];

    // 名前 → member_id（無ければ作る）
    $resolve = static function (string $name) use (&$resolve, &$members, &$resolving, $choices, $plan, $pdo): int {
        $key = member_key($name);
        if (isset($members[$key])) {
            return $members[$key]['id'];
        }
        $choice = (string)($choices[$key] ?? 'new');
        if (str_starts_with($choice, 'db:')) {
            return $members[$key]['id'] = (int)substr($choice, 3);
        }
        if (str_starts_with($choice, 'same:') && empty($resolving[$key])) {
            $other = substr($choice, 5);
            if (isset($plan['names'][$other])) {
                $resolving[$key] = true;
                $id = $resolve($plan['names'][$other]['name']);
                $members[$key] = ['id' => $id, 'name' => $name];
                return $id;
            }
        }
        $pdo->prepare('INSERT INTO member (member_name) VALUES (?)')->execute([member_display($name)]);
        $id = (int)$pdo->lastInsertId();
        $members[$key] = ['id' => $id, 'name' => $name];
        return $id;
    };

    $liveIds = [];
    $pdo->beginTransaction();
    try {
        foreach ($plan['timetables'] as $ti => $tt) {
            $form = $input['tt'][$ti] ?? [];
            if (!empty($form['skip'])) {
                continue;
            }
            $year = (int)($form['year'] ?? 0);
            $liveName = trim((string)($form['live_name'] ?? ''));
            $dayNo = max(1, (int)($form['day_no'] ?? 1));
            $venueName = trim((string)($form['venue'] ?? ''));
            $date = (string)($form['date'] ?? '');
            $meeting = (string)($form['meeting_time'] ?? '');
            if ($year < 1990 || $year > 2100 || $liveName === '') {
                throw new RuntimeException("{$tt['file']}: 年度とライブ名は必須です");
            }
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
            $meeting = preg_match('/^\d{2}:\d{2}$/', $meeting) ? $meeting : null;

            $venueId = null;
            if ($venueName !== '') {
                $st = $pdo->prepare('SELECT venue_id FROM venue WHERE venue_name = ?');
                $st->execute([$venueName]);
                $venueId = $st->fetchColumn();
                if ($venueId === false) {
                    $pdo->prepare('INSERT INTO venue (venue_name) VALUES (?)')->execute([$venueName]);
                    $venueId = $pdo->lastInsertId();
                }
            }

            $st = $pdo->prepare('SELECT live_id FROM live_master WHERE year = ? AND live_name = ?');
            $st->execute([$year, $liveName]);
            $liveId = $st->fetchColumn();
            if ($liveId === false) {
                $pdo->prepare('INSERT INTO live_master (year, live_name) VALUES (?, ?)')->execute([$year, $liveName]);
                $liveId = $pdo->lastInsertId();
            }
            $liveId = (int)$liveId;

            $st = $pdo->prepare('SELECT live_detail_id FROM live_detail WHERE live_id = ? AND day_no = ?');
            $st->execute([$liveId, $dayNo]);
            $existingDetail = $st->fetchColumn();
            if ($existingDetail !== false) {
                if (empty($form['overwrite'])) {
                    throw new RuntimeException("{$year}年度「{$liveName}」{$dayNo}日目 は登録済みです。上書きする場合は「上書き」にチェックしてください");
                }
                $pdo->prepare('DELETE FROM live_detail WHERE live_detail_id = ?')->execute([$existingDetail]);
            }

            $pdo->prepare('INSERT INTO live_detail (live_id, day_no, live_date, venue_id, meeting_time) VALUES (?, ?, ?, ?, ?)')
                ->execute([$liveId, $dayNo, $date, $venueId, $meeting]);
            $detailId = (int)$pdo->lastInsertId();

            $insBand = $pdo->prepare('INSERT INTO band_master
                (live_detail_id, band_name, play_order, start_time, end_time, song_count, member_count, key_note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $insMember = $pdo->prepare('INSERT IGNORE INTO band_member (band_id, member_id, part) VALUES (?, ?, ?)');

            foreach ($tt['slots'] as $si => $slot) {
                if (!$slot['is_band']) {
                    continue;
                }
                $pick = $form['band'][$si] ?? '';
                $roster = ($pick !== '' && isset($plan['roster'][(int)$pick])) ? $plan['roster'][(int)$pick] : null;
                $insBand->execute([
                    $detailId,
                    mb_substr($slot['band_name'], 0, 128),
                    $slot['play_order'],
                    $slot['start_time'],
                    $slot['end_time'],
                    $slot['song_count'] ?? $roster['song_count'] ?? null,
                    $slot['member_count'] ?? $roster['member_count'] ?? ($roster ? count(array_unique(array_column($roster['members'], 'name'))) : null),
                    mb_substr($slot['key_note'] !== '' ? $slot['key_note'] : ($roster['key_note'] ?? ''), 0, 128),
                ]);
                $bandId = (int)$pdo->lastInsertId();
                foreach ($roster['members'] ?? [] as $m) {
                    $insMember->execute([$bandId, $resolve($m['name']), $m['part']]);
                }
            }
            $liveIds[] = $liveId;
        }
        // 上書きで出番がなくなったメンバーを掃除
        $pdo->exec('DELETE m FROM member m
            LEFT JOIN band_member bm ON bm.member_id = m.member_id
            LEFT JOIN user_index u ON u.member_id = m.member_id
            WHERE bm.member_id IS NULL AND u.user_auto_id IS NULL');
        $members = load_member_index($pdo);
        // 先にアカウントだけ作っていた人を、今回できたメンバーと紐付ける
        $link = $pdo->prepare('UPDATE user_index SET member_id = ? WHERE user_auto_id = ? AND member_id IS NULL');
        $linked = array_map('intval', $pdo->query('SELECT member_id FROM user_index WHERE member_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
        foreach ($pdo->query('SELECT user_auto_id, user_name FROM user_index WHERE member_id IS NULL')->fetchAll() as $u) {
            $m = $members[member_key($u['user_name'])] ?? null;
            if ($m && !in_array($m['id'], $linked, true)) {
                $link->execute([$m['id'], $u['user_auto_id']]);
                $linked[] = $m['id'];
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return array_values(array_unique($liveIds));
}
