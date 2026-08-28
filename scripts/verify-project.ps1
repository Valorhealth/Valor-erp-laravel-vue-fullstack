$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$backend = Join-Path $projectRoot 'backend'
$frontend = Join-Path $projectRoot 'frontend'

$requiredExtensions = @('fileinfo', 'gd', 'pdo_pgsql', 'zip')
$loadedExtensions = @(php -m | ForEach-Object { $_.Trim().ToLowerInvariant() })
$missingExtensions = @($requiredExtensions | Where-Object { $_ -notin $loadedExtensions })
if ($missingExtensions.Count -gt 0) {
    throw "PHP extensions required for the full test suite are missing: $($missingExtensions -join ', ')"
}

Push-Location $backend
try {
    composer validate --no-check-publish
    composer audit --no-interaction
    php artisan route:list --path=api/v1 --json | Out-Null
    php artisan test
    python -m unittest discover tests/python -v
} finally {
    Pop-Location
}

Push-Location $frontend
try {
    pnpm install --frozen-lockfile
    pnpm audit
    pnpm build
} finally {
    Pop-Location
}

Write-Host 'Valor ERP verification completed successfully.' -ForegroundColor Green
