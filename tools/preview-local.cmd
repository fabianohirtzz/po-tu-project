@echo off
rem Previa local do site e do painel (ver tools\preview-local.php).
rem O formulario e o clique do WhatsApp sao fingidos: nada vai para o banco.
rem O painel entra com o login de verdade e SALVAR grava no banco de verdade.
cd /d "%~dp0.."
set "PHPEXE=php"
where php >nul 2>nul || set "PHPEXE=%USERPROFILE%\php\php.exe"
echo.
echo   Previa em http://127.0.0.1:8000   (painel: http://127.0.0.1:8000/painel/)
echo   Para parar: Ctrl+C nesta janela.
echo.
start "" http://127.0.0.1:8000
"%PHPEXE%" -S 127.0.0.1:8000 -t . tools\preview-local.php
