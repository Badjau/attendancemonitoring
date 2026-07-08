@echo off
setlocal

cd /d "%~dp0"

set "VENV_DIR=.venv"
set "PYTHON_EXE=%VENV_DIR%\Scripts\python.exe"
set "ACTIVATE_BAT=%VENV_DIR%\Scripts\activate.bat"
set "SSL_KEY=C:\laragon\etc\ssl\laragon.key"
set "SSL_CERT=C:\laragon\etc\ssl\laragon.crt"

echo Face service directory: %CD%

if not exist "%PYTHON_EXE%" (
    echo Creating Python virtual environment in %VENV_DIR%...
    py -3 -m venv "%VENV_DIR%"

    if errorlevel 1 (
        echo Python launcher failed. Trying python directly...
        python -m venv "%VENV_DIR%"
    )

    if errorlevel 1 (
        echo ERROR: Failed to create virtual environment. Make sure Python 3 is installed.
        pause
        exit /b 1
    )
)

if not exist "%ACTIVATE_BAT%" (
    echo ERROR: Virtual environment activation script was not found at %ACTIVATE_BAT%.
    pause
    exit /b 1
)

if not exist "%SSL_KEY%" (
    echo ERROR: SSL key not found: %SSL_KEY%
    pause
    exit /b 1
)

if not exist "%SSL_CERT%" (
    echo ERROR: SSL certificate not found: %SSL_CERT%
    pause
    exit /b 1
)

call "%ACTIVATE_BAT%"

python -m pip install --upgrade pip
if errorlevel 1 (
    echo ERROR: Failed to upgrade pip.
    pause
    exit /b 1
)

python -m pip install -r requirements.txt
if errorlevel 1 (
    echo ERROR: Failed to install face service requirements.
    pause
    exit /b 1
)

echo Starting face recognition service at https://127.0.0.1:8001
python -m uvicorn app.main:app --host 0.0.0.0 --port 8001 --ssl-keyfile "%SSL_KEY%" --ssl-certfile "%SSL_CERT%"

pause
