@echo off
title Building Busistree Installables
cd /d "%~dp0"
echo ========================================================
echo Building Busistree Installables and Release Packages...
echo ========================================================
echo.
node scripts\build-installables.cjs
echo.
echo ========================================================
echo Done! All installers are in the "release" folder.
echo ========================================================
pause
