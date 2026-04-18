@echo off
setlocal EnableDelayedExpansion
title TaxNest PRA Agent - Installer

echo.
echo ================================================
echo   TaxNest PRA Sync Agent - Installer
echo ================================================
echo.

set "INSTALL_DIR=%LOCALAPPDATA%\TaxNest PRA Agent"
set "EXE_NAME=TaxNest PRA Agent.exe"
set "SOURCE_DIR=%~dp0"

echo Installing to: %INSTALL_DIR%
echo.

echo Stopping any running agent...
taskkill /F /IM "TaxNest PRA Agent.exe" 2>nul
timeout /t 2 /nobreak >nul

if exist "%INSTALL_DIR%" (
    echo Existing installation detected - upgrading in place...
    echo Backing up config...
    if exist "%APPDATA%\taxnest-pra-agent\config.json" (
        copy /Y "%APPDATA%\taxnest-pra-agent\config.json" "%TEMP%\taxnest-agent-config.bak" >nul 2>&1
    )
    rmdir /S /Q "%INSTALL_DIR%" 2>nul
    timeout /t 1 /nobreak >nul
    if exist "%TEMP%\taxnest-agent-config.bak" (
        if not exist "%APPDATA%\taxnest-pra-agent" mkdir "%APPDATA%\taxnest-pra-agent"
        copy /Y "%TEMP%\taxnest-agent-config.bak" "%APPDATA%\taxnest-pra-agent\config.json" >nul 2>&1
    )
)

mkdir "%INSTALL_DIR%" 2>nul
echo Copying files (this may take a moment)...
xcopy /E /I /Y /Q "%SOURCE_DIR%*" "%INSTALL_DIR%\" >nul
del "%INSTALL_DIR%\install.bat" 2>nul
del "%INSTALL_DIR%\uninstall.bat" 2>nul

if not exist "%INSTALL_DIR%\%EXE_NAME%" (
    echo.
    echo [ERROR] Installation failed - executable not found.
    pause
    exit /b 1
)

echo Creating Start Menu shortcut...
set "SM_DIR=%APPDATA%\Microsoft\Windows\Start Menu\Programs"
powershell -NoProfile -Command "$s = (New-Object -ComObject WScript.Shell).CreateShortcut('%SM_DIR%\TaxNest PRA Agent.lnk'); $s.TargetPath = '%INSTALL_DIR%\%EXE_NAME%'; $s.WorkingDirectory = '%INSTALL_DIR%'; $s.IconLocation = '%INSTALL_DIR%\%EXE_NAME%,0'; $s.Description = 'TaxNest PRA Sync Agent'; $s.Save()"

echo Creating Desktop shortcut...
powershell -NoProfile -Command "$s = (New-Object -ComObject WScript.Shell).CreateShortcut([Environment]::GetFolderPath('Desktop') + '\TaxNest PRA Agent.lnk'); $s.TargetPath = '%INSTALL_DIR%\%EXE_NAME%'; $s.WorkingDirectory = '%INSTALL_DIR%'; $s.IconLocation = '%INSTALL_DIR%\%EXE_NAME%,0'; $s.Description = 'TaxNest PRA Sync Agent'; $s.Save()"

echo Adding to Windows startup...
set "STARTUP_DIR=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
powershell -NoProfile -Command "$s = (New-Object -ComObject WScript.Shell).CreateShortcut('%STARTUP_DIR%\TaxNest PRA Agent.lnk'); $s.TargetPath = '%INSTALL_DIR%\%EXE_NAME%'; $s.WorkingDirectory = '%INSTALL_DIR%'; $s.IconLocation = '%INSTALL_DIR%\%EXE_NAME%,0'; $s.Save()"

(
echo @echo off
echo title TaxNest PRA Agent - Uninstaller
echo echo.
echo echo Stopping agent...
echo taskkill /F /IM "TaxNest PRA Agent.exe" 2^>nul
echo timeout /t 2 /nobreak ^>nul
echo echo Removing shortcuts...
echo del "%APPDATA%\Microsoft\Windows\Start Menu\Programs\TaxNest PRA Agent.lnk" 2^>nul
echo del "%USERPROFILE%\Desktop\TaxNest PRA Agent.lnk" 2^>nul
echo del "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\TaxNest PRA Agent.lnk" 2^>nul
echo echo Removing application files...
echo cd /d "%TEMP%"
echo rmdir /S /Q "%LOCALAPPDATA%\TaxNest PRA Agent" 2^>nul
echo echo.
echo echo Uninstalled. Press any key to exit.
echo pause ^>nul
) > "%INSTALL_DIR%\uninstall.bat"

echo.
echo ================================================
echo   Installation Complete!
echo ================================================
echo.
echo  - App installed: %INSTALL_DIR%
echo  - Available in Windows Start Menu (search "TaxNest")
echo  - Desktop shortcut created
echo  - Auto-starts with Windows
echo.

choice /C YN /M "Launch TaxNest PRA Agent now"
if errorlevel 2 goto :end
start "" "%INSTALL_DIR%\%EXE_NAME%"

:end
echo.
echo Done!
timeout /t 3 /nobreak >nul
exit /b 0
