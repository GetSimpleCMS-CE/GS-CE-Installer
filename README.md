# GS-CE-Installer
![image](https://github.com/GetSimpleCMS-CE/GS-CE-Installer/assets/119761508/dfb81340-805d-4be3-8b5e-4d5cee9a51be)
Single file script to install or update GetSimpleCMS in 1 click

Upload `gs-ce-installer.php` to the root of the server with FTP and select prefered version to load.

- Copies and extracts the last version or patch of GetSimpleCMS-CE then runs the setup if needed.
- Downloads direct from the GS or CE repository to your server.
- Removes itself before running the GS setup script.
- Requires allow_url_fopen to be enabled by your server's PHP configuration.
