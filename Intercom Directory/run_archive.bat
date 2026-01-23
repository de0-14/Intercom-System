@echo off
echo Running archive script at %DATE% %TIME%
D:\XAMPP\php\php.exe "D:\XAMPP\htdocs\Intercom Directory\archive_cron.php"
echo Archive completed at %TIME%
pause