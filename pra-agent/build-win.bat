@echo off
setlocal
title TaxNest PRA Agent - Installer Banayen
cd /d "%~dp0"

echo.
echo ================================================
echo   TaxNest PRA Agent - Windows Installer Banayen
echo ================================================
echo.

where node >nul 2>&1
if errorlevel 1 (
    echo [RUKAWAT] Node.js is PC par install nahi hai.
    echo.
    echo Yeh karein:
    echo   1. Browser mein kholein:  https://nodejs.org
    echo   2. "LTS" wala bara green button dabayen - ek file download hogi
    echo   3. Us file par double-click, phir Next - Next - Install
    echo   4. Yeh window BAND karein aur build-win.bat DOBARA double-click karein
    echo.
    pause
    exit /b 1
)

for /f "tokens=*" %%v in ('node --version') do set "NODEVER=%%v"
echo Node.js mila: %NODEVER%
echo.
echo Pehli dafa chalane par ~150 MB download hota hai - internet chalta rehna chahiye.
echo.

echo ------------------------------------------------
echo  [1 / 2]  Zaroori files download ho rahi hain...
echo ------------------------------------------------
echo.
call npm install --no-audit --no-fund
if errorlevel 1 (
    echo.
    echo [ITTELA] npm ne error diya - lekin agla marhala khud check kar lega
    echo          ke kuch reh to nahi gaya. Aage barh rahe hain...
    echo.
)

echo.
echo ------------------------------------------------
echo  [2 / 2]  Installer ban raha hai (2-3 minute)...
echo ------------------------------------------------
echo.
call npm run build:win
if errorlevel 1 (
    echo.
    echo [NAKAAM] Installer nahi ban saka.
    echo.
    echo Upar likhi hui aakhri 15-20 lines ka screenshot bhej dein - masla
    echo wahin likha hota hai. Window abhi band na karein.
    echo.
    pause
    exit /b 1
)

echo.
echo ================================================
echo   HO GAYA
echo ================================================
echo.
echo Installer yahan bana hai:
echo   %~dp0dist
echo.
dir /b "%~dp0dist\*.exe" 2>nul
echo.
echo Koi bhi key dabayen - yeh folder khul jayega.
pause >nul
start "" "%~dp0dist"
exit /b 0
