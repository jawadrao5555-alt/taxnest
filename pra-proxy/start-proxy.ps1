$port = 8787
$authSecret = "TaxNestPraProxy2026Secret"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  TaxNest PRA Proxy Server" -ForegroundColor Cyan
Write-Host "  Port: $port" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls11

$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add("http://+:$port/")

try {
    $listener.Start()
} catch {
    Write-Host "Port $port blocked. Trying localhost only..." -ForegroundColor Yellow
    $listener = New-Object System.Net.HttpListener
    $listener.Prefixes.Add("http://localhost:$port/")
    $listener.Start()
}

Write-Host "Proxy running on http://localhost:$port" -ForegroundColor Green
Write-Host "Waiting for requests... (Press Ctrl+C to stop)" -ForegroundColor Yellow
Write-Host ""

while ($listener.IsListening) {
    try {
        $context = $listener.GetContext()
        $request = $context.Request
        $response = $context.Response

        $response.Headers.Add("Access-Control-Allow-Origin", "*")
        $response.Headers.Add("Access-Control-Allow-Methods", "POST, OPTIONS")
        $response.Headers.Add("Access-Control-Allow-Headers", "Content-Type, X-Proxy-Auth, X-Pra-Url, X-Pra-Token")

        if ($request.HttpMethod -eq "OPTIONS") {
            $response.StatusCode = 200
            $response.Close()
            continue
        }

        $auth = $request.Headers["X-Proxy-Auth"]
        if ($auth -ne $authSecret) {
            $errorBytes = [System.Text.Encoding]::UTF8.GetBytes('{"error":"Unauthorized"}')
            $response.StatusCode = 403
            $response.ContentType = "application/json"
            $response.OutputStream.Write($errorBytes, 0, $errorBytes.Length)
            $response.Close()
            Write-Host "$(Get-Date -Format 'HH:mm:ss') BLOCKED - Invalid auth" -ForegroundColor Red
            continue
        }

        $praUrl = $request.Headers["X-Pra-Url"]
        if (-not $praUrl) { $praUrl = "https://ims.pral.com.pk/ims/production/api/Live/PostData" }
        $praToken = $request.Headers["X-Pra-Token"]

        $reader = New-Object System.IO.StreamReader($request.InputStream)
        $body = $reader.ReadToEnd()
        $reader.Close()

        Write-Host "$(Get-Date -Format 'HH:mm:ss') >> Request to PRA: $praUrl" -ForegroundColor Cyan

        try {
            $headers = @{
                "Content-Type" = "application/json"
                "Accept" = "application/json"
            }
            if ($praToken) {
                $headers["Authorization"] = "Bearer $praToken"
            }

            $praResponse = Invoke-RestMethod -Uri $praUrl -Method POST -Body $body -Headers $headers -ContentType "application/json" -TimeoutSec 30

            $jsonResponse = $praResponse | ConvertTo-Json -Depth 10
            $responseBytes = [System.Text.Encoding]::UTF8.GetBytes($jsonResponse)
            $response.StatusCode = 200
            $response.ContentType = "application/json"
            $response.OutputStream.Write($responseBytes, 0, $responseBytes.Length)

            Write-Host "$(Get-Date -Format 'HH:mm:ss') << PRA Response: $jsonResponse" -ForegroundColor Green
        } catch {
            $errMsg = $_.Exception.Message
            $errorJson = @{
                Code = "500"
                Response = "Proxy PRA Error: $errMsg"
                InvoiceNumber = "Not Available"
            } | ConvertTo-Json

            $errorBytes = [System.Text.Encoding]::UTF8.GetBytes($errorJson)
            $response.StatusCode = 502
            $response.ContentType = "application/json"
            $response.OutputStream.Write($errorBytes, 0, $errorBytes.Length)

            Write-Host "$(Get-Date -Format 'HH:mm:ss') !! PRA Error: $errMsg" -ForegroundColor Red
        }

        $response.Close()

    } catch [System.Exception] {
        if ($_.Exception.Message -notlike "*thread exit*") {
            Write-Host "Error: $($_.Exception.Message)" -ForegroundColor Red
        }
    }
}
