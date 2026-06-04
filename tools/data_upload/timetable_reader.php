<?php

/**
 * タイムテーブルファイルを2次元配列にして返す。
 * 対応: csv, xlsx
 */
function readTimetableRows(array $uploadedFile): array
{
    if (empty($uploadedFile['tmp_name']) || !is_uploaded_file($uploadedFile['tmp_name'])) {
        throw new RuntimeException('ファイルがアップロードされていません');
    }

    $extension = strtolower(pathinfo($uploadedFile['name'] ?? '', PATHINFO_EXTENSION));

    return match ($extension) {
        'csv' => readTimetableCsv($uploadedFile['tmp_name']),
        'xlsx' => readTimetableXlsx($uploadedFile['tmp_name']),
        'pdf' => throw new RuntimeException('PDF読込はまだ未対応です。CSVまたはExcel（.xlsx）に変換してアップロードしてください'),
        default => throw new RuntimeException('対応していないファイル形式です。csv または xlsx をアップロードしてください'),
    };
}

function readTimetableCsv(string $path): array
{
    $rows = [];
    $file = new SplFileObject($path, 'r');
    $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

    foreach ($file as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row = normalizeTimetableRow($row);
        if (!isBlankTimetableRow($row)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function readTimetableXlsx(string $path): array
{
    $autoloadPaths = [
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../../../vendor/autoload.php',
    ];

    foreach ($autoloadPaths as $autoloadPath) {
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
            break;
        }
    }

    if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        throw new RuntimeException('Excel読込には phpoffice/phpspreadsheet が必要です。composer require phpoffice/phpspreadsheet を実行してください');
    }

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = [];

    foreach ($sheet->toArray(null, true, true, false) as $row) {
        $row = normalizeTimetableRow($row);
        if (!isBlankTimetableRow($row)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function normalizeTimetableRow(array $row): array
{
    return array_map(
        static fn($value) => trim(str_replace(["\r", "\n"], '', (string)$value)),
        $row
    );
}

function isBlankTimetableRow(array $row): bool
{
    foreach ($row as $value) {
        if ($value !== '') {
            return false;
        }
    }
    return true;
}

function findTimetableValueAfterLabel(array $row, string $label): string
{
    $index = array_search($label, $row, true);
    if ($index === false) {
        return '';
    }
    return $row[$index + 1] ?? '';
}

function parseTimetableRows(array $rows, PDO $pdo): array
{
    if (empty($rows)) {
        throw new RuntimeException('タイムテーブル内に読み込める行がありません');
    }

    $label_timetable = $rows[0];
    $column_live_name = preg_grep('/.*ライブ.*|.*日目.*/u', $label_timetable);
    $original_live_name = reset($column_live_name) ?: '';
    $live_name = preg_replace('/\d日目/u', '', $original_live_name);
    $live_day = preg_replace('/.*(?=\d日目)|日目/u', '', $original_live_name);

    $live_day_selected = ['', '', '', '', '', ''];
    if (empty($live_day)) {
        $live_day_selected[1] = 'selected';
    } elseif ($live_day === '教室ライブ') {
        $live_day_selected[5] = 'selected';
    } elseif (isset($live_day_selected[(int)$live_day])) {
        $live_day_selected[(int)$live_day] = 'selected';
    }

    $live_venue = findTimetableValueAfterLabel($label_timetable, '会場');

    $sql = 'SELECT venue_id,venue_name FROM venue';
    $stmt = $pdo->query($sql);
    $result = $stmt->fetchAll();
    $new_venue = 'selected';
    $venue_complete = [];

    foreach ($result as $key => $row_venue) {
        $venue_complete['n' . $key] = '';
        $venue_name = $row_venue['venue_name'] ?? '';
        if ($live_venue !== '' && $live_venue === $venue_name) {
            $venue_complete['n' . $key] = 'selected';
            $new_venue = '';
            continue;
        }
        similar_text($venue_name, $live_venue, $venue_similar);
        if ($live_venue !== '' && $venue_similar > 60) {
            $venue_complete['n' . $key] = 'selected';
            $new_venue = '';
        }
    }

    $band_info = [];
    $lines = 1;
    $band_column = null;
    $songs_column = null;
    $start_band_row = false;

    foreach ($rows as $row) {
        if ($band_column === null || $band_column === false) {
            $songs_column = array_search('曲数', $row, true);
            $band_column = array_search('バンド名', $row, true);
            continue;
        }

        if (!$start_band_row) {
            if (in_array('集合', $row, true)) {
                $start_band_row = true;
            }
            continue;
        }

        $band_name = $row[$band_column] ?? '';
        $songs = $songs_column !== false ? ($row[$songs_column] ?? '') : '';

        if ($band_name === '' || $band_name === '休憩' || $songs === '') {
            continue;
        }

        $band_name = str_replace(['(', ')'], ['（', '）'], $band_name);
        $band_info[] = [
            'number' => $lines,
            'name' => $band_name,
            'songs' => $songs,
        ];
        $lines++;
    }

    return compact('live_name', 'live_day_selected', 'live_venue', 'result', 'new_venue', 'venue_complete', 'band_info');
}
