"""
check_csv.py — 作った CSV を、取り込み画面（v3/lib/import/parsers.php）と同じ読み方で読んで結果を表示する

    python check_csv.py 12月ライブ1日目_timetable.csv [名簿.csv ...]

PHP の parse_timetable / parse_roster / split_member_names の決まりを Python でまねている。
ここで ✖ / ⚠ が出なければ、取り込み画面でも同じように読める。
"""
import csv
import re
import sys
import unicodedata

NON_BAND = re.compile(r'^(休憩|昼休憩|ご飯|昼食|夕食|転換|メインステージ|撤収|片付け|片づけ|リハ.*|準備|解散|打ち上げ)$')
EMPTY_NAME = re.compile(r'^(未定|募集中?|なし|無し|-+|\?+|？+)$')


def width(s: str) -> str:
    """全角英数記号→半角、空白をそろえる（PHP の tt_width のかわり）"""
    s = unicodedata.normalize('NFKC', s)
    s = s.translate(str.maketrans({'〜': '~', '～': '~', '−': '-', '–': '-', '—': '-'}))
    return re.sub(r'\s+', ' ', s).strip()


def normalize_part(header: str):
    h = re.sub(r'[\s.．]', '', width(header)).lower()
    if h == '':
        return None
    if h.startswith('vo'):
        return 'Vo'
    if h.startswith(('gt', 'gu', 'ギ')):
        return 'Gt'
    if h.startswith(('ba', 'ベ')):
        return 'Ba'
    if h.startswith(('dr', 'ドラ')):
        return 'Dr'
    if h.startswith(('key', 'kb', 'syn', 'キ')):
        return 'Key'
    if 'その他' in h or h.startswith(('cho', 'perc')):
        return 'Other'
    return None


def find_column(header, pattern, start=0):
    for i, title in enumerate(header):
        if i >= start and re.search(pattern, width(title), re.I):
            return i
    return None


def extract_int(text: str):
    m = re.search(r'\d+', width(text))
    return int(m.group()) if m else None


def extract_times(text: str):
    return [f'{int(h):02d}:{m}' for h, m in re.findall(r'(\d{1,2})[:：](\d{2})', width(text))]


def split_member_names(cell: str):
    """PHP の split_member_names と同じ区切り方"""
    cell = width(cell)
    names = []
    for chunk in re.split(r'[・、,，/／&＆\n]+', cell):
        chunk = re.sub(r'\s+(?=[(（])', '', chunk.strip())
        if not chunk:
            continue
        parts = chunk.split()
        if len(parts) > 1 and all(len(p) >= 3 for p in parts):
            people = parts
        elif len(parts) == 4 and not re.search(r'[()（）]', chunk):
            people = [parts[0] + parts[1], parts[2] + parts[3]]
        else:
            people = [''.join(parts)]
        names += [p for p in people if p and not EMPTY_NAME.match(p)]
    return names


def read_rows(path):
    with open(path, encoding='utf-8-sig', newline='') as f:
        return [[c for c in row] for row in csv.reader(f)]


def check_timetable(rows, hi, problems):
    header = rows[hi]
    preamble = [width(c) for row in rows[:hi] for c in row if width(c)]
    month = day = None
    venue = ''
    titles = []
    i = 0
    while i < len(preamble):
        cell = preamble[i]
        if cell in ('会場', '会場:'):
            venue = preamble[i + 1] if i + 1 < len(preamble) else ''
            i += 1
        elif m := re.match(r'^会場[:：]?\s*(.+)$', cell):
            venue = m.group(1)
        elif m := re.search(r'(\d{1,2})月(\d{1,2})日', cell):
            month, day = int(m.group(1)), int(m.group(2))
        elif m := re.match(r'^(\d{1,2})/(\d{1,2})$', cell):
            month, day = int(m.group(1)), int(m.group(2))
        else:
            titles.append(cell)
        i += 1
    title = next((t for t in titles if re.search(r'ライブ|日目|live', t, re.I)), titles[0] if titles else '')

    print(f'タイトル: {title or "（なし）"} / 日付: {f"{month}月{day}日" if month else "（読めない）"} / 会場: {venue or "（なし）"}')
    if not month:
        problems.append('日付が読めない。前置き行に「12月6日」か「12/6」の形で書く')
    if not title:
        problems.append('タイトル（〇〇ライブ1日目 など）が無い')
    if not venue:
        problems.append('会場が無い（無いなら、ユーザーに確認したか？）')
    if re.search(r'\d{4}年', ' '.join(preamble)):
        problems.append('前置き行に年が書いてある。年は書かない')

    c_time = find_column(header, r'^時間')
    c_band = find_column(header, r'バンド名')
    c_songs = find_column(header, r'曲数')
    c_count = find_column(header, r'人数')
    time_cols = [c_time]
    if c_time + 1 < len(header) and header[c_time + 1].strip() == '' and c_time + 1 != c_band:
        time_cols.append(c_time + 1)
    else:
        problems.append('「時間」の右に見出しが空の列（終了時刻）が無い')

    bands = 0
    for n, row in enumerate(rows[hi + 1:], start=hi + 2):
        row = row + [''] * (len(header) - len(row))
        name = width(row[c_band])
        if name in ('', 'バンド名'):
            if any(width(c) for c in row):
                problems.append(f'{n}行目: バンド名が空なのに他のセルがある')
            continue
        if name.startswith('集合'):
            continue
        times = extract_times(' '.join(row[c] for c in time_cols))
        songs = extract_int(row[c_songs]) if c_songs is not None else None
        count = extract_int(row[c_count]) if c_count is not None else None
        is_band = not NON_BAND.match(name) and not (songs is None and count is None)
        bands += is_band
        mark = '♪' if is_band else '－'
        print(f'  {mark} {times[0] if times else "??:??"}-{times[1] if len(times) > 1 else "??:??"}  {name}  曲数:{songs if songs is not None else "-"} 人数:{count if count is not None else "-"}')
        if not times:
            problems.append(f'{n}行目 {name}: 開始時刻が読めない（HH:MM で書く）')
        elif len(times) < 2:
            problems.append(f'{n}行目 {name}: 終了時刻が無い')
        if not is_band and not NON_BAND.match(name):
            problems.append(f'{n}行目 {name}: 曲数も人数も空なのでバンドではない枠になる。バンドなら数字を入れる')
        if '?' in name:
            problems.append(f'{n}行目 {name}: 「?」が残っている（ユーザーに確認して消す）')
    print(f'  → バンド {bands} 組')


def check_roster(rows, hi, problems):
    header = rows[hi]
    c_band = find_column(header, r'バンド名')
    c_songs = find_column(header, r'曲数')
    end = c_songs if c_songs is not None else len(header)
    cols = {c: normalize_part(header[c]) for c in range(c_band + 1, end) if normalize_part(header[c])}
    print('パート列: ' + ' / '.join(f'{width(header[c])}→{p}' for c, p in cols.items()))
    ignored = [width(header[c]) for c in range(c_band + 1, end) if c not in cols and width(header[c])]
    if ignored:
        problems.append(f'パートとして読まれない見出し: {ignored}（Vo/Gt/Ba/Dr/Key/その他 で始める）')

    for n, row in enumerate(rows[hi + 1:], start=hi + 2):
        row = row + [''] * (len(header) - len(row))
        name = width(row[c_band])
        if name in ('', 'バンド名'):
            continue
        members = []
        for c, part in cols.items():
            raw = width(row[c])
            for person in split_member_names(raw):
                members.append(f'{person}({part})')
            if re.search(r'\S \S', re.sub(r'\s+(?=[(（])', '', raw)) and len(split_member_names(raw)) == 1 and '、' not in raw:
                problems.append(f'{n}行目 {name}: 「{raw}」は1人として読まれる。姓名の空白は詰める／2人なら「、」で区切る')
        print(f'  {name}: ' + ' '.join(members))
        if '?' in ''.join(row):
            problems.append(f'{n}行目 {name}: 「?」が残っている（ユーザーに確認して消す）')


def main(paths):
    failed = False
    for path in paths:
        print(f'===== {path} =====')
        problems = []
        try:
            with open(path, 'rb') as f:
                if not f.read(3) == b'\xef\xbb\xbf':
                    problems.append('BOM が無い（Excel で開くと化ける）。UTF-8 BOM付きで保存する')
            rows = read_rows(path)
        except UnicodeDecodeError:
            print('✖ UTF-8 で読めない')
            failed = True
            continue
        hi = next((i for i, row in enumerate(rows) if any('バンド名' in c for c in row)), None)
        if hi is None:
            print('✖ 「バンド名」の見出しが無い → 取り込めない')
            failed = True
            continue
        if any(normalize_part(t) in ('Vo', 'Gt', 'Ba', 'Dr') for t in rows[hi]):
            print('種類: 名簿')
            check_roster(rows, hi, problems)
        elif find_column(rows[hi], r'時間') is not None:
            print('種類: タイムテーブル')
            check_timetable(rows, hi, problems)
        else:
            print('✖ タイムテーブルか名簿か判別できない（「時間」列も Vo/Gt/Ba/Dr 列も無い）')
            failed = True
            continue
        for p in problems:
            print(f'⚠ {p}')
        failed = failed or bool(problems)
        print()
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print('使い方: python check_csv.py <CSV> [...]')
        sys.exit(2)
    sys.stdout.reconfigure(encoding='utf-8')
    main(sys.argv[1:])
