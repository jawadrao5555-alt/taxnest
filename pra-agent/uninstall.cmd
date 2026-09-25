@echo off
rem Portable installs from older releases have an uninstall.bat but no Windows
rem registration. This wrapper stays in the auto-update ZIP so it can remove
rem the new HKCU entry before invoking that existing uninstaller.
reg delete "HKCU\Software\Microsoft\Windows\CurrentVersion\Uninstall\TaxNestPRAAgentPortable" /f >nul 2>&1
if exist "%~dp0uninstall.bat" (
  call "%~dp0uninstall.bat"
) else (
  echo TaxNest PRA Agent uninstaller missing. No application files were removed.
  pause
  exit /b 1
)
