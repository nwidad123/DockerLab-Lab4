$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent

function Invoke-Docker {
    param([string[]] $DockerArguments)

    & docker @DockerArguments

    if ($LASTEXITCODE -ne 0) {
        throw "Docker command failed: docker $($DockerArguments -join ' ')"
    }
}

Push-Location $projectRoot

try {
    if (-not (Test-Path .\.env)) {
        throw 'Missing .env. Copy .env.example to .env and fill in your settings.'
    }

    Invoke-Docker -DockerArguments @(
        'compose', 'config', '--quiet'
    )

    Invoke-Docker -DockerArguments @(
        'compose', 'up', '-d', '--build',
        '--wait', '--wait-timeout', '180'
    )

    & powershell -NoProfile -ExecutionPolicy Bypass `
        -File (Join-Path $PSScriptRoot 'Test-Php.ps1')

    if ($LASTEXITCODE -ne 0) {
        throw 'PHP syntax checks failed.'
    }

    Invoke-Docker -DockerArguments @(
        'cp', './tests/authenticator.php',
        'lab_php:/tmp/lab4-authenticator.php'
    )

    Invoke-Docker -DockerArguments @(
        'exec', 'lab_php', 'php',
        '/tmp/lab4-authenticator.php',
        '/usr/share/nginx/html/GoogleAuthenticator.php'
    )

    $ready = $false

    for ($attempt = 1; $attempt -le 30; $attempt++) {
        try {
            $response = Invoke-WebRequest `
                -Uri 'http://localhost:8080/login.php' `
                -UseBasicParsing `
                -TimeoutSec 5

            if ($response.StatusCode -eq 200) {
                $ready = $true
                break
            }
        } catch {
            # Allow the web server time to finish starting.
        }

        Start-Sleep -Seconds 2
    }

    if (-not $ready) {
        throw 'The website did not become ready.'
    }

    Invoke-Docker -DockerArguments @(
        'cp', './tests/smoke.php',
        'lab_php:/tmp/lab4-smoke.php'
    )

    Invoke-Docker -DockerArguments @(
        'exec', 'lab_php', 'php',
        '/tmp/lab4-smoke.php', 'http://web-server'
    )

    Write-Host "`nPASS: Application started and all checks passed." `
        -ForegroundColor Green
    Write-Host 'Open http://localhost:8080'
} catch {
    Write-Host "`nFAIL: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
} finally {
    Pop-Location
}