# GS-CE-Installer
![GS-CE-Installer-v2](https://user-images.githubusercontent.com/119761508/210094861-3628c604-0f16-48ae-8fe4-964ded195247.png | width=100)
Single file script to install or update GetSimpleCMS in 1 click

Upload gs-ce-installer.php to the root of the server with FTP and select prefered version to load.

- Copies and extracts the last version or patch of GetSimpleCMS-CE then runs the setup if needed.
- Downloads direct from the GS or CE repository to your server.
- Removes itself before running the GS setup script.
- Requires allow_url_fopen to be enabled by your server's PHP configuration.
