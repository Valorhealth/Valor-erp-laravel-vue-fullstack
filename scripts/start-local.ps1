$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$runtimeDir = Join-Path $root '.runtime'
$php = Join-Path $root '.tools\php\php.exe'
$codexRuntime = Join-Path $env:USERPROFILE '.cache\codex-runtimes\codex-primary-runtime\dependencies'
$bundledNode = Join-Path $codexRuntime 'node\bin\node.exe'
$bundledPnpm = Join-Path $codexRuntime 'bin\fallback\pnpm.cmd'

if (-not (Test-Path -LiteralPath $php)) {
    throw "ไม่พบ PHP portable ที่ $php"
}

$node = Get-Command node -ErrorAction SilentlyContinue
$pnpm = Get-Command pnpm -ErrorAction SilentlyContinue
$pythonCommand = Get-Command python -ErrorAction SilentlyContinue
$ollamaCommand = Get-Command ollama -ErrorAction SilentlyContinue
$nodePath = if ($node) { $node.Source } elseif (Test-Path -LiteralPath $bundledNode) { $bundledNode } else { $null }
$pnpmPath = if ($pnpm) { $pnpm.Source } elseif (Test-Path -LiteralPath $bundledPnpm) { $bundledPnpm } else { $null }
$pythonPath = if ($pythonCommand) { $pythonCommand.Source } else { $null }

if (-not $nodePath -or -not $pnpmPath) {
    throw 'ไม่พบ Node.js หรือ pnpm สำหรับรัน Vue'
}
if (-not $pythonPath) {
    throw 'ไม่พบ Python สำหรับระบบวิเคราะห์งบประมาณ'
}

New-Item -ItemType Directory -Force -Path $runtimeDir | Out-Null
$env:PATH = "$(Split-Path -Parent $nodePath);$env:PATH"
$env:PYTHON_BINARY = $pythonPath

$ollamaPortInUse = Get-NetTCPConnection -LocalPort 11434 -State Listen -ErrorAction SilentlyContinue
if (-not $ollamaPortInUse -and $ollamaCommand) {
    $ollama = Start-Process -FilePath $ollamaCommand.Source `
        -ArgumentList @('serve') `
        -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $runtimeDir 'ollama.out.log') `
        -RedirectStandardError (Join-Path $runtimeDir 'ollama.error.log') `
        -PassThru
    Set-Content -LiteralPath (Join-Path $runtimeDir 'ollama.pid') -Value $ollama.Id

    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        Start-Sleep -Milliseconds 250
        $ollamaPortInUse = Get-NetTCPConnection -LocalPort 11434 -State Listen -ErrorAction SilentlyContinue
        if ($ollamaPortInUse) { break }
    }
}

$apiPortInUse = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue
if (-not $apiPortInUse) {
    $serverRouter = Join-Path $root 'scripts\local-api-router.php'
    $api = Start-Process -FilePath $php `
        -ArgumentList @(
            '-d', 'display_errors=Off',
            '-d', 'upload_max_filesize=128M',
            '-d', 'post_max_size=140M',
            '-d', 'memory_limit=768M',
            '-S', '127.0.0.1:8000',
            "`"$serverRouter`""
        ) `
        -WorkingDirectory (Join-Path $root 'backend\public') `
        -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $runtimeDir 'api.out.log') `
        -RedirectStandardError (Join-Path $runtimeDir 'api.error.log') `
        -PassThru
    Set-Content -LiteralPath (Join-Path $runtimeDir 'api.pid') -Value $api.Id
}

$budgetWorkerPidPath = Join-Path $runtimeDir 'budget-worker.pid'
$budgetWorkerRunning = $false
if (Test-Path -LiteralPath $budgetWorkerPidPath) {
    $existingWorkerId = [int](Get-Content -LiteralPath $budgetWorkerPidPath -Raw)
    $existingWorker = Get-CimInstance Win32_Process -Filter "ProcessId = $existingWorkerId" -ErrorAction SilentlyContinue
    $budgetWorkerRunning = [bool]($existingWorker -and $existingWorker.CommandLine -like '*budget_worker.py*')
}
if (-not $budgetWorkerRunning) {
    $budgetWorker = Start-Process -FilePath $pythonPath `
        -ArgumentList @(
            "`"$(Join-Path $root 'backend\scripts\budget_worker.py')`"",
            "`"$(Join-Path $root 'backend\storage\app\private')`""
        ) `
        -WorkingDirectory (Join-Path $root 'backend') `
        -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $runtimeDir 'budget-worker.out.log') `
        -RedirectStandardError (Join-Path $runtimeDir 'budget-worker.error.log') `
        -PassThru
    Set-Content -LiteralPath $budgetWorkerPidPath -Value $budgetWorker.Id
}

$vuePortInUse = Get-NetTCPConnection -LocalPort 5173 -State Listen -ErrorAction SilentlyContinue
if (-not $vuePortInUse) {
    $vue = Start-Process -FilePath $pnpmPath `
        -ArgumentList @('dev', '--host', '127.0.0.1', '--port', '5173') `
        -WorkingDirectory (Join-Path $root 'frontend') `
        -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $runtimeDir 'vue.out.log') `
        -RedirectStandardError (Join-Path $runtimeDir 'vue.error.log') `
        -PassThru
    Set-Content -LiteralPath (Join-Path $runtimeDir 'vue.pid') -Value $vue.Id
}

$healthUrl = 'http://127.0.0.1:8000/api/v1/health'
$apiHealthy = $false
for ($attempt = 0; $attempt -lt 20; $attempt++) {
    try {
        $health = Invoke-RestMethod -Uri $healthUrl -Headers @{ Accept = 'application/json' } -TimeoutSec 3
        $apiHealthy = $health.status -eq 'ok' -and $health.database -eq 'connected'
        if ($apiHealthy) { break }
    } catch {
        Start-Sleep -Milliseconds 500
    }
}

Write-Output 'Valor ERP local services:'
Write-Output '  Frontend: http://127.0.0.1:5173'
Write-Output "  API:      $healthUrl"
if ($apiHealthy) {
    Write-Output '  Database: connected'
} else {
    Write-Warning 'Database connection failed. Local login and data pages will not work.'
    Write-Warning 'Update DB_URL in backend\.env with the current Supabase connection string, then stop and start Local again.'
    Write-Output "  Log:      $(Join-Path $root 'backend\storage\logs\laravel.log')"
}
Write-Output "  Python:   $pythonPath"
Write-Output '  Worker:   Budget queue is running'
Write-Output $(if ($ollamaPortInUse) { '  Ollama:   http://127.0.0.1:11434' } else { '  Ollama:   ไม่พร้อมใช้งาน (Vector Search ยังใช้งานได้)' })

# powershell -ExecutionPolicy Bypass -File .\scripts\start-local.ps1
