#define AppName "OSG Agent"
#define AppVersion "6.1.0"
[Setup]
AppId={{D76CA596-65C5-4B1C-8D59-31AFC49D02B2}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher=Ignacio Sanguina
DefaultDirName={autopf}\NodoControl
DisableProgramGroupPage=yes
PrivilegesRequired=admin
MinVersion=10.0
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
WizardStyle=modern
OutputDir=..\dist-installer
OutputBaseFilename=NodoControl-Agent-Setup
Compression=lzma2
SolidCompression=yes
UninstallDisplayName={#AppName}
AppPublisherURL=https://osgsecurity.com.ar
CloseApplications=yes
RestartApplications=no

[Languages]
Name: "spanish"; MessagesFile: "compiler:Languages\Spanish.isl"

[Files]
Source: "..\dist\NodoControlAgent\*"; DestDir: "{app}\Agent"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\dist\NodoControlConfig\*"; DestDir: "{app}\Config"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\dist\OSGNotifier\*"; DestDir: "{app}\Agent\Notifier"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\Configurar OSG Agent"; Filename: "{app}\Config\NodoControlConfig.exe"
Name: "{group}\Desinstalar OSG Agent"; Filename: "{uninstallexe}"

[Run]
Filename: "{app}\Agent\NodoControlAgent.exe"; Parameters: "install"; Flags: runhidden waituntilterminated; Check: HasExistingConfig
Filename: "{app}\Agent\NodoControlAgent.exe"; Parameters: "start"; Flags: runhidden waituntilterminated; Check: HasExistingConfig
Filename: "{app}\Config\NodoControlConfig.exe"; Description: "Vincular equipo con OSG Agent"; Flags: postinstall skipifsilent runascurrentuser; Check: not HasExistingConfig

[UninstallRun]
Filename: "{app}\Agent\NodoControlAgent.exe"; Parameters: "uninstall"; Flags: runhidden waituntilterminated

[Code]
function HasExistingConfig(): Boolean;
begin
  Result := FileExists(ExpandConstant('{commonappdata}\NodoControl\agent.json'));
end;

// Never overwrite binaries while SCM still reports the service as running.
// Query a structured ServiceController status, not localized sc.exe output.
function ServiceStopped(): Boolean;
var
  ExitCode: Integer;
  CommandLine: String;
begin
  CommandLine := '-NoProfile -NonInteractive -Command ' +
    '"$s=Get-Service -Name ''NodoControlAgent'' -ErrorAction SilentlyContinue; ' +
    'if ($null -eq $s -or $s.Status -eq ''Stopped'') { exit 0 }; exit 1"';
  Result := Exec(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'),
    CommandLine, '', SW_HIDE, ewWaitUntilTerminated, ExitCode) and (ExitCode = 0);
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  StatusCode: Integer;
  StopCode: Integer;
  Attempts: Integer;
begin
  Result := '';
  if Exec(ExpandConstant('{sys}\sc.exe'), 'query NodoControlAgent', '',
          SW_HIDE, ewWaitUntilTerminated, StatusCode) and (StatusCode = 0) then
  begin
    if not ServiceStopped() then
    begin
      if not Exec(ExpandConstant('{sys}\sc.exe'), 'stop NodoControlAgent', '',
                  SW_HIDE, ewWaitUntilTerminated, StopCode) then
      begin
        Result := 'No fue posible enviar la solicitud de detención al servicio.';
        Exit;
      end;
      // sc.exe returning success means a stop was requested, not completed.
      // The status is verified below, even when SCM reports 1062.
      if (StopCode <> 0) and (StopCode <> 1062) then
      begin
        Result := 'No se pudo detener el servicio anterior. Revisá el Visor de eventos.';
        Exit;
      end;
    end;
    for Attempts := 1 to 30 do
    begin
      if ServiceStopped() then Exit;
      Sleep(1000);
    end;
    Result := 'El servicio NodoControlAgent no se detuvo en 30 segundos. ' +
      'Se canceló la actualización antes de reemplazar los archivos.';
  end;
end;

// Data in ProgramData is retained on uninstall for deliberate administrative
// cleanup; this avoids unexpected destruction of telemetry and credentials.
