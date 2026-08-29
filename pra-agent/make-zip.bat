@echo off
setlocal
title TaxNest PRA Agent - Release Zip Banayen
cd /d "%~dp0"

echo.
echo ================================================
echo   TaxNest PRA Agent - Release Zip Banayen
echo ================================================
echo.
echo Shops ke agent khud ko ZIP se update karte hain, EXE se nahi.
echo Yeh file wohi zip banati hai.
echo.

if not exist "dist\win-unpacked\" (
    echo [RUKAWAT] "dist\win-unpacked" folder nahi mila.
    echo.
    echo Pehle build-win.bat chalayen - wo yeh folder banata hai.
    echo Uske baad yeh file dobara double-click karein.
    echo.
    pause
    exit /b 1
)

if not exist "install.bat" (
    echo [RUKAWAT] install.bat nahi mila.
    echo.
    echo Lagta hai yeh file source folder ke bahar rakhi gayi hai. Isay wahan
    echo rakhein jahan build-win.bat aur package.json parhe hain.
    echo.
    pause
    exit /b 1
)

echo ------------------------------------------------
echo  [1 / 3]  Purani copy saaf ki ja rahi hai...
echo ------------------------------------------------
echo.
if exist "dist\TaxNest-PRA-Agent\" rmdir /s /q "dist\TaxNest-PRA-Agent"
if exist "dist\TaxNest-PRA-Agent-Windows.zip" del /q "dist\TaxNest-PRA-Agent-Windows.zip"

echo ------------------------------------------------
echo  [2 / 3]  Files jama ki ja rahi hain...
echo ------------------------------------------------
echo.
robocopy "dist\win-unpacked" "dist\TaxNest-PRA-Agent" /E /R:2 /W:2 >nul
if %ERRORLEVEL% GEQ 8 (
    echo [NAKAAM] Files copy nahi ho sakin.
    echo.
    pause
    exit /b 1
)
copy /y "install.bat" "dist\TaxNest-PRA-Agent\install.bat" >nul

echo ------------------------------------------------
echo  [3 / 3]  Zip ban rahi hai (1-2 minute)...
echo ------------------------------------------------
echo.
powershell -NoProfile -NonInteractive -Command "Compress-Archive -Path 'dist\TaxNest-PRA-Agent' -DestinationPath 'dist\TaxNest-PRA-Agent-Windows.zip' -Force"
if errorlevel 1 (
    echo.
    echo [NAKAAM] Zip nahi ban saki.
    echo.
    echo Upar likhi hui aakhri 15-20 lines ka screenshot bhej dein.
    echo Window abhi band na karein.
    echo.
    pause
    exit /b 1
)

if not exist "dist\TaxNest-PRA-Agent-Windows.zip" (
    echo [NAKAAM] Zip file nahi mili.
    echo.
    pause
    exit /b 1
)

echo.
echo ================================================
echo   HO GAYA
echo ================================================
echo.
echo Yeh file bhejni hai:
echo   %~dp0dist\TaxNest-PRA-Agent-Windows.zip
echo.
for %%f in ("dist\TaxNest-PRA-Agent-Windows.zip") do echo Size: %%~zf bytes
echo.
echo Koi bhi key dabayen - yeh folder khul jayega aur zip select ho jayegi.
pause >nul
explorer /select,"%~dp0dist\TaxNest-PRA-Agent-Windows.zip"
exit /b 0
