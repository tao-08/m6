# デプロイを「起動 → 完了まで待つ → 結果表示」まで1コマンドで行う
#   ドライラン: powershell -File scripts/deploy.ps1
#   本番      : powershell -File scripts/deploy.ps1 -Prod
param([switch]$Prod)

# winget で入れた gh は、開いたままのターミナルだと PATH に載っていないことがあるので読み直す
$env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')

$dry = if ($Prod) { 'false' } else { 'true' }

# 起動前の最新の実行ID。PCとGitHubの時計はズレうるので、時刻ではなくID（増え続ける番号）で新しい実行を見分ける
$before = [long](gh run list --workflow=deploy.yml --limit 1 --json databaseId --jq '.[0].databaseId')

gh workflow run deploy.yml --ref main -f dry_run=$dry
if ($LASTEXITCODE -ne 0) { exit 1 }

$id = $null
for ($i = 0; $i -lt 20 -and -not $id; $i++) {
    Start-Sleep 1
    $runs = gh run list --workflow=deploy.yml --event=workflow_dispatch --limit 5 --json databaseId | ConvertFrom-Json
    $id = ($runs | Where-Object { $_.databaseId -gt $before } | Select-Object -First 1).databaseId
}
if (-not $id) { Write-Host '実行が見つかりません'; exit 1 }

gh run watch $id --interval 2 | Out-Null
$r = gh run view $id --json conclusion,url,jobs | ConvertFrom-Json
$mode = if ($Prod) { '本番' } else { 'ドライラン' }
Write-Host "$mode : $($r.conclusion)  $($r.url)"
$r.jobs.steps | ForEach-Object { Write-Host "  $($_.conclusion)  $($_.name)" }
if ($r.conclusion -ne 'success') { exit 1 }
