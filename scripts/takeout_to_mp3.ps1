# Google Takeout（YouTube）で落とした動画を「動画ID.mp3」に変換する。できた MP3 はバンド編集でアップロードするかドライブに置いて紐付け、区分を YouTube にする（migrations/153）
#   powershell -File scripts/takeout_to_mp3.ps1 -Takeout "C:\...\Takeout\YouTube and YouTube Music" -Out "C:\...\mp3"
#   Takeout の動画のファイル名はタイトルなので、Takeout に入っている動画の一覧 CSV（動画 ID とタイトル）で動画 ID に直す
#   ファイル名がすでに動画 ID（11文字）のものはそのまま使う。同じタイトルが2本以上ある動画は変換せずに名前を出す（手で「動画ID.mp4」に直してもう一度）
#   128kbps（25分で約24MB）。60MB（約65分）を超えるとサイトに上げられない
param(
    [Parameter(Mandatory)][string]$Takeout,
    [Parameter(Mandatory)][string]$Out,
    [string]$Ffmpeg = 'ffmpeg'
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [Text.Encoding]::UTF8
New-Item -ItemType Directory -Force $Out | Out-Null

# タイトルの比較用: 文字と数字だけ・小文字（Takeout はファイル名に使えない記号を置き換えるため）
function Get-Norm([string]$s) { ($s -replace '[^\p{L}\p{N}]', '').ToLowerInvariant() }

# 動画の一覧 CSV: 「ID」を含む列と「タイトル / Title」を含む列を探す（Takeout の版で列名が変わるため）
$byTitle = @{}
foreach ($csv in Get-ChildItem $Takeout -Recurse -Filter *.csv) {
    $rows = @(Import-Csv $csv.FullName -Encoding UTF8)
    if (-not $rows) { continue }
    $cols = $rows[0].PSObject.Properties.Name
    $idCol = $cols | Where-Object { $_ -match 'ID' } | Select-Object -First 1
    $titleCol = $cols | Where-Object { $_ -match 'タイトル|Title' } | Select-Object -First 1
    if (-not $idCol -or -not $titleCol) { continue }
    foreach ($r in $rows) {
        if ($r.$idCol -notmatch '^[A-Za-z0-9_-]{11}$') { continue }
        $k = Get-Norm $r.$titleCol
        if (-not $byTitle.ContainsKey($k)) { $byTitle[$k] = [Collections.Generic.HashSet[string]]::new() }
        [void]$byTitle[$k].Add($r.$idCol)
    }
}
Write-Host "一覧の動画: $($byTitle.Count) 件"

$skipped = @()
foreach ($f in Get-ChildItem $Takeout -Recurse -Include *.mp4, *.mov, *.webm, *.mkv, *.m4a) {
    $name = [IO.Path]::GetFileNameWithoutExtension($f.Name)
    [string[]]$ids = if ($name -match '^[A-Za-z0-9_-]{11}$') { @($name) } else { @(@($byTitle[(Get-Norm $name)]) | Where-Object { $_ }) }
    if ($ids.Count -ne 1) {
        $skipped += "$($f.Name)（" + $(if ($ids.Count) { "同じタイトルが $($ids.Count) 本" } else { '一覧に無い' }) + '）'
        continue
    }
    $dst = Join-Path $Out "$($ids[0]).mp3"
    if (Test-Path $dst) { continue } # 変換済み（やり直すときは消す）
    Write-Host "$($f.Name) → $($ids[0]).mp3"
    & $Ffmpeg -v error -y -i $f.FullName -vn -map_metadata -1 -c:a libmp3lame -b:a 128k -ar 44100 $dst
    if ($LASTEXITCODE -ne 0) { $skipped += "$($f.Name)（ffmpeg が失敗）"; Remove-Item $dst -ErrorAction SilentlyContinue; continue }
    if ((Get-Item $dst).Length -gt 60MB) { $skipped += "$($ids[0]).mp3（60MB を超えるので上げられない）" }
}
if ($skipped) { Write-Host "`n変換しなかったもの:"; $skipped | ForEach-Object { Write-Host "  $_" } }
