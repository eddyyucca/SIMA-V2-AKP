; SIMA Asset Agent — installer resmi (Inno Setup 6.5+)
;
; Memasang skrip agent (PowerShell, teks biasa — lihat ../agent-client/README.md) sebagai Scheduled Task Windows
; bernama jelas "SIMA Asset Agent", aplikasi status (admin), dan SIMA Agent Helper (pengumuman + chat dari IT, tanpa UAC).
; Tiga bahasa: Indonesia (bawaan), Inggris, Korea. Lihat ../docs/rencana-agent-monitoring-aset.md untuk rencana lengkap.
;
; Build (disarankan, membangun semuanya sekaligus):   powershell -ExecutionPolicy Bypass -File build-installer.ps1
; Atau manual setelah status-app\build.ps1 dan helper-app\build.ps1:   ISCC.exe SIMA-Asset-Agent.iss
; Hasilnya: dist\SIMA-Asset-Agent-Setup.exe
;
; PENTING: berkas ini dan info-before.*.txt disimpan sebagai UTF-8 DENGAN BOM supaya teks Korea terbaca benar.

#ifndef MyAppVersion
  #define MyAppVersion "8.0.0"
#endif
#define MyAppName "SIMA Asset Agent"
#define MyAppPublisher "PT Adhi Kartiko Pratama Tbk"
#define MyAppExeDescription "SIMA Asset Monitoring Agent"

[Setup]
; GUID tetap (jangan diubah) supaya versi berikutnya meng-upgrade di tempat, bukan terpasang dobel.
AppId={{CDF7405E-E73B-4B86-8ECF-F4CAFB439038}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppVerName={#MyAppName} {#MyAppVersion}
VersionInfoVersion={#MyAppVersion}.0
VersionInfoProductName={#MyAppName}
VersionInfoDescription={#MyAppExeDescription}
VersionInfoCompany={#MyAppPublisher}
VersionInfoCopyright=(c) {#MyAppPublisher}

; Dipasang di Program Files (64-bit): ada aplikasi desktop "SIMA Agent Status" dan program pembantu di samping skrip agent.
; UsePreviousAppDir=no memindahkan pemasangan lama dari ProgramData ke sini (sisa folder lama dibersihkan di [InstallDelete]).
UsePreviousAppDir=no
DefaultDirName={autopf}\SIMA Asset Agent
DisableDirPage=yes
DisableProgramGroupPage=yes
DisableReadyPage=no
DisableWelcomePage=no

; --- WAJIB dijalankan sebagai Administrator ---
; PrivilegesRequired=admin membuat Windows menampilkan dialog UAC SEBELUM installer ini sempat terbuka;
; kalau pengguna menolak UAC atau membuka tanpa hak admin, instalasi tidak akan berjalan sama sekali.
; PrivilegesRequiredOverridesAllowed SENGAJA tidak diisi supaya tidak ada opsi "pasang untuk saya saja"
; yang bisa melewati admin — admin benar-benar wajib, bukan pilihan.
PrivilegesRequired=admin

ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=6.1sp1

; --- Tampilan wizard: gaya modern, sedikit lebih lega, gambar bermerek AKP dalam tiga ukuran DPI ---
; (gambar dibuat oleh tools\generate-wizard-images.ps1; netral bahasa, jadi dipakai ketiga bahasa)
WizardStyle=modern
WizardSizePercent=115
WizardImageFile=assets\wizard-164.bmp,assets\wizard-246.bmp,assets\wizard-328.bmp
WizardSmallImageFile=assets\wizard-small-55.bmp,assets\wizard-small-83.bmp,assets\wizard-small-110.bmp
WizardImageStretch=yes
SetupIconFile=assets\icon.ico
UninstallDisplayIcon={app}\SIMA-Agent-Status.exe
UninstallDisplayName={#MyAppName}

; --- Bahasa: dialog pilih bahasa SELALU tampil dan Indonesia terpilih lebih dulu (bukan mengikuti bahasa Windows) ---
ShowLanguageDialog=yes
LanguageDetectionMethod=none
UsePreviousLanguage=no

Compression=lzma2/ultra64
SolidCompression=yes
OutputDir=dist
OutputBaseFilename=SIMA-Asset-Agent-Setup

; Urutan = urutan di dialog; yang PERTAMA adalah bahasa bawaan (dan sumber teks cadangan [CustomMessages]).
; Halaman penjelasan (transparansi ke karyawan) tersedia dalam tiga bahasa: apa yang dikirim dan TIDAK dikirim agent.
[Languages]
Name: "id"; MessagesFile: "languages\Indonesian.isl"; InfoBeforeFile: "info-before.id.txt"
Name: "en"; MessagesFile: "compiler:Default.isl"; InfoBeforeFile: "info-before.en.txt"
Name: "ko"; MessagesFile: "compiler:Languages\Korean.isl"; InfoBeforeFile: "info-before.ko.txt"

[Messages]
id.WelcomeLabel2=Wizard ini akan memasang [name/ver] di komputer ini.%n%nAgent melaporkan status perangkat ke server SIMA perusahaan setiap 5 menit, dan program pembantu menampilkan pengumuman serta chat dari tim IT. Semua aktivitas tercatat di log yang bisa Anda buka kapan saja.%n%nSiapkan alamat SIMA dan token agent dari halaman detail aset. Disarankan menutup aplikasi lain sebelum melanjutkan.
en.WelcomeLabel2=This wizard will install [name/ver] on this computer.%n%nThe agent reports the device status to the company's SIMA server every 5 minutes, and the helper program shows announcements and chats from the IT team. All activity is written to a log you can open at any time.%n%nHave the SIMA address and the agent token from the asset detail page ready. It is recommended that you close other applications before continuing.
ko.WelcomeLabel2=이 마법사는 [name/ver]을(를) 이 컴퓨터에 설치합니다.%n%n에이전트는 5분마다 장치 상태를 회사 SIMA 서버에 보고하며, 도우미 프로그램은 IT팀의 공지와 채팅을 표시합니다. 모든 활동은 언제든 열어볼 수 있는 로그에 기록됩니다.%n%n자산 상세 페이지에서 발급한 SIMA 주소와 에이전트 토큰을 준비하세요. 계속하기 전에 다른 응용 프로그램을 닫는 것이 좋습니다.
id.FinishedLabel=Instalasi [name] selesai.%n%nLaporan pertama dikirim beberapa detik lagi. Ikon "SIMA Agent Helper" akan muncul di baris tray, dan status agent bisa diperiksa lewat "SIMA Agent Status" (butuh kata sandi dari tim IT).
en.FinishedLabel=Setup of [name] is complete.%n%nThe first report is sent in a few seconds. The "SIMA Agent Helper" icon will appear in the tray, and the agent status can be checked with "SIMA Agent Status" (the password comes from the IT team).
ko.FinishedLabel=[name] 설치가 완료되었습니다.%n%n첫 보고서는 몇 초 후에 전송됩니다. "SIMA Agent Helper" 아이콘이 트레이에 표시되며, "SIMA Agent Status"로 에이전트 상태를 확인할 수 있습니다(비밀번호는 IT팀에서 받으세요).

[CustomMessages]
; ---- Indonesia (bawaan) ----
id.TaskDesktopIcon=Buat pintasan SIMA Agent Status di Desktop
id.TaskGroupShortcuts=Pintasan:
id.IconStatusComment=Periksa status SIMA Asset Agent di perangkat ini
id.IconLogName=Lihat Log Agent
id.IconLogComment=Buka riwayat laporan SIMA Asset Agent
id.RunNetMonitor=Lanjutkan pasang Net Monitor for Employees Pro (EduIQ) — program terpisah, baca penjelasannya di halaman sebelumnya
id.RunOpenStatus=Buka SIMA Agent Status sekarang
id.CfgTitle=Hubungkan ke SIMA
id.CfgSubtitle=Sambungkan agent ini ke server SIMA perusahaan
id.CfgIntro=Masukkan alamat SIMA dan token agent khusus untuk aset ini. Token dibuat dari halaman detail aset di SIMA (tombol "Create token" pada baris Agent Token) dan hanya ditampilkan SEKALI saat dibuat.%n%nInstaller akan memeriksa alamat dan token sebelum memasang. Kalau belum punya token, batalkan instalasi ini, buat tokennya dulu di SIMA, lalu jalankan lagi.
id.CfgUrlLabel=Alamat SIMA:
id.CfgTokenLabel=Token Agent:
id.CfgChecking=Memeriksa koneksi ke server SIMA...
id.ErrUrl=Alamat SIMA harus diisi dan diawali dengan http:// atau https://
id.ErrTokenEmpty=Token Agent wajib diisi. Buat token dari halaman detail aset di SIMA terlebih dahulu (tombol "Create token"), lalu jalankan installer ini lagi.
id.ErrTokenFormat=Token Agent yang dimasukkan sepertinya bukan token asli dari SIMA (token asli hanya berisi huruf dan angka, minimal 20 karakter, tanpa spasi/garis miring/titik dua).%n%nPastikan Anda menyalin PERSIS token yang muncul sekali di SIMA setelah klik tombol "Create token" pada halaman detail aset — bukan teks lain seperti path folder atau URL.
id.ChkTokenInvalid=Server SIMA menolak token ini (HTTP %1).%n%nPastikan token disalin persis dan belum dicabut. Bila perlu, buat token baru dari halaman detail aset di SIMA.
id.ChkUnreachable=Koneksi ke server SIMA tidak dapat dipastikan:%n%n%1%n%nInstalasi tetap bisa dilanjutkan (agent akan mencoba lagi saat jaringan tersedia), tetapi alamat atau token yang salah baru ketahuan nanti. Lanjutkan?
id.ChkUnexpected=Server menjawab dengan kode yang tidak terduga (HTTP %1). Alamat SIMA mungkin salah atau server belum diperbarui.%n%nLanjutkan tetap?
id.ReadyServer=Server SIMA:
id.ReadyToken=Token agent (4 karakter terakhir):
id.ReadySchedule=Jadwal laporan:
id.ReadyScheduleValue=Tiap 5 menit, sebagai Scheduled Task bernama "SIMA Asset Agent"
id.ReadyHelper=Program pembantu:
id.ReadyHelperValue=SIMA Agent Helper (ikon tray): pengumuman dan chat dari IT, jalan otomatis saat login
id.ErrTaskRegister=SIMA Asset Agent sudah terpasang, TAPI pendaftaran jadwal otomatis (Scheduled Task) GAGAL (kode keluar: %1). Agent TIDAK akan melapor ke SIMA sampai ini diperbaiki.%n%nCara perbaiki: buka PowerShell SEBAGAI ADMINISTRATOR, lalu jalankan:%n    cd "%2"%n    .\install.ps1%n%nBaca pesan error yang muncul — kalau masih gagal, teruskan pesan itu ke tim IT.

; ---- English ----
en.TaskDesktopIcon=Create a desktop shortcut for SIMA Agent Status
en.TaskGroupShortcuts=Shortcuts:
en.IconStatusComment=Check the status of the SIMA Asset Agent on this device
en.IconLogName=View Agent Log
en.IconLogComment=Open the SIMA Asset Agent report history
en.RunNetMonitor=Continue and install Net Monitor for Employees Pro (EduIQ) — a separate program, see the explanation on the earlier page
en.RunOpenStatus=Open SIMA Agent Status now
en.CfgTitle=Connect to SIMA
en.CfgSubtitle=Connect this agent to the company SIMA server
en.CfgIntro=Enter the SIMA address and the agent token created for this asset. The token is created from the asset detail page in SIMA (the "Create token" button on the Agent Token row) and is shown only ONCE when created.%n%nThe installer checks the address and the token before installing. If you do not have a token yet, cancel this installation, create the token in SIMA first, then run it again.
en.CfgUrlLabel=SIMA address:
en.CfgTokenLabel=Agent token:
en.CfgChecking=Checking the connection to the SIMA server...
en.ErrUrl=The SIMA address is required and must start with http:// or https://
en.ErrTokenEmpty=The agent token is required. Create a token from the asset detail page in SIMA first (the "Create token" button), then run this installer again.
en.ErrTokenFormat=The token you entered does not look like a real SIMA token (a real token contains only letters and digits, at least 20 characters, with no spaces, slashes or colons).%n%nMake sure you copy EXACTLY the token shown once in SIMA after pressing "Create token" on the asset detail page — not other text such as a folder path or a URL.
en.ChkTokenInvalid=The SIMA server rejected this token (HTTP %1).%n%nMake sure the token was copied exactly and has not been revoked. If needed, create a new token from the asset detail page in SIMA.
en.ChkUnreachable=The connection to the SIMA server could not be confirmed:%n%n%1%n%nYou can still continue (the agent will retry when the network is available), but a wrong address or token will only be noticed later. Continue?
en.ChkUnexpected=The server answered with an unexpected code (HTTP %1). The SIMA address may be wrong or the server may not be updated yet.%n%nContinue anyway?
en.ReadyServer=SIMA server:
en.ReadyToken=Agent token (last 4 characters):
en.ReadySchedule=Report schedule:
en.ReadyScheduleValue=Every 5 minutes, as a Scheduled Task named "SIMA Asset Agent"
en.ReadyHelper=Helper program:
en.ReadyHelperValue=SIMA Agent Helper (tray icon): announcements and chat from IT, starts automatically at sign-in
en.ErrTaskRegister=SIMA Asset Agent is installed, BUT registering the automatic schedule (Scheduled Task) FAILED (exit code: %1). The agent will NOT report to SIMA until this is fixed.%n%nTo fix it, open PowerShell AS ADMINISTRATOR and run:%n    cd "%2"%n    .\install.ps1%n%nRead the error message that appears — if it still fails, pass that message on to the IT team.

; ---- 한국어 ----
ko.TaskDesktopIcon=바탕 화면에 SIMA Agent Status 바로 가기 만들기
ko.TaskGroupShortcuts=바로 가기:
ko.IconStatusComment=이 장치의 SIMA Asset Agent 상태 확인
ko.IconLogName=에이전트 로그 보기
ko.IconLogComment=SIMA Asset Agent 보고 기록 열기
ko.RunNetMonitor=Net Monitor for Employees Pro(EduIQ) 계속 설치 — 별도 프로그램이며 이전 페이지의 설명을 참고하세요
ko.RunOpenStatus=지금 SIMA Agent Status 열기
ko.CfgTitle=SIMA에 연결
ko.CfgSubtitle=이 에이전트를 회사 SIMA 서버에 연결합니다
ko.CfgIntro=SIMA 주소와 이 자산을 위해 발급한 에이전트 토큰을 입력하세요. 토큰은 SIMA의 자산 상세 페이지(Agent Token 줄의 "Create token" 버튼)에서 만들며 생성 시 단 한 번만 표시됩니다.%n%n설치 프로그램은 설치 전에 주소와 토큰을 확인합니다. 아직 토큰이 없다면 이 설치를 취소하고 SIMA에서 토큰을 먼저 만든 뒤 다시 실행하세요.
ko.CfgUrlLabel=SIMA 주소:
ko.CfgTokenLabel=에이전트 토큰:
ko.CfgChecking=SIMA 서버 연결을 확인하는 중...
ko.ErrUrl=SIMA 주소를 입력해야 하며 http:// 또는 https://로 시작해야 합니다.
ko.ErrTokenEmpty=에이전트 토큰은 필수입니다. 먼저 SIMA의 자산 상세 페이지에서 토큰을 만든 뒤("Create token" 버튼) 이 설치 프로그램을 다시 실행하세요.
ko.ErrTokenFormat=입력한 토큰이 실제 SIMA 토큰처럼 보이지 않습니다(실제 토큰은 영문자와 숫자만 포함하며 20자 이상이고 공백, 슬래시, 콜론이 없습니다).%n%n자산 상세 페이지에서 "Create token"을 누른 뒤 SIMA에 한 번 표시된 토큰을 정확히 복사했는지 확인하세요. 폴더 경로나 URL 같은 다른 텍스트가 아닙니다.
ko.ChkTokenInvalid=SIMA 서버가 이 토큰을 거부했습니다(HTTP %1).%n%n토큰을 정확히 복사했고 폐기되지 않았는지 확인하세요. 필요하면 SIMA의 자산 상세 페이지에서 새 토큰을 만드세요.
ko.ChkUnreachable=SIMA 서버 연결을 확인할 수 없습니다:%n%n%1%n%n계속 진행할 수 있지만(네트워크가 연결되면 에이전트가 다시 시도합니다) 잘못된 주소나 토큰은 나중에야 알게 됩니다. 계속하시겠습니까?
ko.ChkUnexpected=서버가 예기치 않은 코드(HTTP %1)로 응답했습니다. SIMA 주소가 잘못되었거나 서버가 아직 업데이트되지 않았을 수 있습니다.%n%n그래도 계속하시겠습니까?
ko.ReadyServer=SIMA 서버:
ko.ReadyToken=에이전트 토큰(마지막 4자):
ko.ReadySchedule=보고 주기:
ko.ReadyScheduleValue=5분마다, "SIMA Asset Agent"라는 작업 스케줄러로 실행
ko.ReadyHelper=도우미 프로그램:
ko.ReadyHelperValue=SIMA Agent Helper(트레이 아이콘): IT의 공지와 채팅, 로그인 시 자동 실행
ko.ErrTaskRegister=SIMA Asset Agent는 설치되었지만 자동 일정(작업 스케줄러) 등록에 실패했습니다(종료 코드: %1). 이 문제를 해결할 때까지 에이전트는 SIMA에 보고하지 않습니다.%n%n해결 방법: PowerShell을 관리자 권한으로 열고 다음을 실행하세요:%n    cd "%2"%n    .\install.ps1%n%n표시되는 오류 메시지를 읽고, 계속 실패하면 그 메시지를 IT팀에 전달하세요.

[Tasks]
Name: "desktopicon"; Description: "{cm:TaskDesktopIcon}"; GroupDescription: "{cm:TaskGroupShortcuts}"

[InstallDelete]
; Pemasangan versi lama ada di ProgramData\SIMA\agent (termasuk config.json berisi token). Hapus supaya tidak ada
; salinan token yang tertinggal; config.json baru ditulis di {app} pada langkah ssPostInstall.
Type: filesandordirs; Name: "{commonappdata}\SIMA\agent"

[Files]
; Skrip agent asli dari agent-client/ (sudah diuji end-to-end) — bukan salinan terpisah, supaya installer
; selalu memasang versi yang sama persis dengan yang dipakai saat trial manual.
Source: "..\agent-client\agent.ps1"; DestDir: "{app}"; Flags: ignoreversion
; Pustaka pembaruan otomatis (memuat versi agent dan kunci PUBLIK penanda tangan rilis).
Source: "..\agent-client\agent-update.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\agent-client\install.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\agent-client\uninstall.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "assets\icon.ico"; DestDir: "{app}"; Flags: ignoreversion
; Aplikasi desktop pemeriksa status agen (dibangun oleh status-app\build.ps1): dijaga kata sandi, jalan sebagai Administrator.
Source: "status-app\bin\SIMA-Agent-Status.exe"; DestDir: "{app}"; Flags: ignoreversion
; SIMA Agent Helper: program kecil di sesi PENGGUNA (tanpa UAC) yang menampilkan pengumuman dan chat dari IT. Dibangun oleh
; helper-app\build.ps1. Hanya IT yang dapat memulai dan mengakhiri chat; lihat info-before.*.txt dan helper-app\HelperApp.cs.
Source: "helper-app\bin\SIMA-Agent-Helper.exe"; DestDir: "{app}"; Flags: ignoreversion

; Net Monitor for Employees Pro (EduIQ.com) — dibundel lagi di dalam .exe ini (satu file untuk didistribusikan,
; satu klik), TAPI diekstrak ke folder PERMANEN ({app}\redist), bukan {tmp}. Memakai {tmp} sebelumnya diduga
; penyebab Windows menolak elevasinya sendiri ("CreateProcess failed; code 740") — folder temp instalasi
; kadang mendapat ACL/kebijakan lebih ketat daripada folder biasa. Dijalankan lewat [Run] di bawah sebagai
; PROSES TERPISAH dari installer SIMA (shellexec + UAC sendiri), bukan bagian dari proses SIMA.
Source: "..\apps\Net Employe\Setup.exe"; DestDir: "{app}\redist"; DestName: "NetMonitorForEmployeesPro-Setup.exe"; Flags: ignoreversion

[Registry]
; Jalan otomatis saat pengguna login (semua pengguna). Ikon tray selalu terlihat; log di %LOCALAPPDATA%\SIMA\helper.log.
Root: HKLM; Subkey: "SOFTWARE\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "SIMA Agent Helper"; ValueData: """{app}\SIMA-Agent-Helper.exe"""; Flags: uninsdeletevalue

[Icons]
; Tidak ada halaman "pilih Start Menu folder" (DisableProgramGroupPage), tapi ikon tetap dibuat di lokasi
; bawaan — supaya pengguna/IT tetap bisa menemukan log dan uninstaller (transparansi, bukan disembunyikan).
Name: "{autoprograms}\{#MyAppName}\SIMA Agent Status"; Filename: "{app}\SIMA-Agent-Status.exe"; WorkingDir: "{app}"; Comment: "{cm:IconStatusComment}"
Name: "{autodesktop}\SIMA Agent Status"; Filename: "{app}\SIMA-Agent-Status.exe"; WorkingDir: "{app}"; Tasks: desktopicon
Name: "{autoprograms}\{#MyAppName}\{cm:IconLogName}"; Filename: "{app}\agent.log"; IconFilename: "{app}\icon.ico"; Comment: "{cm:IconLogComment}"
Name: "{autoprograms}\{#MyAppName}\{cm:UninstallProgram,{#MyAppName}}"; Filename: "{uninstallexe}"; IconFilename: "{app}\icon.ico"

[Run]
; Pendaftaran Scheduled Task "SIMA Asset Agent" dijalankan dari [Code] (CurStepChanged, lewat Exec), BUKAN
; dideklarasikan di sini — [Run] biasa tidak memeriksa kode keluar proses, jadi kalau install.ps1 gagal,
; installer akan diam-diam melaporkan "berhasil" padahal task-nya tidak pernah terdaftar (pernah terjadi
; nyata di lapangan). Lewat Exec() di [Code], kegagalan ditangkap dan ditampilkan jelas ke admin.

; Net Monitor for Employees Pro — dijalankan sebagai PROSES SENDIRI (PID, jendela, elevasi UAC masing-masing),
; bukan bagian dari proses installer SIMA — walau satu file .exe yang sama yang memuatnya.
; "unchecked": kotak centangnya kosong secara bawaan di halaman Finish — admin harus sadar mencentangnya
; sendiri setelah membaca info-before.*.txt (kemampuan penuh: lihat layar, keylog, ambil alih mouse/keyboard,
; blokir aplikasi/USB). "shellexec" memakai ShellExecute (bukan CreateProcess) supaya Windows menampilkan
; dialog UAC-nya Net Monitor sendiri dengan benar untuk binary yang mewajibkan elevasi lewat manifest-nya.
Filename: "{app}\redist\NetMonitorForEmployeesPro-Setup.exe"; Description: "{cm:RunNetMonitor}"; Flags: postinstall skipifsilent unchecked shellexec

; Buka aplikasi status setelah instalasi (laporan pertama sudah dikirim oleh install.ps1).
Filename: "{app}\SIMA-Agent-Status.exe"; Description: "{cm:RunOpenStatus}"; Flags: postinstall nowait skipifsilent shellexec
; Program pembantu dijalankan sebagai PENGGUNA yang memasang (bukan admin hasil UAC), supaya tampil di sesi yang benar.
Filename: "{app}\SIMA-Agent-Helper.exe"; Flags: nowait runasoriginaluser

[UninstallRun]
; Hentikan program pembantu sebelum berkas dihapus (kalau tidak, exe-nya terkunci).
Filename: "{sys}\taskkill.exe"; Parameters: "/F /IM SIMA-Agent-Helper.exe"; Flags: runhidden waituntilterminated; RunOnceId: "StopHelper"
; Melepas Scheduled Task sebelum berkas dihapus. RunOnceId mencegah ini terpanggil dua kali kalau proses
; uninstall di-restart Windows Installer.
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\uninstall.ps1"""; Flags: runhidden waituntilterminated; RunOnceId: "RemoveScheduledTask"

[UninstallDelete]
; config.json berisi token rahasia dan agent.log berisi riwayat laporan — keduanya dibuat di luar [Files]
; (lihat [Code] di bawah / Add-Content), jadi harus dihapus manual saat uninstall.
Type: files; Name: "{app}\config.json"
Type: files; Name: "{app}\agent.log"
; Sisa pembaruan otomatis (cadangan versi lama, staging, status gagal, berkas tertunda) dan token program pembantu.
Type: files; Name: "{commonappdata}\SIMA\helper.json"
Type: dirifempty; Name: "{commonappdata}\SIMA"
Type: filesandordirs; Name: "{app}\backup"
Type: filesandordirs; Name: "{app}\update-tmp"
Type: files; Name: "{app}\update-state.json"
Type: files; Name: "{app}\agent-state.json"
Type: files; Name: "{app}\agent-commands.json"
Type: files; Name: "{app}\*.new"
Type: dirifempty; Name: "{app}\redist"
Type: dirifempty; Name: "{app}"

[Code]
var
  ConfigPage: TInputQueryWizardPage;

{ ---------- teks bahasa ---------- }

{ CustomMessage + penggantian %n (baris baru) dan %1/%2 (argumen). Dipakai untuk semua teks di [Code]. }
function Msg(const Name: String): String;
begin
  Result := CustomMessage(Name);
  StringChangeEx(Result, '%n', #13#10, True);
end;

function MsgArg(const Name, Arg1, Arg2: String): String;
begin
  Result := Msg(Name);
  StringChangeEx(Result, '%1', Arg1, True);
  StringChangeEx(Result, '%2', Arg2, True);
end;

{ ---------- penyiapan ---------- }

{ Saat memperbarui pemasangan lama, program pembantu yang sedang berjalan mengunci berkasnya: hentikan dulu. }
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  ResultCode: Integer;
begin
  Exec(ExpandConstant('{sys}\taskkill.exe'), '/F /IM SIMA-Agent-Helper.exe', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Result := '';
end;

procedure InitializeWizard;
begin
  { Ditempatkan setelah halaman penjelasan (wpInfoBefore), sebelum halaman pemilihan tugas dan "Ready to Install". }
  ConfigPage := CreateInputQueryPage(wpInfoBefore, Msg('CfgTitle'), Msg('CfgSubtitle'), Msg('CfgIntro'));
  ConfigPage.Add(Msg('CfgUrlLabel'), False);
  ConfigPage.Add(Msg('CfgTokenLabel'), True);
  { Alamat SIMA produksi — bisa diubah di kolom ini kalau laptop target perlu disambungkan ke server lain
    (mis. lokal/staging). }
  ConfigPage.Values[0] := 'https://asset.lameruru.web.id';
end;

{ Token asli dari AssetAgentToken::issueFor() (Str::random(40)) hanya huruf+angka. Bukan validasi kriptografi
  — cuma penangkal salah-tempel yang murah (mis. path folder, URL, atau teks penjelasan ikut tersalin). }
function IsLikelyValidToken(const S: String): Boolean;
var
  I: Integer;
  C: Char;
begin
  Result := Length(S) >= 20;
  if not Result then Exit;

  for I := 1 to Length(S) do
  begin
    C := S[I];
    if not (((C >= 'A') and (C <= 'Z')) or ((C >= 'a') and (C <= 'z')) or ((C >= '0') and (C <= '9'))) then
    begin
      Result := False;
      Exit;
    end;
  end;
end;

function TrimTrailingSlash(const S: String): String;
begin
  Result := Trim(S);
  while (Length(Result) > 0) and (Result[Length(Result)] = '/') do
    Delete(Result, Length(Result), 1);
end;

{ Memeriksa alamat dan token SEBELUM memasang: GET /api/agent/v1/update (butuh token agent yang sah) —
  200 = alamat benar dan token sah, 401/403 = token ditolak. Mengembalikan False bila server tidak dapat dihubungi. }
function CheckConnection(const Url, Token: String; var StatusCode: Integer; var ErrorText: String): Boolean;
var
  Http: Variant;
begin
  Result := False;
  StatusCode := 0;
  ErrorText := '';
  try
    Http := CreateOleObject('WinHttp.WinHttpRequest.5.1');
    Http.SetTimeouts(4000, 4000, 8000, 8000);
    Http.Open('GET', TrimTrailingSlash(Url) + '/api/agent/v1/update?current=99999', False);
    Http.SetRequestHeader('Authorization', 'Bearer ' + Token);
    Http.SetRequestHeader('Accept', 'application/json');
    Http.Send;
    StatusCode := Http.Status;
    Result := True;
  except
    ErrorText := GetExceptionMessage;
  end;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  Url, Token, ErrorText, SavedDescription: String;
  StatusCode: Integer;
  Reached: Boolean;
begin
  Result := True;

  if CurPageID = ConfigPage.ID then
  begin
    Url := Trim(ConfigPage.Values[0]);
    Token := Trim(ConfigPage.Values[1]);

    if (Url = '') or ((Pos('http://', Url) <> 1) and (Pos('https://', Url) <> 1)) then
    begin
      MsgBox(Msg('ErrUrl'), mbError, MB_OK);
      Result := False;
      Exit;
    end;

    if Token = '' then
    begin
      MsgBox(Msg('ErrTokenEmpty'), mbError, MB_OK);
      Result := False;
      Exit;
    end;

    { Token asli (dibuat lewat Str::random di SIMA) hanya berisi huruf dan angka. Pernah terjadi admin
      tidak sengaja menempel teks lain (mis. path folder seperti "C:\xampp\...") ke kolom ini — ditolak
      di sini supaya ketahuan SEKARANG, bukan baru ketahuan nanti saat agent gagal melapor. }
    if not IsLikelyValidToken(Token) then
    begin
      MsgBox(Msg('ErrTokenFormat'), mbError, MB_OK);
      Result := False;
      Exit;
    end;

    { Periksa koneksi dan token ke server. Token ditolak = berhenti di sini; server tidak terjangkau atau
      menjawab aneh = tanya dulu (pemasangan offline tetap diperbolehkan). }
    SavedDescription := WizardForm.PageDescriptionLabel.Caption;
    WizardForm.PageDescriptionLabel.Caption := Msg('CfgChecking');
    WizardForm.Cursor := crHourGlass;
    WizardForm.Update;
    try
      Reached := CheckConnection(Url, Token, StatusCode, ErrorText);
    finally
      WizardForm.Cursor := crDefault;
      WizardForm.PageDescriptionLabel.Caption := SavedDescription;
    end;

    if not Reached then
    begin
      if MsgBox(MsgArg('ChkUnreachable', ErrorText, ''), mbConfirmation, MB_YESNO or MB_DEFBUTTON2) <> IDYES then
        Result := False;
    end
    else if (StatusCode = 401) or (StatusCode = 403) then
    begin
      MsgBox(MsgArg('ChkTokenInvalid', IntToStr(StatusCode), ''), mbError, MB_OK);
      Result := False;
    end
    else if StatusCode <> 200 then
    begin
      if MsgBox(MsgArg('ChkUnexpected', IntToStr(StatusCode), ''), mbConfirmation, MB_YESNO or MB_DEFBUTTON2) <> IDYES then
        Result := False;
    end;
  end;
end;

{ Ringkasan di halaman "Siap memasang": apa yang akan dilakukan, termasuk alamat dan 4 karakter terakhir token. }
function UpdateReadyMemo(Space, NewLine, MemoUserInfoInfo, MemoDirInfo, MemoTypeInfo, MemoComponentsInfo, MemoGroupInfo, MemoTasksInfo: String): String;
var
  Token: String;
begin
  Token := Trim(ConfigPage.Values[1]);
  Result :=
    Msg('ReadyServer') + NewLine + Space + Trim(ConfigPage.Values[0]) + NewLine + NewLine +
    Msg('ReadyToken') + NewLine + Space + '********' + Copy(Token, Length(Token) - 3, 4) + NewLine + NewLine +
    Msg('ReadySchedule') + NewLine + Space + Msg('ReadyScheduleValue') + NewLine + NewLine +
    Msg('ReadyHelper') + NewLine + Space + Msg('ReadyHelperValue');

  if MemoDirInfo <> '' then
    Result := Result + NewLine + NewLine + MemoDirInfo;
  if MemoTasksInfo <> '' then
    Result := Result + NewLine + NewLine + MemoTasksInfo;
end;

function JsonEscape(const S: String): String;
begin
  Result := S;
  StringChangeEx(Result, '\', '\\', True);
  StringChangeEx(Result, '"', '\"', True);
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  Json: String;
  ResultCode: Integer;
  ExecOk: Boolean;
begin
  if CurStep = ssPostInstall then
  begin
    { config.json ditulis DULU — install.ps1 mensyaratkan berkas ini sudah ada (lihat agent-client/install.ps1). }
    Json :=
      '{' + #13#10 +
      '  "simaUrl": "' + JsonEscape(Trim(ConfigPage.Values[0])) + '",' + #13#10 +
      '  "token": "' + JsonEscape(Trim(ConfigPage.Values[1])) + '"' + #13#10 +
      '}' + #13#10;
    SaveStringToFile(ExpandConstant('{app}\config.json'), Json, False);

    { Token adalah rahasia: Program Files secara bawaan bisa DIBACA semua pengguna, jadi hak akses config.json dikunci
      hanya untuk SYSTEM (agent berjalan sebagai SYSTEM) dan Administrators (aplikasi status berjalan sebagai admin).
      Memakai SID agar tidak bergantung bahasa Windows. }
    Exec(ExpandConstant('{sys}\icacls.exe'),
      '"' + ExpandConstant('{app}\config.json') + '" /inheritance:r /grant:r *S-1-5-18:F *S-1-5-32-544:F',
      '', SW_HIDE, ewWaitUntilTerminated, ResultCode);

    { Dijalankan lewat Exec() (bukan [Run] biasa) supaya kode keluarnya BISA diperiksa. install.ps1 sendiri
      sudah memverifikasi Scheduled Task benar-benar terdaftar sebelum keluar dengan kode 0 (lihat
      agent-client/install.ps1) — kalau gagal di sini, berarti pendaftarannya SUNGGUH gagal, bukan salah
      baca status. }
    ExecOk := Exec('powershell.exe',
      '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{app}\install.ps1') + '" -IntervalMinutes 5',
      '', SW_HIDE, ewWaitUntilTerminated, ResultCode);

    if (not ExecOk) or (ResultCode <> 0) then
      MsgBox(MsgArg('ErrTaskRegister', IntToStr(ResultCode), ExpandConstant('{app}')), mbError, MB_OK);
  end;
end;
