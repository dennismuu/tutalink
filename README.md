# LertFind Kenya

LertFind is a PHP 8.1+ and MySQL/MariaDB application for tutoring and home-service discovery, registration, booking requests, admin-set service pricing, role-scoped administration, and booking support.

## Run locally with XAMPP

1. Copy the project into `C:\xampp\htdocs\TUTALINK`.
2. Start **Apache** and **MySQL** from the XAMPP Control Panel.
3. Open phpMyAdmin at `http://localhost/phpmyadmin`, import `database/tutalink.sql`, and confirm the `tutalink` database was created.
4. The default local connection is `127.0.0.1:3306`, database `tutalink`, user `root`, and an empty password. For a different MySQL account, set `TIDB_HOST`, `TIDB_PORT`, `TIDB_DB`, `TIDB_USER`, and `TIDB_PASSWORD` in the Apache process environment before restarting Apache.
5. From PowerShell, create the first administrator:

   ```powershell
   cd C:\xampp\htdocs\TUTALINK
   php scripts\create_super_admin.php
