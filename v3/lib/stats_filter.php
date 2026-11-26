<?php
/**
 * メンバー・期間の絞り込み（?who= / ?period=）。member.php の集計と search.php のバンドで使う
 *   メンバー: 入学年度が entryFrom〜entryTo の人（null = 全メンバー）。期間: ライブの年度
 *   stats.php は初期値（現役・上下3学年）が違うので別に持っている
 */
declare(strict_types=1);

/** GET から絞り込みの値を読む（変な値は初期値「全メンバー・全期間」に戻す） */
function stats_filter_read(PDO $pdo): array
{
    $getInt = static function (string $key): ?int {
        $v = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT);
        return is_int($v) ? $v : null;
    };
    $thisYear = current_fiscal_year();
    $liveYears = array_map('intval', $pdo->query('SELECT DISTINCT fiscal_year FROM live ORDER BY fiscal_year DESC')->fetchAll(PDO::FETCH_COLUMN));
    $myGrade = grade_of(my_entry_year());
    $myEntry = $myGrade !== null ? $thisYear - $myGrade + 1 : null;
    $canNear = $myEntry !== null;
    $activeFrom = $thisYear - 3;
    $who = $_GET['who'] ?? 'all';
    if (!in_array($who, ['all', 'active', 'grade', 'near', 'custom'], true) || ($who === 'near' && !$canNear)) {
        $who = 'all';
    }
    $slotEntries = array_map('intval', $pdo->query('SELECT DISTINCT entry_year FROM member
        WHERE entry_year IS NOT NULL ORDER BY entry_year DESC')->fetchAll(PDO::FETCH_COLUMN));
    $fEntry = $getInt('entry');
    if (!in_array($fEntry, $slotEntries, true)) {
        $fEntry = in_array($myEntry, $slotEntries, true) ? $myEntry : ($slotEntries[0] ?? $thisYear);
    }
    $efrom = $getInt('efrom') ?? $activeFrom;
    $eto   = $getInt('eto')   ?? $thisYear;
    if ($efrom > $eto) {
        [$efrom, $eto] = [$eto, $efrom];
    }
    [$entryFrom, $entryTo] = match ($who) {
        'active' => [$activeFrom, $thisYear],
        'grade'  => [$fEntry, $fEntry],
        'near'   => [$myEntry - 3, $myEntry + 3],
        'custom' => [$efrom, $eto],
        default  => [null, null],
    };
    $period = $_GET['period'] ?? 'all';
    if (!in_array($period, ['all', 'year', 'range'], true)) {
        $period = 'all';
    }
    $fYear = $getInt('year');
    if ($fYear === null || !in_array($fYear, $liveYears, true)) {
        $fYear = $liveYears[0] ?? $thisYear;
    }
    $pfrom = $getInt('pfrom') ?? ($liveYears ? min($liveYears) : $thisYear);
    $pto   = $getInt('pto')   ?? ($liveYears ? max($liveYears) : $thisYear);
    if ($pfrom > $pto) {
        [$pfrom, $pto] = [$pto, $pfrom];
    }
    return compact('thisYear', 'liveYears', 'myEntry', 'canNear', 'who', 'slotEntries', 'fEntry', 'efrom', 'eto',
        'entryFrom', 'entryTo', 'period', 'fYear', 'pfrom', 'pto');
}

/** 絞り込みのフォーム。$hidden は一緒に送る値（id・q など）、$attrs は <form> に足す属性 */
function stats_filter_form(array $f, array $hidden, string $attrs = '', string $periodLegend = '集計期間'): string
{
    $yearSlot = static function (string $name, int $selected, array $list, string $label, string $unit): string {
        $html = '<div class="year-slot year-slot--grade year-slot--fy" data-slot-input tabindex="0" role="spinbutton" aria-label="' . h($label) . '"><div class="year-slot__reel">';
        foreach ($list as $y) {
            $y = (int)$y;
            $html .= '<div class="year-slot__item' . ($y === $selected ? ' is-current' : '') . '" data-value="' . $y . '" data-label="' . $y . h($unit) . '">' . sprintf("'%02d", $y % 100) . '</div>';
        }
        return $html . '</div><input type="hidden" name="' . h($name) . '" value="' . $selected . '"></div>';
    };
    $tab = static fn(string $name, string $value, string $label, string $cur): string =>
        '<label class="tab"><input type="radio" name="' . $name . '" value="' . $value . '" data-autosubmit' . ($cur === $value ? ' checked' : '') . '>' . $label . '</label>';
    $entryYears = range($f['thisYear'], $f['thisYear'] - 15); // 新しい順
    $html = '<form method="get" class="card stats-filter no-print"' . $attrs . '>';
    foreach ($hidden as $k => $v) {
        $html .= '<input type="hidden" name="' . h((string)$k) . '" value="' . h((string)$v) . '">';
    }
    $html .= '<fieldset class="stats-filter__row"><legend>メンバー</legend>'
        . '<div class="tabs tabs--filter" role="radiogroup" aria-label="メンバー">'
        . $tab('who', 'all', '全メンバー', $f['who']) . $tab('who', 'active', '現役', $f['who']) . $tab('who', 'grade', '学年別', $f['who'])
        . '<label class="tab' . ($f['canNear'] ? '' : ' is-disabled') . '"' . ($f['canNear'] ? '' : ' title="アカウントがメンバーに紐付いていないか、入学年度が未登録のため使えません"') . '>'
        . '<input type="radio" name="who" value="near" data-autosubmit' . ($f['who'] === 'near' ? ' checked' : '') . ($f['canNear'] ? '' : ' disabled') . '>上下3学年</label>'
        . $tab('who', 'custom', '範囲指定', $f['who'])
        . '</div>'
        . '<div class="stats-filter__extra" data-show-when="who=grade">'
        . '<div class="year-slot year-slot--grade" data-slot-input tabindex="0" role="spinbutton" aria-label="学年" title="上下にドラッグで学年を切り替え"><div class="year-slot__reel">';
    foreach (array_reverse($f['slotEntries']) as $se) {
        $sg = grade_of($se);
        $html .= grade_slot_item((string)$se, ($sg !== null ? h(grade_label($sg)) : '') . sprintf("<small>'%02d</small>", $se % 100), (string)$f['fEntry']);
    }
    $html .= '</div><input type="hidden" name="entry" value="' . $f['fEntry'] . '"></div></div>'
        . '<div class="stats-filter__extra" data-show-when="who=custom">'
        . $yearSlot('efrom', $f['efrom'], $entryYears, '入学年度（から）', '年入学') . '<span>〜</span>'
        . $yearSlot('eto', $f['eto'], $entryYears, '入学年度（まで）', '年入学') . '<span class="muted small">年入学</span>'
        . '</div></fieldset>'
        . '<fieldset class="stats-filter__row"><legend>' . h($periodLegend) . '</legend>'
        . '<div class="tabs tabs--filter" role="radiogroup" aria-label="期間">'
        . $tab('period', 'all', '全期間', $f['period']) . $tab('period', 'year', '単年度', $f['period']) . $tab('period', 'range', '期間指定', $f['period'])
        . '</div>'
        . '<div class="stats-filter__extra" data-show-when="period=year">'
        . $yearSlot('year', $f['fYear'], $f['liveYears'], '年度', '年度') . '<span class="muted small">年度</span></div>'
        . '<div class="stats-filter__extra" data-show-when="period=range">'
        . $yearSlot('pfrom', $f['pfrom'], $f['liveYears'], '期間（から）', '年度') . '<span>〜</span>'
        . $yearSlot('pto', $f['pto'], $f['liveYears'], '期間（まで）', '年度') . '<span class="muted small">年度</span></div>'
        . '</fieldset>'
        . '<noscript><button class="btn btn--sm" type="submit">適用</button></noscript>'
        . '</form>';
    return $html;
}

/** 今の絞り込みを URL に残す値（「もっと見る」のリンク用）。全メンバー・全期間なら空 */
function stats_filter_query(array $f): array
{
    $q = [];
    if ($f['who'] !== 'all') {
        $q['who'] = $f['who'];
        $q += match ($f['who']) {
            'grade'  => ['entry' => $f['fEntry']],
            'custom' => ['efrom' => $f['efrom'], 'eto' => $f['eto']],
            default  => [],
        };
    }
    if ($f['period'] !== 'all') {
        $q['period'] = $f['period'];
        $q += $f['period'] === 'year' ? ['year' => $f['fYear']] : ['pfrom' => $f['pfrom'], 'pto' => $f['pto']];
    }
    return $q;
}
