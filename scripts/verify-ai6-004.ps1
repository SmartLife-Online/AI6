[CmdletBinding()]
param(
    [switch]$Quick,
    [string]$RuntimeUrl = ''
)

$ErrorActionPreference = 'Stop'
$script:FailureCount = 0
$script:PreviousLocation = Get-Location
$repositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path

function Write-CheckHeader {
    param([Parameter(Mandatory)][string]$Name)

    Write-Host "`n==> $Name" -ForegroundColor Cyan
}

function Write-CheckResult {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][bool]$Passed,
        [string]$Detail = ''
    )

    if ($Passed) {
        Write-Host "[OK] $Name" -ForegroundColor Green
    } else {
        Write-Host "[FEHLER] $Name" -ForegroundColor Red
        $script:FailureCount++
    }

    if ($Detail -ne '') {
        Write-Host "       $Detail"
    }
}

function Invoke-CommandCheck {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][string]$Command,
        [Parameter(Mandatory)][string[]]$Arguments
    )

    Write-CheckHeader -Name $Name

    try {
        & $Command @Arguments
        $exitCode = $LASTEXITCODE
        Write-CheckResult -Name $Name -Passed ($exitCode -eq 0) -Detail "Exitcode: $exitCode"
    } catch {
        Write-CheckResult -Name $Name -Passed $false -Detail $_.Exception.Message
    }
}

function Invoke-HttpStatusCheck {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][string]$Url,
        [Parameter(Mandatory)][int]$ExpectedStatus,
        [switch]$RequireHealthBody
    )

    Write-CheckHeader -Name $Name
    $temporaryFile = [System.IO.Path]::GetTempFileName()

    try {
        $statusOutput = & curl.exe `
            --silent `
            --show-error `
            --max-time 20 `
            --output $temporaryFile `
            --write-out '%{http_code}' `
            $Url
        $curlExitCode = $LASTEXITCODE
        $statusText = ($statusOutput | Out-String).Trim()
        $statusCode = 0
        $hasStatusCode = [int]::TryParse($statusText, [ref]$statusCode)
        $passed = $curlExitCode -eq 0 -and $hasStatusCode -and $statusCode -eq $ExpectedStatus
        $detail = "Erwartet: $ExpectedStatus; erhalten: $statusText; curl-Exitcode: $curlExitCode"

        if ($passed -and $RequireHealthBody) {
            try {
                $health = Get-Content -Raw -LiteralPath $temporaryFile | ConvertFrom-Json
                $passed = $health.status -eq 'ok'

                if (-not $passed) {
                    $detail += '; Health-Body enthaelt nicht status=ok'
                }
            } catch {
                $passed = $false
                $detail += '; Health-Body ist kein gueltiges JSON'
            }
        }

        Write-CheckResult -Name $Name -Passed $passed -Detail $detail
    } finally {
        Remove-Item -Force -LiteralPath $temporaryFile -ErrorAction SilentlyContinue
    }
}

try {
    Set-Location $repositoryRoot

    if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
        throw 'PHP wurde nicht im PATH gefunden.'
    }

    if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
        throw 'Composer wurde nicht im PATH gefunden.'
    }

    if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
        throw 'Git wurde nicht im PATH gefunden.'
    }

    Invoke-CommandCheck `
        -Name 'AI6-004-Tickettests' `
        -Command 'php' `
        -Arguments @('artisan', 'test', 'tests/Unit/Auth', 'tests/Feature/Auth', 'tests/Feature/Projects')

    if (-not $Quick) {
        Invoke-CommandCheck `
            -Name 'Vollstaendige PHPUnit-Suite' `
            -Command 'php' `
            -Arguments @('artisan', 'test')
    } else {
        Write-Host "`n[UEBERSPRUNGEN] Vollstaendige PHPUnit-Suite (-Quick wurde verwendet)." -ForegroundColor Yellow
    }

    Invoke-CommandCheck `
        -Name 'Pint-Formatpruefung' `
        -Command 'php' `
        -Arguments @('vendor/bin/pint', '--test')
    Invoke-CommandCheck `
        -Name 'PHPStan' `
        -Command 'php' `
        -Arguments @('-d', 'memory_limit=512M', 'vendor/bin/phpstan', 'analyse')
    Invoke-CommandCheck `
        -Name 'Composer-Vertrag' `
        -Command 'composer' `
        -Arguments @('validate', '--strict')
    Invoke-CommandCheck `
        -Name 'Ticketmanifest' `
        -Command 'php' `
        -Arguments @('scripts/generate-ticket-manifest.php', '--check')
    Invoke-CommandCheck `
        -Name 'Git-Diff-Pruefung' `
        -Command 'git' `
        -Arguments @('diff', '--check')

    Write-CheckHeader -Name 'Offene AI6-004-Vertragsstellen'
    $compose = Get-Content -Raw -LiteralPath 'docker-compose.yml' | ConvertFrom-Json
    $sessionDriver = [string]$compose.services.app.environment.SESSION_DRIVER
    Write-CheckResult `
        -Name 'Compose verwendet Datenbank-Sessions' `
        -Passed ($sessionDriver -eq 'database') `
        -Detail "services.app.environment.SESSION_DRIVER=$sessionDriver"

    $sharedParser = 'app/AI6/Shared/Config/StrictPositiveIntegerParser.php'
    Write-CheckResult `
        -Name 'Strikter Integerparser liegt im Shared-Konfigurationsmodul' `
        -Passed (Test-Path -LiteralPath $sharedParser) `
        -Detail $sharedParser

    if ($RuntimeUrl -ne '') {
        if (-not (Get-Command curl.exe -ErrorAction SilentlyContinue)) {
            throw 'curl.exe wurde fuer die Laufzeitpruefung nicht gefunden.'
        }

        $parsedRuntimeUrl = $null
        $isValidRuntimeUrl = [Uri]::TryCreate($RuntimeUrl, [UriKind]::Absolute, [ref]$parsedRuntimeUrl) `
            -and $parsedRuntimeUrl.Scheme -in @('http', 'https')

        if (-not $isValidRuntimeUrl) {
            throw 'RuntimeUrl muss eine absolute HTTP- oder HTTPS-Adresse sein.'
        }

        $baseUrl = $RuntimeUrl.TrimEnd('/')
        Invoke-HttpStatusCheck -Name 'Health-Endpunkt' -Url "$baseUrl/health" -ExpectedStatus 200 -RequireHealthBody
        Invoke-HttpStatusCheck -Name 'Keine oeffentliche Registrierung' -Url "$baseUrl/register" -ExpectedStatus 404
        Invoke-HttpStatusCheck -Name 'Keine Passwort-vergessen-Route' -Url "$baseUrl/forgot-password" -ExpectedStatus 404
        Invoke-HttpStatusCheck -Name 'Keine Passwort-Reset-Route' -Url "$baseUrl/reset-password/example-token" -ExpectedStatus 404
        Invoke-HttpStatusCheck -Name 'Keine E-Mail-Verifizierungsroute' -Url "$baseUrl/email/verify" -ExpectedStatus 404
    } else {
        Write-Host "`n[HINWEIS] Keine RuntimeUrl angegeben; HTTP-Pruefungen wurden uebersprungen." -ForegroundColor Yellow
    }
} catch {
    Write-Host "`n[ABBRUCH] $($_.Exception.Message)" -ForegroundColor Red
    $script:FailureCount++
} finally {
    Set-Location $script:PreviousLocation
}

if ($script:FailureCount -eq 0) {
    Write-Host "`nAI6-004-Pruefung erfolgreich." -ForegroundColor Green
    exit 0
}

Write-Host "`nAI6-004-Pruefung mit $script:FailureCount Fehler(n) beendet." -ForegroundColor Red
exit 1
