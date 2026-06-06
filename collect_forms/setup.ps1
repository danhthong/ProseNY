# Setup the forms pipeline (Python 3.11 venv + dependencies + Chromium).
# Run from anywhere:  .\collect_forms\setup.ps1
# After setup, run:   .\collect_forms\run.ps1

$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

if (-not (Get-Command uv -ErrorAction SilentlyContinue)) {
    Write-Error "uv is not installed. Install it from https://docs.astral.sh/uv/"
}

Write-Host "Creating Python 3.11 virtual environment..." -ForegroundColor Cyan
uv venv --python 3.11 .venv

Write-Host "Installing dependencies..." -ForegroundColor Cyan
uv pip install --python .venv\Scripts\python.exe -r requirements.txt

Write-Host "Installing Playwright Chromium..." -ForegroundColor Cyan
.\.venv\Scripts\python.exe -m playwright install chromium

Write-Host ""
Write-Host "Setup complete." -ForegroundColor Green
Write-Host "Run the full pipeline with:  .\run.ps1" -ForegroundColor Yellow
Write-Host "Or pass a custom listing URL: .\run.ps1 'https://www.nycourts.gov/forms?...'" -ForegroundColor Yellow
