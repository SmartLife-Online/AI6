@echo off
setlocal
cd /d "%~dp0.."
php artisan serve --host=localhost --port=8000 --no-interaction
