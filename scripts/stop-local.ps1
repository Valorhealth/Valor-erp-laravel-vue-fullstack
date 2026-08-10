$root = Split-Path -Parent $PSScriptRoot
$runtimeDir = Join-Path $root '.runtime'

function Stop-ProcessTree {
    param([int]$RootId)

    $children = Get-CimInstance Win32_Process -Filter "ParentProcessId = $RootId" -ErrorAction SilentlyContinue
    foreach ($child in $children) {
        Stop-ProcessTree -RootId ([int]$child.ProcessId)
    }

    if (Get-Process -Id $RootId -ErrorAction SilentlyContinue) {
        Stop-Process -Id $RootId -Force -ErrorAction SilentlyContinue
    }
}

$processIds = [System.Collections.Generic.HashSet[int]]::new()
foreach ($name in @('api', 'vue', 'ollama', 'budget-worker')) {
    $pidPath = Join-Path $runtimeDir "$name.pid"
    if (-not (Test-Path -LiteralPath $pidPath)) {
        continue
    }

    $processId = [int](Get-Content -LiteralPath $pidPath -Raw)
    [void]$processIds.Add($processId)
    Remove-Item -LiteralPath $pidPath -Force
}

# Artisan and pnpm can spawn child listeners then let the launcher exit. Pick up
# only processes whose command line belongs to this workspace and local ports.
$escapedRoot = [regex]::Escape($root)
$workspaceProcesses = Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
    $_.CommandLine -and
    $_.CommandLine -match $escapedRoot -and
    ($_.CommandLine -match 'artisan\s+serve.*--port=8000' -or
        $_.CommandLine -match '-S\s+127\.0\.0\.1:8000.*(?:server|local-api-router)\.php' -or
        $_.CommandLine -match 'vite.*--port.*5173')
}
foreach ($process in $workspaceProcesses) {
    [void]$processIds.Add([int]$process.ProcessId)
}

foreach ($processId in $processIds) {
    Stop-ProcessTree -RootId $processId
}

Write-Output 'Valor ERP prototype processes stopped.'

# powershell -ExecutionPolicy Bypass -File .\scripts\stop-local.ps1
