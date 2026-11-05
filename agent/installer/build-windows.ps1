# OSG Agent v6.1.0 - Windows x64 build with CPython 3.15.
# Compatible with Windows PowerShell 5.1 and PowerShell 7.
# Run this script from the trusted project sources; never alter machine-wide execution policy.
[CmdletBinding()]
param(
    [string]$IsccPath
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$agent = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $agent

if (-not (Get-Command py -ErrorAction SilentlyContinue)) {
    throw 'No se encontro py.exe. Comprueba el lanzador con: py -0p'
}

# Avoid embedded quoted strings in Python -c: Windows PowerShell 5.1 can strip
# nested double quotes when forwarding native-process arguments.
& py -3.15 -c 'import sys; assert sys.version_info[:2] == (3,15) and sys.maxsize > 2**32'
if ($LASTEXITCODE -ne 0) {
    throw 'Se requiere CPython 3.15 de 64 bits. Verifica: py -3.15 --version y py -0p'
}

$venv = Join-Path $agent '.buildenv315'
$python = Join-Path $venv 'Scripts\python.exe'
if (-not (Test-Path -LiteralPath $python -PathType Leaf)) {
    Write-Host 'Creando entorno de compilacion Python 3.15...'
    & py -3.15 -m venv $venv
    if ($LASTEXITCODE -ne 0) {
        throw 'Fallo al crear el entorno virtual. Revisa el mensaje de Python anterior.'
    }
}
if (-not (Test-Path -LiteralPath $python -PathType Leaf)) {
    throw "No existe el interprete de compilacion: $python"
}

& $python -c 'import sys; assert sys.version_info[:2] == (3,15) and sys.maxsize > 2**32'
if ($LASTEXITCODE -ne 0) {
    throw "El entorno $venv es incompatible. No se modifica automaticamente: revisalo antes de continuar."
}

Write-Host 'Instalando dependencias de compilacion...'
& $python -m pip install --disable-pip-version-check -r requirements-windows.txt 'pyinstaller>=6.21,<7'
if ($LASTEXITCODE -ne 0) { throw 'pip fallo. Revisa la salida anterior.' }

Write-Host 'Ejecutando pruebas Python...'
& $python -m unittest discover -s tests -v
if ($LASTEXITCODE -ne 0) { throw 'Fallaron las pruebas Python. Compilacion cancelada.' }

Write-Host 'Comprobando dependencias Windows (notificaciones y sesiones)...'
& $python -c 'import win32ts, win32security, win32api'
if ($LASTEXITCODE -ne 0) { throw 'Faltan APIs pywin32 usadas por el canal interactivo.' }

Write-Host 'Empaquetando servicio...'
& $python -m PyInstaller --noconfirm --clean --onedir --console --hidden-import win32ts --hidden-import win32security --hidden-import win32api --name NodoControlAgent agent_entry.py
if ($LASTEXITCODE -ne 0) { throw 'No se pudo empaquetar el servicio.' }

Write-Host 'Empaquetando notificador de sesión (sin privilegios de administrador)...'
& $python -m PyInstaller --noconfirm --clean --onedir --windowed --name OSGNotifier notifier.py
if ($LASTEXITCODE -ne 0) { throw 'No se pudo empaquetar el notificador.' }

Write-Host 'Empaquetando asistente...' 
& $python -m PyInstaller --noconfirm --clean --onedir --windowed --uac-admin --name NodoControlConfig config_wizard.py
if ($LASTEXITCODE -ne 0) { throw 'No se pudo empaquetar el asistente.' }

$iss = Join-Path $PSScriptRoot 'NodoControl.iss'
if (-not (Test-Path -LiteralPath $iss -PathType Leaf)) {
    throw "No se encontro el archivo de Inno Setup: $iss"
}

# Inno Setup 7 (preferred) or 6. Do not require PATH changes or downgrade.
# An explicit -IsccPath also supports custom installations.
$iscc = $null
if (-not [string]::IsNullOrWhiteSpace($IsccPath)) {
    if (-not (Test-Path -LiteralPath $IsccPath -PathType Leaf)) {
        throw "ISCC.exe no existe en la ruta indicada: $IsccPath"
    }
    $iscc = (Resolve-Path -LiteralPath $IsccPath).Path
} else {
    $roots = @()
    if ($env:ProgramFiles) { $roots += $env:ProgramFiles }
    if (${env:ProgramFiles(x86)}) { $roots += ${env:ProgramFiles(x86)} }
    if ($env:LOCALAPPDATA) { $roots += (Join-Path $env:LOCALAPPDATA 'Programs') }

    # Prefer v7 even if v6 is installed in another program-files root.
    foreach ($version in @(7, 6)) {
        foreach ($root in $roots) {
            $candidate = Join-Path $root ("Inno Setup $version\ISCC.exe")
            if (Test-Path -LiteralPath $candidate -PathType Leaf) {
                $iscc = $candidate
                break
            }
        }
        if ($null -ne $iscc) { break }
    }

    if ($null -eq $iscc) {
        $tool = Get-Command ISCC.exe -ErrorAction SilentlyContinue
        if ($null -ne $tool) { $iscc = $tool.Source }
    }
}
if ($null -eq $iscc) {
    throw 'No se encontro ISCC.exe de Inno Setup 7 o 6. Usa -IsccPath con la ruta completa a ISCC.exe.'
}
Write-Host "Compilador Inno Setup: $iscc"

Write-Host 'Generando instalador Inno Setup...'
& $iscc $iss
if ($LASTEXITCODE -ne 0) { throw 'Inno Setup devolvio un error. Revisa la salida anterior.' }

$output = Join-Path $agent 'dist-installer\NodoControl-Agent-Setup.exe'
if (-not (Test-Path -LiteralPath $output -PathType Leaf)) {
    throw "Inno Setup no genero el archivo esperado: $output"
}
& $python -m pip freeze | Out-File -Encoding utf8 (Join-Path $agent 'dist-installer\build-dependencies.txt')
Get-FileHash -LiteralPath $output -Algorithm SHA256 | Format-List
Write-Host "Instalador generado: $output"
