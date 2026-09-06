<#
.SYNOPSIS
    Captura um baseline de performance dos endpoints quentes do 2pets-api.

.DESCRIPTION
    Fase 0 do plano de otimização de queries: sem esta captura não há como provar ganho nas
    fases seguintes. O script:
      1. Reseta o pg_stat_statements (via docker compose exec no serviço `postgres`).
      2. Faz login como tutor/vet/admin (credenciais do DemoDataSeeder) e reusa os tokens.
      3. Chama os endpoints quentes, registrando endpoint, status HTTP, tempo total (ms) e os
         headers X-Query-Count / X-Query-Time-Ms (só existem em APP_ENV=local — ver
         QueryCountMiddleware) num CSV.
      4. Dumpa o top-50 de pg_stat_statements por total_exec_time num segundo CSV.

.PARAMETER Label
    Identifica a rodada nos nomes de arquivo (ex: "fase0-baseline", "fase4-depois").

.PARAMETER BaseUrl
    URL base da API. Default: http://localhost:8000 (porta host do serviço `nginx`).

.PARAMETER Password
    Senha compartilhada pelos 3 usuários de demo do DemoDataSeeder.

.EXAMPLE
    .\storage\perf\capture.ps1 -Label "fase0-baseline"
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $false)]
    [string]$Label = 'captura',

    [Parameter(Mandatory = $false)]
    [string]$BaseUrl = 'http://localhost:8000',

    [Parameter(Mandatory = $false)]
    [string]$Password = 'password'
)

$ErrorActionPreference = 'Stop'

# Este script vive em 2pets-api/storage/perf/. A raiz do monorepo (onde está o
# docker-compose.yml e onde `docker compose exec` precisa rodar) fica 3 níveis acima.
$repoRoot = (Get-Item $PSScriptRoot).Parent.Parent.Parent.FullName
$outputDir = $PSScriptRoot
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$endpointsCsvPath = Join-Path $outputDir "$timestamp-$Label-endpoints.csv"
$statementsCsvPath = Join-Path $outputDir "$timestamp-$Label-pg-stat-statements.csv"

function Reset-PgStatStatements {
    Write-Host 'Resetando pg_stat_statements...' -ForegroundColor Cyan

    Push-Location $repoRoot
    try {
        docker compose exec -T postgres psql -U twopets -d twopets -c 'SELECT pg_stat_statements_reset();' | Out-Null
    } finally {
        Pop-Location
    }
}

function Clear-ApplicationCache {
    # OBRIGATORIO antes de medir. O ProfessionalSearchService cacheia o resultado
    # da busca no Redis por 5 minutos; sem limpar, a segunda captura mede um cache
    # hit e reporta "X-Query-Count: 0" — numero bonito e completamente inutil para
    # comparar antes/depois. Ja aconteceu na captura fase0-baseline-v2.
    Write-Host 'Limpando cache da aplicacao (Redis)...' -ForegroundColor Cyan

    Push-Location $repoRoot
    try {
        docker compose exec -T backend php artisan cache:clear | Out-Null
    } finally {
        Pop-Location
    }
}

function Get-AuthToken {
    param([Parameter(Mandatory)][string]$Email)

    $body = @{ email = $Email; password = $Password } | ConvertTo-Json
    $response = Invoke-RestMethod -Uri "$BaseUrl/api/login" -Method Post -Body $body `
        -ContentType 'application/json' -UseBasicParsing

    if (-not $response.access_token) {
        throw "Login falhou para '$Email' — sem access_token na resposta."
    }

    return $response.access_token
}

function Get-HeaderValue {
    param($Headers, [Parameter(Mandatory)][string]$Name)

    if ($null -eq $Headers) {
        return ''
    }

    try {
        $value = $Headers[$Name]
        if ($null -ne $value) {
            return ($value -join ',')
        }
    } catch {
        # Alguns tipos de coleção de header (HttpResponseHeaders) não suportam indexador.
    }

    try {
        return ($Headers.GetValues($Name) -join ',')
    } catch {
        return ''
    }
}

function Invoke-Endpoint {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][string]$Path,
        [string]$Token = $null
    )

    $headers = @{ Accept = 'application/json' }
    if ($Token) {
        $headers['Authorization'] = "Bearer $Token"
    }

    $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
    $statusCode = $null
    $queryCount = ''
    $queryTimeMs = ''

    try {
        $response = Invoke-WebRequest -Uri "$BaseUrl$Path" -Headers $headers -Method Get -UseBasicParsing
        $statusCode = [int]$response.StatusCode
        $queryCount = Get-HeaderValue -Headers $response.Headers -Name 'X-Query-Count'
        $queryTimeMs = Get-HeaderValue -Headers $response.Headers -Name 'X-Query-Time-Ms'
    } catch {
        $errorResponse = $_.Exception.Response
        if ($null -ne $errorResponse) {
            $statusCode = [int]$errorResponse.StatusCode
            $queryCount = Get-HeaderValue -Headers $errorResponse.Headers -Name 'X-Query-Count'
            $queryTimeMs = Get-HeaderValue -Headers $errorResponse.Headers -Name 'X-Query-Time-Ms'
        } else {
            $statusCode = 'ERRO'
        }
    }

    $stopwatch.Stop()

    return [PSCustomObject]@{
        Endpoint    = $Name
        Path        = $Path
        StatusCode  = $statusCode
        # Formatado com cultura invariante — o locale do host (pt-BR) usa vírgula como
        # separador decimal, o que quebraria qualquer parsing numérico do CSV depois.
        TotalTimeMs = $stopwatch.Elapsed.TotalMilliseconds.ToString('0.00', [System.Globalization.CultureInfo]::InvariantCulture)
        QueryCount  = $queryCount
        QueryTimeMs = $queryTimeMs
    }
}

function Get-HotEndpoints {
    param(
        [Parameter(Mandatory)][string]$TutorToken,
        [Parameter(Mandatory)][string]$VetToken,
        [Parameter(Mandatory)][string]$AdminToken
    )

    $lat = -23.5505
    $lng = -46.6333

    return @(
        # Busca pública — com/sem localização, com/sem termo (routes/api.php:83)
        @{ Name = 'search-sem-local-sem-termo'; Path = '/api/public/search' }
        @{ Name = 'search-com-local-sem-termo'; Path = "/api/public/search?latitude=$lat&longitude=$lng&radius_km=20" }
        @{ Name = 'search-sem-local-com-termo'; Path = '/api/public/search?query=veterinario' }
        @{ Name = 'search-com-local-com-termo'; Path = "/api/public/search?latitude=$lat&longitude=$lng&radius_km=20&query=veterinario" }

        @{ Name = 'public-featured'; Path = '/api/public/featured' }
        @{ Name = 'public-nearby'; Path = "/api/public/nearby?latitude=$lat&longitude=$lng&radius_km=20" }

        # Master data (routes/api.php:91-97) + breeds
        @{ Name = 'master-data-pathologies'; Path = '/api/public/pathologies' }
        @{ Name = 'master-data-vaccine-catalog'; Path = '/api/public/vaccine-catalog' }
        @{ Name = 'master-data-food-brands'; Path = '/api/public/food-brands' }
        @{ Name = 'master-data-specialties'; Path = '/api/public/specialties' }
        @{ Name = 'master-data-food-allergies'; Path = '/api/public/food-allergies' }
        @{ Name = 'master-data-dietary-restrictions'; Path = '/api/public/dietary-restrictions' }
        @{ Name = 'breeds'; Path = '/api/public/breeds' }

        # Dashboards e listagens autenticadas
        @{ Name = 'admin-dashboard-stats'; Path = '/api/admin/dashboard/stats'; Token = $AdminToken }
        @{ Name = 'professional-dashboard-stats'; Path = '/api/professional/dashboard/stats'; Token = $VetToken }
        @{ Name = 'tutor-dashboard-stats'; Path = '/api/dashboard/stats'; Token = $TutorToken }
        @{ Name = 'ai-business-insights'; Path = '/api/professional/ai/insights'; Token = $VetToken }
        @{ Name = 'professional-appointments'; Path = '/api/professional/appointments'; Token = $VetToken }
        @{ Name = 'professional-medical-records'; Path = '/api/professional/medical-records'; Token = $VetToken }
        @{ Name = 'professional-clients'; Path = '/api/professional/clients'; Token = $VetToken }
        @{ Name = 'favorites'; Path = '/api/favorites'; Token = $TutorToken }
        @{ Name = 'notifications'; Path = '/api/notifications'; Token = $TutorToken }
    )
}

function Export-EndpointResults {
    param(
        [Parameter(Mandatory)][string]$TutorToken,
        [Parameter(Mandatory)][string]$VetToken,
        [Parameter(Mandatory)][string]$AdminToken
    )

    $results = Get-HotEndpoints -TutorToken $TutorToken -VetToken $VetToken -AdminToken $AdminToken |
        ForEach-Object { Invoke-Endpoint -Name $_.Name -Path $_.Path -Token $_.Token }

    $results | Export-Csv -Path $endpointsCsvPath -NoTypeInformation -Encoding UTF8
    Write-Host "Endpoints capturados em: $endpointsCsvPath" -ForegroundColor Green
}

function Export-TopStatements {
    Write-Host 'Exportando top-50 de pg_stat_statements...' -ForegroundColor Cyan

    $copySql = 'COPY (SELECT query, calls, total_exec_time, mean_exec_time, min_exec_time, ' +
        'max_exec_time, rows FROM pg_stat_statements ORDER BY total_exec_time DESC LIMIT 50) ' +
        'TO STDOUT WITH CSV HEADER'

    Push-Location $repoRoot
    try {
        docker compose exec -T postgres psql -U twopets -d twopets -c $copySql |
            Out-File -FilePath $statementsCsvPath -Encoding utf8
    } finally {
        Pop-Location
    }

    Write-Host "Top-50 pg_stat_statements em: $statementsCsvPath" -ForegroundColor Green
}

Write-Host "=== Captura de performance — label '$Label' ===" -ForegroundColor Yellow

Clear-ApplicationCache
Reset-PgStatStatements

Write-Host 'Autenticando usuários de demo...' -ForegroundColor Cyan
$tutorToken = Get-AuthToken -Email 'tutor@2pets.com.br'
$vetToken = Get-AuthToken -Email 'vet@2pets.com.br'
$adminToken = Get-AuthToken -Email 'admin@2pets.com.br'

Export-EndpointResults -TutorToken $tutorToken -VetToken $vetToken -AdminToken $adminToken
Export-TopStatements

Write-Host '=== Captura concluída ===' -ForegroundColor Yellow
