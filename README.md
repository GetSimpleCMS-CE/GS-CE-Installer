# GS-CE-Installer

Single file script to install or update GetSimpleCMS in 2 clicks.

<img width="1200" alt="image" src="https://github.com/user-attachments/assets/734d8853-58c9-48de-990e-f9e248ce87dc" />

## Instructions
- Upload the `install.php` file to your `/www/` directory via FTP.
- Browse to your site and open the `install.php` file and select your preferred version to install or update

## What does this script do?
- Downloads and extracts the last version or update of GetSimpleCMS-CE and then runs setup.
- If it detects a previously installed version, you will be given the option to create a backup.
- Removes itself before running the GS setup script.

## Requirements
- php7.4+ (8.1+ recommended)
- Requires allow_url_fopen to be enabled by your server's PHP configuration.
