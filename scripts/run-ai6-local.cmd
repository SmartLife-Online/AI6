@echo off
setlocal
cd /d "%~dp0.."
php artisan serve --host=127.0.0.1 --port=8000 --no-interaction
