# Optional, HTTP-only lab access from Windows to a Docker Engine inside Debian WSL2.
# Run in elevated PowerShell. WSL NAT IP may change after restart: rerun script.
# Usage: .\scripts\docker\windows-lan.ps1 -Port 8088 -AllowInsecureHttp
param(
  [ValidateRange(1024,65535)][int]$Port = 8088,
  [switch]$AllowInsecureHttp,
  [switch]$ReplaceExisting
)
$ErrorActionPreference = 'Stop'
if (-not $AllowInsecureHttp) { throw 'HTTP sin cifrar: indicar -AllowInsecureHttp SOLO para laboratorio privado.' }
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
  throw 'Ejecuta PowerShell como Administrador.'
}
$linuxIp = (& wsl.exe -d Debian -- sh -lc 'hostname -I' | Out-String).Trim().Split(' ',[System.StringSplitOptions]::RemoveEmptyEntries)[0]
if (-not $linuxIp -or $linuxIp -notmatch '^\d+\.\d+\.\d+\.\d+$') { throw 'No se pudo detectar IPv4 de Debian WSL2.' }
# Refuse to overwrite another tool's existing portproxy mapping by default.
$rules = (& netsh.exe interface portproxy show v4tov4 | Out-String)
$existing = $rules -match "(?m)^\s*0\.0\.0\.0\s+$Port\s+"
if ($existing -and -not $ReplaceExisting) {
  throw "Ya existe una redirección portproxy en 0.0.0.0:$Port. Revisa 'netsh interface portproxy show v4tov4'; para reemplazarla explícitamente usa -ReplaceExisting."
}
if ($existing) {
  & netsh.exe interface portproxy delete v4tov4 "listenport=$Port" 'listenaddress=0.0.0.0' | Out-Host
}
& netsh.exe interface portproxy add v4tov4 "listenport=$Port" 'listenaddress=0.0.0.0' "connectport=$Port" "connectaddress=$linuxIp" | Out-Host
$ruleName = "OSG Nodo Control LAB HTTP $Port"
Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue | Remove-NetFirewallRule
New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Protocol TCP -LocalPort $Port -RemoteAddress LocalSubnet -Profile Private -Action Allow | Out-Null
Write-Host "Redirección HTTP configurada a Debian WSL2 $linuxIp`:$Port. Solo perfiles privados y subred local."
Write-Host 'Vuelve a ejecutar tras reiniciar WSL2; el servicio IP Helper de Windows debe estar activo.'
