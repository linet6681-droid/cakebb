@echo off
REM XAMPP default path - change if yours differs
"C:\xampp\mysql\bin\mysqldump.exe" -u root --routines cakeb > backup_cakeb_%date:~-4%%date:~4,2%%date:~7,2%.sql
echo Backup done.
