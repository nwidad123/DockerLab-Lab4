$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path $PSScriptRoot -Parent
$webRoot = Join-Path $projectRoot 'www'
$files = Get-ChildItem $webRoot -Filter *.php -Recurse
$failed = @()

foreach ($file in $files) {
    $relativePath = $file.FullName.Substring($webRoot.Length + 1)
    $containerPath = '/usr/share/nginx/html/' +
        $relativePath.Replace('\', '/')

    & docker exec lab_php php -l $containerPath

    if ($LASTEXITCODE -ne 0) {
        $failed += $relativePath
    }
}

if ($failed.Count -gt 0) {
    Write-Host "`nFailed files:" -ForegroundColor Red
    $failed | ForEach-Object { Write-Host $_ }
    exit 1
}

Write-Host "`nPASS: All $($files.Count) PHP files passed syntax checks." `
    -ForegroundColor Green
exit 0