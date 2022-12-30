# GS-CE-Installer
<img src="https://user-images.githubusercontent.com/119761508/210095438-9136c0e9-b00b-4bd2-bfd5-46e01432239a.png" width="800">
Single file script to install or update GetSimpleCMS in 1 click

Upload `gs-ce-installer.php` to the root of the server with FTP and select prefered version to load.

- Copies and extracts the last version or patch of GetSimpleCMS-CE then runs the setup if needed.
- Downloads direct from the GS or CE repository to your server.
- Removes itself before running the GS setup script.
- Requires allow_url_fopen to be enabled by your server's PHP configuration.
