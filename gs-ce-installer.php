<?php

/* **********
* Plugin Name: gs-ce-installer
* Description: Single file script to install or update GetSimpleCMS in 1 click.
* Version: 2.6
* Author: Islander / Risingisland
* Author URI: https://github.com/risingisland
********** */

// Bypass permission check for localhost (for testing only)
if ($_SERVER['SERVER_ADDR'] === '127.0.0.1' || $_SERVER['SERVER_ADDR'] === '::1') {
	define('LOCALHOST_BYPASS', true);
}

// Error reporting - show errors in development, hide in production
if (defined('LOCALHOST_BYPASS')) {
	error_reporting(E_ALL);
	ini_set('display_errors', 1);
} else {
	error_reporting(0);
	ini_set('display_errors', 0);
}

// Start session for CSRF token
if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

set_time_limit(0);
ini_set('max_execution_time', 0);

$installer_version = '2.6';

if (extension_loaded('xdebug')) {
	ini_set('xdebug.max_nesting_level', 100000);
}

// Handle installation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['target'])) {
	$token = $_POST['token'] ?? '';
	$create_backup = isset($_POST['create_backup']) ? true : false;
	if (Installer::doInstall($_POST['target'], $token, $create_backup)) {
		exit;
	}
}

header('Content-Type: text/html; charset=utf-8');

class Installer {
	public static $packageInfo = [
		'Full' => [
			'tree' => 'Get-Simple CMS CE v3.3.22',
			'name' => 'New Installation',
			'link' => 'https://github.com/GetSimpleCMS-CE/GetSimpleCMS-CE/archive/refs/tags/v3.3.22.zip',
			'location' => 'admin/install.php',
			'description' => 'Fresh installation of GetSimple CMS CE',
			'badge' => '✨ New Install',
			'version' => '3.3.22',
			'type' => 'stable'
		],
		'FullBeta' => [
			'tree' => 'Get-Simple CMS CE v3.3.23-beta',
			'name' => 'Install Current Beta',
			'link' => 'https://github.com/GetSimpleCMS-CE/GetSimpleCMS-CE/archive/refs/heads/main.zip',
			'location' => 'admin/install.php',
			'description' => 'Latest development version (beta)',
			'badge' => '🧪 Beta',
			'version' => '3.3.23-beta',
			'type' => 'beta'
		],
		'Upgrade' => [
			'tree' => 'Get-Simple CMS CE v3.3.22 Upgrade',
			'name' => 'Upgrade Only',
			'link' => 'https://github.com/GetSimpleCMS-CE/update-GetSimpleCMS-CE/archive/refs/heads/3.3.22.zip',
			'location' => 'admin/',
			'description' => 'Upgrade existing installation (v3.3.16+ required)',
			'badge' => '🔄 Upgrade',
			'version' => '3.3.22',
			'type' => 'upgrade'
		],
		'UpgradeBeta' => [
			'tree' => 'Get-Simple CMS CE v3.3.23-beta Upgrade',
			'name' => 'Upgrade to Current Beta',
			'link' => 'https://github.com/GetSimpleCMS-CE/update-GetSimpleCMS-CE/archive/refs/heads/main.zip',
			'location' => 'admin/',
			'description' => 'Upgrade to latest development version (beta)',
			'badge' => '🧪 Beta Upgrade',
			'version' => '3.3.23-beta',
			'type' => 'upgrade-beta'
		]
	];

	public static function generateCSRFToken() {
		if (empty($_SESSION['install_token'])) {
			$_SESSION['install_token'] = bin2hex(random_bytes(32));
		}
		return $_SESSION['install_token'];
	}

	public static function isInstalled() {
		return file_exists(__DIR__ . '/gsconfig.php');
	}

	public static function getAvailableOptions() {
		$installed = self::isInstalled();
		$options = [];

		if (!$installed) {
			// New installation options
			$options['Full'] = self::$packageInfo['Full'];
			$options['FullBeta'] = self::$packageInfo['FullBeta'];
		} else {
			// Upgrade options
			$options['Upgrade'] = self::$packageInfo['Upgrade'];
			$options['UpgradeBeta'] = self::$packageInfo['UpgradeBeta'];
		}

		return $options;
	}

	public static function items($default = null) {
		$options = self::getAvailableOptions();
		$rs = [];
		$col_width = count($options) === 2 ? 'col-6' : 'col-4';

		foreach ($options as $key => $item) {
			$checked = ($key === $default) ? 'checked' : '';
			$version = $item['version'];
			
			$rs[] = sprintf(
				'<div class="%s">
					<div class="card package-card" data-package="%s" data-type="%s">
						<div class="package-header">
							<span class="package-icon">%s</span>
							<h3 class="package-title">%s</h3>
						</div>
						<div class="package-body">
							<p class="package-description">%s</p>
							<div class="package-features">
								<span class="badge">%s</span>
								<span class="badge badge-secondary">v%s</span>
							</div>
						</div>
						<div class="package-footer">
							<label class="radio-card">
								<input type="radio" name="target" value="%s" %s>
								<span class="radio-label">Select</span>
							</label>
						</div>
					</div>
				</div>',
				$col_width,
				$key,
				$item['type'],
				$item['type'] === 'beta' || $item['type'] === 'upgrade-beta' ? '🧪' : '📦',
				$item['name'],
				$item['description'],
				$item['badge'],
				$version,
				$key,
				$checked
			);
		}

		return implode("\n", $rs);
	}

	public static function hasProblem() {
		$problems = [];

		if (!ini_get('allow_url_fopen')) {
			$problems[] = 'Cannot download files - url_fopen is not enabled on this server.';
		}

		if (!class_exists('ZipArchive')) {
			$problems[] = 'Cannot extract files - Zip extension is not available.';
		}

		if (!defined('LOCALHOST_BYPASS') && !Installer::hasDirPerm()) {
			$problems[] = 'Cannot download files - The directory does not have write permission.';
		}

		$memory_limit = ini_get('memory_limit');
		if ($memory_limit != '-1' && self::convertToBytes($memory_limit) < 128 * 1024 * 1024) {
			$problems[] = 'Low memory limit detected (' . htmlspecialchars($memory_limit) . '). Consider increasing memory_limit to at least 128M.';
		}

		if (empty($problems)) {
			return false;
		}

		$html = '<div class="alert alert-error">';
		$html .= '<strong>⚠️ Installation Requirements Not Met:</strong><ul>';
		foreach ($problems as $problem) {
			$html .= '<li>' . htmlspecialchars($problem) . '</li>';
		}
		$html .= '</ul></div>';
		return $html;
	}

	private static function convertToBytes($value) {
		$value = trim($value);
		$last = strtolower($value[strlen($value) - 1]);
		$value = (int)$value;

		switch ($last) {
			case 'g':
				$value *= 1024;
			case 'm':
				$value *= 1024;
			case 'k':
				$value *= 1024;
		}

		return $value;
	}

	private static function downloadFile($url, $path) {
		$context = stream_context_create([
			'http' => [
				'timeout' => 30,
				'header' => "User-Agent: GetSimple CMS Installer\r\n"
			],
			'ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true
			]
		]);

		$rs = @file_get_contents($url, false, $context);
		if ($rs === false) {
			$error = error_get_last();
			throw new \RuntimeException('Download failed: ' . ($error['message'] ?? 'Unknown error'));
		}

		if (file_put_contents($path, $rs) === false) {
			throw new \RuntimeException('Failed to save downloaded file');
		}

		return true;
	}

	private static function createBackup($base_dir) {
		$backup_dir = $base_dir . '/backups/zip';
		
		// Create backup directory if it doesn't exist
		if (!is_dir($backup_dir)) {
			if (!mkdir($backup_dir, 0755, true)) {
				throw new \RuntimeException('Failed to create backup directory: ' . $backup_dir);
			}
		}
		
		// Generate backup filename with date and time (YYYYMMDDHHmmss)
		$backup_filename = date('YmdHis') . '.zip';
		$backup_path = $backup_dir . '/' . $backup_filename;
		
		// Create zip archive
		$zip = new ZipArchive();
		if ($zip->open($backup_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('Failed to create backup archive');
		}
		
		// Files and directories to exclude from backup
		$exclude = [
			'backups',
			'data/cache',
			'data/logs',
			'data/tmp',
			'gs-ce-installer.php',
			'fetch.zip'
		];
		
		// Normalize base directory
		$base_dir = rtrim(str_replace('\\', '/', $base_dir), '/');
		
		// Get all files recursively
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($base_dir, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		
		$added_count = 0;
		
		foreach ($files as $file) {
			// Get the full path
			$full_path = str_replace('\\', '/', $file->getPathname());
			
			// Get the relative path by removing the base directory
			$relative_path = substr($full_path, strlen($base_dir) + 1);
			
			// Skip if path is empty
			if (empty($relative_path)) {
				continue;
			}
			
			// Check if path should be excluded
			$exclude_path = false;
			foreach ($exclude as $exclude_pattern) {
				if (strpos($relative_path, $exclude_pattern) === 0) {
					$exclude_path = true;
					break;
				}
			}
			
			if ($exclude_path) {
				continue;
			}
			
			// Get the directory part of the relative path
			$relative_dir = dirname($relative_path);
			
			// Add directory structure if it doesn't exist
			if ($relative_dir !== '.' && !empty($relative_dir)) {
				// Split the directory path and add each level
				$dir_parts = explode('/', $relative_dir);
				$current_path = '';
				foreach ($dir_parts as $part) {
					$current_path .= $part . '/';
					// Add directory with trailing slash
					if ($zip->locateName($current_path) === false) {
						$zip->addEmptyDir($current_path);
					}
				}
			}
			
			// Add the file
			if ($zip->addFile($full_path, $relative_path)) {
				$added_count++;
			}
		}
		
		$zip->close();
		
		// Verify backup was created
		if (!file_exists($backup_path) || filesize($backup_path) < 1000) {
			throw new \RuntimeException('Backup file appears to be corrupted or empty');
		}
		
		return $backup_filename;
	}

	private static function moveFiles($src, $dest) {
		$src = rtrim($src, '/\\');
		$dest = rtrim($dest, '/\\');

		if (!is_dir($src)) {
			throw new \RuntimeException('Source directory does not exist: ' . $src);
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $item) {
			$targetPath = $dest . '/' . $iterator->getSubPathName();

			if ($item->isDir()) {
				self::mmkDir($targetPath);
			} else {
				$targetDir = dirname($targetPath);
				self::mmkDir($targetDir);

				if (!copy($item->getPathname(), $targetPath)) {
					throw new \RuntimeException('Failed to copy: ' . $item->getPathname());
				}
			}
		}
	}

	private static function mmkDir($folder, $perm = 0755) {
		if (is_dir($folder)) {
			return;
		}

		if (mkdir($folder, $perm, true) || is_dir($folder)) {
			return;
		}

		throw new \RuntimeException(sprintf('Directory "%s" was not created', $folder));
	}

	public static function doInstall($target_version = null, $token = null, $create_backup = false) {
		// Verify CSRF token
		if (empty($token) || !hash_equals($_SESSION['install_token'] ?? '', $token)) {
			if (defined('LOCALHOST_BYPASS')) {
				die('<div class="alert alert-error">Security validation failed. Please try again.</div>');
			}
			return false;
		}

		if (empty($target_version) || !is_scalar($target_version)) {
			return false;
		}

		$packageInfo = self::getAvailableOptions();
		if (!isset($packageInfo[$target_version])) {
			return false;
		}

		$rowInstall = $packageInfo[$target_version];
		$base_dir = str_replace('\\', '/', __DIR__);
		$temp_dir = $base_dir . '/_temp_' . uniqid('gs_', true);
		$zip_file = $base_dir . '/fetch.zip';

		try {
			// Ensure we can write to the directory
			if (!self::hasDirPerm()) {
				throw new \RuntimeException('Directory is not writable');
			}

			// Create backup if requested and upgrading
			$is_upgrade = strpos($target_version, 'Upgrade') === 0;
			if ($create_backup && $is_upgrade) {
				self::createBackup($base_dir);
			}

			// Download the file
			self::downloadFile($rowInstall['link'], $zip_file);

			// Verify download
			if (!file_exists($zip_file) || filesize($zip_file) < 1000) {
				throw new \RuntimeException('Downloaded file appears to be corrupted or empty');
			}

			// Extract the zip
			$zip = new ZipArchive;
			if ($zip->open($zip_file) !== true) {
				throw new \RuntimeException('Failed to open the downloaded package');
			}

			// Create temp directory
			self::mmkDir($temp_dir);

			if (!$zip->extractTo($temp_dir)) {
				$zip->close();
				throw new \RuntimeException('Failed to extract the package');
			}
			$zip->close();
			unlink($zip_file);

			// Find the extracted directory
			$dir = '';
			$items = scandir($temp_dir);
			foreach ($items as $name) {
				if ($name !== '.' && $name !== '..' && is_dir($temp_dir . '/' . $name)) {
					$dir = $name;
					break;
				}
			}

			if (empty($dir)) {
				throw new \RuntimeException('No files found in the downloaded package');
			}

			// Move files to destination
			self::moveFiles($temp_dir . '/' . $dir, $base_dir);

			// Clean up
			self::rmdirs($temp_dir);

			// Clear token to prevent reuse
			unset($_SESSION['install_token']);

			// Remove installer
			if (file_exists(__FILE__)) {
				unlink(__FILE__);
			}

			header('Location: ' . $rowInstall['location']);
			return true;

		} catch (\Exception $e) {
			// Clean up on error
			if (file_exists($zip_file)) {
				unlink($zip_file);
			}
			if (is_dir($temp_dir)) {
				self::rmdirs($temp_dir);
			}

			if (defined('LOCALHOST_BYPASS')) {
				die('<div class="alert alert-error">Installation failed: ' . htmlspecialchars($e->getMessage()) . '</div>');
			} else {
				die('<div class="alert alert-error">Installation failed. Please check server permissions and try again.</div>');
			}
		}
	}

	private static function rmdirs($dir) {
		if (!is_dir($dir)) {
			return;
		}

		$objects = scandir($dir);
		foreach ($objects as $object) {
			if ($object === '.' || $object === '..') {
				continue;
			}
			$path = sprintf('%s/%s', $dir, $object);
			if (is_dir($path) && !is_link($path)) {
				self::rmdirs($path);
			} else {
				unlink($path);
			}
		}
		rmdir($dir);
	}

	private static function hasDirPerm() {
		$test_file = __DIR__ . '/installer_test_' . time() . '.tmp';
		$test_content = 'test';

		// Try to create a file
		if (file_put_contents($test_file, $test_content) === false) {
			return false;
		}

		// Try to read it back
		if (file_get_contents($test_file) !== $test_content) {
			unlink($test_file);
			return false;
		}

		// Clean up
		unlink($test_file);
		return true;
	}

	public static function checkPhpRequirement() {
		if (version_compare(PHP_VERSION, '7.4') >= 0) {
			return '<span class="badge success">v.' . PHP_VERSION . '</span>';
		} else {
			return '<span class="badge error">Your PHP version is ' . PHP_VERSION . ' (7.4+ required)</span>';
		}
	}

	public static function checkExtension($ext, $friendly_name, $required = true) {
		if (extension_loaded($ext)) {
			return '<span class="badge success">✓ Enabled</span>';
		} else {
			if ($required) {
				return '<span class="badge error">✗ Not enabled</span>';
			}
			return '<span class="badge warning">⚠ Not enabled (optional)</span>';
		}
	}

	public static function checkModRewrite() {
		$mod_rewrite = false;
		if (function_exists('apache_get_modules')) {
			$mod_rewrite = in_array('mod_rewrite', apache_get_modules());
		} else {
			// For non-Apache servers, assume mod_rewrite equivalent is available
			$mod_rewrite = true;
		}

		return $mod_rewrite ? '<span class="badge success">✓ Enabled</span>' :
			'<span class="badge warning">⚠ Not detected (optional for FancyURLs)</span>';
	}

	public static function getInstallType() {
		return self::isInstalled() ? 'upgrade' : 'new';
	}
}

// Generate CSRF token
$csrf_token = Installer::generateCSRFToken();
$is_installed = Installer::isInstalled();
$install_type = Installer::getInstallType();
$options_count = count(Installer::getAvailableOptions());
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<title>GS-CE Installer v<?php echo $installer_version; ?></title>
	<link rel="icon" type="image/png" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAA3FpVFh0WE1MOmNvbS5hZG9iZS54bXAAAAAAADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IkFkb2JlIFhNUCBDb3JlIDkuMS1jMDAyIDc5LmI3YzY0Y2NmOSwgMjAyNC8wNy8xNi0xMjozOTowNCAgICAgICAgIj4gPHJkZjpSREYgeG1sbnM6cmRmPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5LzAyLzIyLXJkZi1zeW50YXgtbnMjIj4gPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9IiIgeG1sbnM6eG1wTU09Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9tbS8iIHhtbG5zOnN0UmVmPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvc1R5cGUvUmVzb3VyY2VSZWYjIiB4bWxuczp4bXA9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC8iIHhtcE1NOk9yaWdpbmFsRG9jdW1lbnRJRD0ieG1wLmRpZDowNDBhYmFhNy0wYjA3LWZjNDEtOGJiNC0yNDllY2MzMzU4MmUiIHhtcE1NOkRvY3VtZW50SUQ9InhtcC5kaWQ6MDYzRDIxM0YyRkZBMTFGMEFCNzZFRDE5RDE2MkNGNDMiIHhtcE1NOkluc3RhbmNlSUQ9InhtcC5paWQ6MDYzRDIxM0UyRkZBMTFGMEFCNzZFRDE5RDE2MkNGNDMiIHhtcDpDcmVhdG9yVG9vbD0iQWRvYmUgUGhvdG9zaG9wIENDIDIwMTkgKFdpbmRvd3MpIj4gPHhtcE1NOkRlcml2ZWRGcm9tIHN0UmVmOmluc3RhbmNlSUQ9InhtcC5paWQ6QjRCMEEwNDVENEU4MTFFRkFFOTZDQTFCRjkwQTZGMTUiIHN0UmVmOmRvY3VtZW50SUQ9InhtcC5kaWQ6QjRCMEEwNDZENEU4MTFFRkFFOTZDQTFCRjkwQTZGMTUiLz4gPC9y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">
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<style>
		*,
		*::before,
		*::after {
			box-sizing: border-box;
		}

		:root {
			--bg-color: #ffffff;
			--bg-secondary-color: #f3f3f6;
			--font-color: #222;
			--color-grey: #d4d4d4;
			--color-darkGrey: #7f8c8d;
			--color-primary: #cf3805;
			--color-lightGrey: #d5d5d5;
			--color-grey-light: #e0e0e0;
			--shadow-color: rgba(0, 0, 0, 0.08);
			--font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
			--font-size: 1rem;
			--line-height: 1.6;
			--container-width: 1200px;
		}

		body.dark {
			--bg-color: #1a1a1a;
			--bg-secondary-color: #252528;
			--font-color: #e8e8e8;
			--color-grey: #555;
			--color-grey-light: #3a3a3a;
			--color-darkGrey: #aaa;
			--shadow-color: rgba(0, 0, 0, 0.3);
		}

		html {
			font-size: 100%;
		}

		body {
			margin: 0;
			padding: 0;
			font-family: var(--font-family);
			font-size: var(--font-size);
			line-height: var(--line-height);
			background: var(--bg-color);
			color: var(--font-color);
			transition: background 0.3s, color 0.3s;
		}

		.container {
			max-width: var(--container-width);
			margin: 0 auto;
			padding: 0 15px;
		}

		.row {
			display: flex;
			flex-wrap: wrap;
			margin-left: -10px;
			margin-right: -10px;
		}

		.row.is-center {
			justify-content: center; 
			margin:15px 0;
		}

		.col {
			flex: 1;
			padding: 0 10px;
		}

		.col-1,
		.col-2,
		.col-3,
		.col-4,
		.col-5,
		.col-6,
		.col-7,
		.col-8,
		.col-9,
		.col-10,
		.col-11,
		.col-12 {
			flex: 0 0 auto;
			padding: 0 10px;
		}

		.col-1 {
			width: 8.333%;
		}
		.col-2 {
			width: 16.667%;
		}
		.col-3 {
			width: 25%;
		}
		.col-4 {
			width: 33.333%;
		}
		.col-5 {
			width: 41.667%;
		}
		.col-6 {
			width: 50%;
		}
		.col-7 {
			width: 58.333%;
		}
		.col-8 {
			width: 66.667%;
		}
		.col-9 {
			width: 75%;
		}
		.col-10 {
			width: 83.333%;
		}
		.col-11 {
			width: 91.667%;
		}
		.col-12 {
			width: 100%;
		}

		.text-center {
			text-align: center;
		}
		.text-right {
			text-align: right;
		}
		.text-primary {
			color: var(--color-primary);
		}
		.text-muted {
			opacity: 0.7;
		}

		.card {
			background: var(--bg-secondary-color);
			border-radius: 8px;
			padding: 20px;
			margin-bottom: 20px;
			box-shadow: 0 2px 8px var(--shadow-color);
		}

		.padding {
			padding: 10px;
		}
		.padding-big {
			padding: 25px;
		}
		.small {
			font-size: 0.85em;
		}

		.button {
			display: inline-block;
			padding: 8px 16px;
			border: 1px solid var(--color-grey);
			border-radius: 4px;
			background: var(--bg-secondary-color);
			color: var(--font-color);
			cursor: pointer;
			text-decoration: none;
			font-size: 1em;
			transition: all 0.2s;
		}

		.button:hover {
			background: var(--color-grey);
		}

		.button.success {
			background: #28a745;
			color: white;
			border-color: #28a745;
		}

		.button.success:hover {
			background: #218838;
			border-color: #1e7e34;
		}

		.button.error {
			background: #dc3545;
			color: white;
			border-color: #dc3545;
		}

		.btn-small {
			padding: 4px 10px;
			font-size: 0.85em;
		}

		.btn-large {
			padding: 12px 48px;
			font-size: 1.1em;
			border-radius: 8px;
		}

		.btn-icon {
			margin-right: 8px;
		}

		.button:disabled {
			opacity: 0.6;
			cursor: not-allowed;
		}

		.button.loading {
			opacity: 0.8;
		}

		hr {
			border: 0;
			border-top: 1px solid var(--color-grey-light);
			margin: 20px 0;
		}

		table {
			width: 100%;
			border-collapse: collapse;
		}

		.striped tbody tr:nth-child(odd) {
			background: var(--bg-secondary-color);
		}

		th,
		td {
			padding: 10px 12px;
			text-align: left;
			border-bottom: 1px solid var(--color-grey-light);
		}

		th {
			font-weight: 600;
		}

		pre {
			background: var(--bg-secondary-color);
			padding: 15px;
			border-radius: 4px;
			overflow-x: auto;
			font-family: "Courier New", monospace;
			font-size: 0.85em;
			color: #de5285;
			border: 1px solid var(--color-grey-light);
			margin: 10px 0;
			background-color:#fff;
		}

		code {
			font-family: "Courier New", monospace;
			background: var(--bg-secondary-color);
			padding: 2px 6px;
			border-radius: 3px;
			font-size: 0.9em;
		}

		dl {
			margin: 0;
		}
		dt {
			font-weight: bold;
			margin-top: 10px;
		}
		dd {
			margin-left: 0;
			margin-bottom: 8px;
			line-height: 1.5;
		}

		a {
			color: var(--color-primary);
			text-decoration: none;
		}
		a:hover {
			text-decoration: underline;
		}

		/* ---- Custom Styles ---- */
		.package-card {
			border: 2px solid var(--color-grey-light);
			border-radius: 12px;
			padding: 0;
			transition: all 0.3s ease;
			cursor: pointer;
			height: 100%;
			background: var(--bg-color);
			box-shadow: 0 2px 8px var(--shadow-color);
			margin-bottom: 20px;
		}

		.package-card:hover {
			transform: translateY(-4px);
			box-shadow: 0 8px 24px var(--shadow-color);
			border-color: var(--color-primary);
		}

		.package-card:has(input:checked) {
			border-color: var(--color-primary);
			background: var(--bg-secondary-color);
			box-shadow: 0 0 0 4px rgba(207, 56, 5, 0.15);
		}

		.package-header {
			padding: 20px 20px 10px;
			border-bottom: 1px solid var(--color-grey-light);
			text-align: center;
		}

		.package-icon {
			font-size: 2.5em;
			display: block;
			margin-bottom: 8px;
		}

		.package-title {
			margin: 0;
			font-size: 1.1em;
			font-weight: 600;
		}

		.package-body {
			padding: 15px 20px;
			min-height: 100px;
		}

		.package-description {
			margin: 0 0 12px 0;
			font-size: 0.9em;
			opacity: 0.8;
			text-align: center;
		}

		.package-features {
			display: flex;
			gap: 8px;
			flex-wrap: wrap;
			justify-content: center;
		}

		.package-footer {
			padding: 15px 20px;
			border-top: 1px solid var(--color-grey-light);
			text-align: center;
		}

		.badge {
			display: inline-block;
			padding: 4px 12px;
			border-radius: 20px;
			font-size: 0.75em;
			font-weight: 500;
			background: var(--color-primary);
			color: white;
			white-space: nowrap;
		}

		.badge-secondary {
			background: var(--color-grey-light);
			color: var(--font-color);
		}

		.badge.success {
			background: #28a745;
			color: white;
		}

		.badge.error {
			background: #dc3545;
			color: white;
		}

		.badge.warning {
			background: #ffc107;
			color: #333;
		}

		.radio-card {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			cursor: pointer;
			font-weight: 500;
		}

		.radio-card input[type="radio"] {
			width: 18px;
			height: 18px;
			cursor: pointer;
		}

		.alert {
			padding: 16px 20px;
			border-radius: 8px;
			margin-bottom: 20px;
			border: 1px solid transparent;
		}

		.alert-error {
			background: #f8d7da;
			border-color: #f5c6cb;
			color: #721c24;
		}

		.alert-error ul {
			margin: 8px 0 0 20px;
			padding: 0;
		}

		.alert-error li {
			margin-bottom: 4px;
		}

		.dark .alert-error {
			background: #2d1b1e;
			border-color: #4a1e24;
			color: #f5c6cb;
		}

		.alert-success {
			background: #d4edda;
			border-color: #c3e6cb;
			color: #155724;
		}

		.dark .alert-success {
			background: #1a2e1d;
			border-color: #2a4a2e;
			color: #b8e6c0;
		}

		.alert-info {
			background: #d1ecf1;
			border-color: #bee5eb;
			color: #0c5460;
		}

		.dark .alert-info {
			background: #1a2a30;
			border-color: #2a4048;
			color: #b8e6f0;
		}

		.requirement-table {
			font-size: 0.9em;
		}

		.requirement-table th,
		.requirement-table td {
			padding: 8px 12px;
		}

		.upgrade-info dd {
			margin-bottom: 8px;
			line-height: 1.5;
		}

		.warning-icon {
			display: inline-block;
			width: 1.2em;
			height: 1.2em;
			background-repeat: no-repeat;
			background-size: 100% 100%;
			vertical-align: middle;
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cg fill='none'%3E%3Cpath d='m12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035q-.016-.005-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427q-.004-.016-.017-.018m.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093q.019.005.029-.008l.004-.014l-.034-.614q-.005-.018-.02-.022m-.715.002a.02.02 0 0 0-.027.006l-.006.014l-.034.614q.001.018.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01z'/%3E%3Cpath fill='%23f0a030' d='M12 2c5.523 0 10 4.477 10 10s-4.477 10-10 10S2 17.523 2 12S6.477 2 12 2m0 13a1 1 0 1 0 0 2a1 1 0 0 0 0-2m0-9a1 1 0 0 0-.993.883L11 7v6a1 1 0 0 0 1.993.117L13 13V7a1 1 0 0 0-1-1'/%3E%3C/g%3E%3C/svg%3E");
		}

		.info-icon {
			display: inline-block;
			width: 1.2em;
			height: 1.2em;
			background-repeat: no-repeat;
			background-size: 100% 100%;
			vertical-align: middle;
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%23CC7A00' d='M12 17q.425 0 .713-.288T13 16v-4q0-.425-.288-.712T12 11t-.712.288T11 12v4q0 .425.288.713T12 17m0-8q.425 0 .713-.288T13 8t-.288-.712T12 7t-.712.288T11 8t.288.713T12 9m0 13q-2.075 0-3.9-.788t-3.175-2.137T2.788 15.9T2 12t.788-3.9t2.137-3.175T8.1 2.788T12 2t3.9.788t3.175 2.137T21.213 8.1T22 12t-.788 3.9t-2.137 3.175t-3.175 2.138T12 22'/%3E%3C/svg%3E");
		}

		.ok-icon {
			display: inline-block;
			width: 1.2em;
			height: 1.2em;
			background-repeat: no-repeat;
			background-size: 100% 100%;
			vertical-align: middle;
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpath fill='%2300A300' d='M1 6a5 5 0 1 1 10 0A5 5 0 0 1 1 6m7.354-.896a.5.5 0 1 0-.708-.708L5.5 6.543L4.354 5.396a.5.5 0 1 0-.708.708l1.5 1.5a.5.5 0 0 0 .708 0z'/%3E%3C/svg%3E");
		}

		.install-status {
			display: none;
			margin-top: 20px;
		}

		.install-status.active {
			display: block;
		}

		/* Progress Bar */
		.progress-container {
			display: none;
			margin-top: 20px;
			margin-bottom: 20px;
			padding: 20px;
			background: var(--bg-secondary-color);
			border-radius: 8px;
			border: 1px solid var(--color-grey-light);
		}

		.progress-container.active {
			display: block;
		}

		.progress-bar {
			width: 100%;
			height: 24px;
			background: var(--color-grey-light);
			border-radius: 12px;
			overflow: hidden;
			position: relative;
			margin: 10px 0;
		}

		.progress-bar-fill {
			height: 100%;
			width: 0%;
			background: linear-gradient(90deg, var(--color-primary), #f05a28);
			border-radius: 12px;
			transition: width 0.5s ease;
			position: relative;
		}

		.progress-bar-fill::after {
			content: '';
			position: absolute;
			top: 0;
			left: 0;
			right: 0;
			bottom: 0;
			background: linear-gradient(
				90deg,
				transparent,
				rgba(255, 255, 255, 0.3),
				transparent
			);
			animation: shimmer 1.5s infinite;
		}

		@keyframes shimmer {
			0% {
				transform: translateX(-100%);
			}
			100% {
				transform: translateX(100%);
			}
		}

		.progress-text {
			display: flex;
			justify-content: space-between;
			font-size: 0.9em;
			margin-top: 5px;
		}

		.progress-label {
			font-weight: 500;
		}

		.progress-percent {
			color: var(--color-primary);
			font-weight: 600;
		}

		.status-message {
			margin-top: 10px;
			padding: 10px;
			border-radius: 4px;
			background: var(--bg-color);
			border-left: 3px solid var(--color-primary);
			font-size: 0.95em;
			min-height: 40px;
		}

		.status-message .spinner {
			display: inline-block;
			width: 16px;
			height: 16px;
			border: 2px solid var(--color-grey-light);
			border-top: 2px solid var(--color-primary);
			border-radius: 50%;
			animation: spin 0.8s linear infinite;
			margin-right: 8px;
			vertical-align: middle;
		}

		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}

		.status-message .check {
			display: inline-block;
			color: #28a745;
			font-weight: bold;
			margin-right: 8px;
			vertical-align: middle;
		}

		/* Backup checkbox */
		.backup-option {
			margin: 15px 0;
			padding: 15px;
			background: var(--bg-secondary-color);
			border-radius: 8px;
			border: 1px solid var(--color-grey-light);
		}

		.backup-option label {
			cursor: pointer;
			font-weight: 500;
			display: flex;
			align-items: center;
			gap: 10px;
		}

		.backup-option input[type="checkbox"] {
			width: 18px;
			height: 18px;
			cursor: pointer;
		}

		.backup-option .backup-info {
			font-size: 0.85em;
			opacity: 0.8;
			margin-left: 30px;
			margin-top: 5px;
		}

		.backup-option .backup-location {
			font-family: monospace;
			background: var(--bg-color);
			padding: 2px 8px;
			border-radius: 4px;
			font-size: 0.9em;
		}

		.backup-option .backup-icon {
			font-size: 1.2em;
		}

		@media (max-width: 768px) {
			.col-4 {
				width: 100%;
			}
			.col-6 {
				width: 100%;
			}
			.btn-large {
				padding: 10px 30px;
				font-size: 1em;
				width: 100%;
			}
			.requirement-table {
				font-size: 0.8em;
			}
			.requirement-table th,
			.requirement-table td {
				padding: 4px 8px;
			}
			.package-card {
				margin-bottom: 15px;
			}
			.container {
				padding: 0 10px;
			}
			.backup-option .backup-info {
				margin-left: 0;
			}
		}
	</style>
	<script>
		function switchMode(el) {
			var bodyClass = document.body.classList;
			if (bodyClass.contains('dark')) {
				el.innerHTML = '☀️';
				bodyClass.remove('dark');
			} else {
				el.innerHTML = '🌙';
				bodyClass.add('dark');
			}
		}

		function simulateProgress() {
			var progressContainer = document.getElementById('progress-container');
			var progressFill = document.getElementById('progress-fill');
			var progressPercent = document.getElementById('progress-percent');
			var statusMessage = document.getElementById('status-message');
			var installBtn = document.querySelector('button[type="submit"]');
			
			progressContainer.className = 'progress-container active';
			
			var steps = [
				{ progress: 10, message: '📥 Downloading package...' },
				{ progress: 30, message: '📦 Extracting files...' },
				{ progress: 50, message: '📂 Moving files to destination...' },
				{ progress: 70, message: '⚙️ Configuring installation...' },
				{ progress: 90, message: '🧹 Cleaning up temporary files...' },
				{ progress: 100, message: '✅ Installation complete! Redirecting...' }
			];
			
			// Check if backup was requested
			var backupCheckbox = document.getElementById('create_backup');
			if (backupCheckbox && backupCheckbox.checked) {
				// Insert backup step at the beginning
				var backupStep = { progress: 5, message: '💾 Creating backup...' };
				steps.unshift(backupStep);
			}
			
			var currentStep = 0;
			
			function updateProgress() {
				if (currentStep >= steps.length) return;
				
				var step = steps[currentStep];
				progressFill.style.width = step.progress + '%';
				progressPercent.textContent = step.progress + '%';
				statusMessage.innerHTML = '<span class="spinner"></span> ' + step.message;
				
				currentStep++;
				
				if (currentStep < steps.length) {
					setTimeout(updateProgress, 800 + Math.random() * 400);
				}
			}
			
			// Start progress after a short delay
			setTimeout(updateProgress, 300);
		}

		function showLoading() {
			var btn = document.querySelector('button[type="submit"]');
			var statusDiv = document.getElementById('install-status');

			if (btn) {
				btn.innerHTML = '<span class="btn-icon">⏳</span> Installing... Please wait';
				btn.disabled = true;
				btn.classList.add('loading');
			}

			if (statusDiv) {
				statusDiv.className = 'install-status active alert alert-info';
				statusDiv.innerHTML = '<strong>⏳ Installation in progress...</strong><p>This may take a few minutes. Please do not close this window.</p>';
			}

			// Start progress simulation
			simulateProgress();

			return true;
		}

		// Show/hide backup option based on selection
		document.addEventListener('DOMContentLoaded', function() {
			var upgradeInfo = document.getElementById('upgrade-info');
			var backupOption = document.getElementById('backup-option');
			var radioButtons = document.querySelectorAll('input[name="target"]');

			function toggleUpgradeInfo() {
				if (!upgradeInfo) return;
				var show = false;
				var isUpgrade = false;
				radioButtons.forEach(function(radio) {
					if (radio.checked && (radio.value === 'Upgrade' || radio.value === 'UpgradeBeta')) {
						show = true;
						isUpgrade = true;
					}
				});
				upgradeInfo.style.display = show ? 'block' : 'none';
				
				// Show backup option only for upgrades
				if (backupOption) {
					backupOption.style.display = isUpgrade ? 'block' : 'none';
				}
			}

			radioButtons.forEach(function(radio) {
				radio.addEventListener('change', toggleUpgradeInfo);
			});

			// Initial check
			toggleUpgradeInfo();

			// Auto-select card on click
			var cards = document.querySelectorAll('.package-card');
			cards.forEach(function(card) {
				card.addEventListener('click', function(e) {
					var radio = this.querySelector('input[type="radio"]');
					if (radio && !e.target.closest('.radio-card')) {
						radio.checked = true;
						var event = new Event('change', { bubbles: true });
						radio.dispatchEvent(event);
					}
				});
			});
		});
	</script>
</head>
<body>
	<div class="container">
		<div class="row">
			<div class="col-11">
				<h1 class="text-primary text-center" style="font-weight:600; margin:20px 0;">
					<svg style="vertical-align:middle;" width="1.2em" height="1.2em" viewBox="0 0 500 500" version="1.1" xmlns="http://www.w3.org/2000/svg">
						<g id="#000000fe">
							<path fill="#000000" opacity="1.00" d=" M 242.40 0.00 L 256.59 0.00 C 297.90 1.22 338.93 12.52 374.70 33.29 C 423.60 61.35 462.49 106.42 482.91 158.99 C 493.41 185.54 498.84 213.93 500.00 242.43 L 500.00 257.55 C 498.57 298.78 487.19 339.69 466.35 375.34 C 435.92 428.06 385.61 468.96 327.66 487.69 C 304.76 495.24 280.75 499.16 256.67 500.00 L 243.37 500.00 C 198.43 498.71 153.85 485.32 115.96 461.06 C 86.50 442.33 61.03 417.37 41.71 388.29 C 15.89 349.76 1.55 303.89 0.00 257.58 L 0.00 242.45 C 1.04 216.48 5.62 190.61 14.43 166.13 C 34.05 110.56 74.16 62.61 125.28 33.30 C 160.76 12.68 201.43 1.44 242.40 0.00 M 240.08 5.21 C 193.20 6.78 147.06 22.66 108.82 49.75 C 77.93 71.67 51.95 100.66 34.10 134.11 C -1.70 199.60 -4.65 281.83 26.18 349.77 C 52.21 408.73 102.64 456.35 162.93 479.10 C 208.55 496.69 259.48 499.50 307.02 488.45 C 343.60 479.56 378.23 462.35 407.02 438.04 C 435.35 414.31 458.50 384.25 473.51 350.43 C 493.16 307.62 499.18 259.02 492.19 212.53 C 485.22 166.10 464.21 121.94 432.88 86.99 C 409.84 61.25 381.46 40.19 349.84 26.21 C 315.58 10.65 277.63 3.68 240.08 5.21 Z" />
						</g>
						<g id="#f6f6f6ff">
							<path fill="#f6f6f6" opacity="1.00" d=" M 240.08 5.21 C 277.63 3.68 315.58 10.65 349.84 26.21 C 381.46 40.19 409.84 61.25 432.88 86.99 C 464.21 121.94 485.22 166.10 492.19 212.53 C 499.18 259.02 493.16 307.62 473.51 350.43 C 458.50 384.25 435.35 414.31 407.02 438.04 C 378.23 462.35 343.60 479.56 307.02 488.45 C 259.48 499.50 208.55 496.69 162.93 479.10 C 102.64 456.35 52.21 408.73 26.18 349.77 C -4.65 281.83 -1.70 199.60 34.10 134.11 C 51.95 100.66 77.93 71.67 108.82 49.75 C 147.06 22.66 193.20 6.78 240.08 5.21 M 102.22 92.26 C 83.95 102.02 69.18 118.04 60.85 137.00 C 51.14 158.85 49.97 183.78 54.47 207.04 C 59.18 230.87 72.90 253.24 93.48 266.52 C 115.84 281.38 143.79 285.23 170.06 282.52 C 195.60 279.97 220.95 269.51 238.65 250.54 C 238.74 221.51 238.67 192.47 238.68 163.44 C 208.14 163.42 177.59 163.47 147.05 163.41 C 146.94 175.60 147.03 187.80 147.00 200.00 C 164.65 200.00 182.29 199.99 199.94 200.00 C 199.93 209.92 199.95 219.84 199.92 229.77 C 186.07 241.69 166.87 245.07 149.10 243.62 C 133.32 242.35 117.90 234.41 108.77 221.30 C 98.08 206.31 95.64 186.92 97.98 169.03 C 99.72 155.25 105.91 141.78 116.50 132.58 C 126.73 123.39 140.56 119.15 154.12 118.70 C 172.75 117.68 191.74 124.66 204.98 137.86 C 214.16 128.70 223.43 119.63 232.59 110.46 C 217.84 95.01 198.39 83.70 177.11 80.49 C 151.80 76.68 124.88 79.90 102.22 92.26 M 302.41 87.40 C 290.31 92.61 279.31 101.07 272.65 112.58 C 264.76 126.11 263.84 142.99 268.32 157.80 C 271.99 169.92 281.27 179.70 292.38 185.44 C 307.12 193.23 323.74 196.50 340.13 198.58 C 349.98 199.73 359.99 200.96 369.23 204.76 C 374.07 206.78 378.90 210.00 380.92 215.05 C 383.74 221.80 381.38 230.15 375.54 234.53 C 366.06 241.83 353.50 242.98 341.94 242.79 C 321.11 242.54 300.40 235.47 284.07 222.48 C 276.12 233.48 268.36 244.62 260.34 255.57 C 277.30 269.58 298.57 277.59 320.09 281.29 C 340.25 284.46 361.33 284.27 380.93 278.11 C 395.76 273.42 409.89 264.71 418.43 251.43 C 427.53 237.60 429.16 219.66 424.55 203.96 C 420.92 191.67 411.86 181.55 400.88 175.20 C 384.86 165.84 366.29 162.18 348.08 160.01 C 337.62 158.49 326.58 157.19 317.43 151.49 C 310.26 147.17 308.10 136.79 312.83 129.92 C 317.23 123.77 324.75 120.88 331.87 119.28 C 354.68 114.82 379.10 120.26 398.04 133.68 C 405.21 122.99 412.34 112.26 419.54 101.59 C 401.55 88.75 379.79 81.83 357.90 79.80 C 339.19 78.26 319.78 79.85 302.41 87.40 M 170.82 308.68 C 154.85 311.99 139.82 321.14 130.83 334.92 C 120.21 350.59 117.74 370.62 121.03 388.97 C 123.94 405.04 133.06 420.18 146.81 429.23 C 161.91 439.51 180.99 442.47 198.88 440.39 C 213.87 438.63 228.01 431.57 238.77 421.05 C 232.90 414.50 227.04 407.94 221.15 401.41 C 209.86 412.62 192.48 416.73 177.21 412.74 C 168.52 410.43 160.66 404.84 156.01 397.10 C 150.35 387.91 149.01 376.60 150.54 366.07 C 151.98 356.32 156.93 346.89 164.99 341.05 C 180.85 329.61 204.58 331.81 218.49 345.42 C 224.57 339.18 230.81 333.09 236.94 326.89 C 230.24 320.35 222.37 314.92 213.62 311.53 C 200.07 306.21 184.97 305.82 170.82 308.68 M 255.88 309.82 C 255.87 352.59 255.87 395.35 255.88 438.12 C 289.17 438.13 322.46 438.13 355.75 438.12 C 355.76 429.67 355.73 421.23 355.76 412.79 C 332.20 412.70 308.64 412.76 285.08 412.76 C 285.05 403.56 285.06 394.37 285.07 385.18 C 306.73 385.19 328.39 385.19 350.06 385.18 C 350.07 377.10 350.07 369.02 350.06 360.94 C 328.39 360.93 306.73 360.95 285.06 360.93 C 285.06 352.29 285.06 343.65 285.06 335.00 C 307.94 334.98 330.82 335.03 353.70 334.98 C 353.58 326.57 353.89 318.15 353.55 309.75 C 320.99 309.91 288.44 309.76 255.88 309.82 Z" />
						</g>
						<g id="#283840ff">
							<path fill="#283840" opacity="1.00" d=" M 102.22 92.26 C 124.88 79.90 151.80 76.68 177.11 80.49 C 198.39 83.70 217.84 95.01 232.59 110.46 C 223.43 119.63 214.16 128.70 204.98 137.86 C 191.74 124.66 172.75 117.68 154.12 118.70 C 140.56 119.15 126.73 123.39 116.50 132.58 C 105.91 141.78 99.72 155.25 97.98 169.03 C 95.64 186.92 98.08 206.31 108.77 221.30 C 117.90 234.41 133.32 242.35 149.10 243.62 C 166.87 245.07 186.07 241.69 199.92 229.77 C 199.95 219.84 199.93 209.92 199.94 200.00 C 182.29 199.99 164.65 200.00 147.00 200.00 C 147.03 187.80 146.94 175.60 147.05 163.41 C 177.59 163.47 208.14 163.42 238.68 163.44 C 238.67 192.47 238.74 221.51 238.65 250.54 C 220.95 269.51 195.60 279.97 170.06 282.52 C 143.79 285.23 115.84 281.38 93.48 266.52 C 72.90 253.24 59.18 230.87 54.47 207.04 C 49.97 183.78 51.14 158.85 60.85 137.00 C 69.18 118.04 83.95 102.02 102.22 92.26 Z" />
							<path fill="#283840" opacity="1.00" d=" M 302.41 87.40 C 319.78 79.85 339.19 78.26 357.90 79.80 C 379.79 81.83 401.55 88.75 419.54 101.59 C 412.34 112.26 405.21 122.99 398.04 133.68 C 379.10 120.26 354.68 114.82 331.87 119.28 C 324.75 120.88 317.23 123.77 312.83 129.92 C 308.10 136.79 310.26 147.17 317.43 151.49 C 326.58 157.19 337.62 158.49 348.08 160.01 C 366.29 162.18 384.86 165.84 400.88 175.20 C 411.86 181.55 420.92 191.67 424.55 203.96 C 429.16 219.66 427.53 237.60 418.43 251.43 C 409.89 264.71 395.76 273.42 380.93 278.11 C 361.33 284.27 340.25 284.46 320.09 281.29 C 298.57 277.59 277.30 269.58 260.34 255.57 C 268.36 244.62 276.12 233.48 284.07 222.48 C 300.40 235.47 321.11 242.54 341.94 242.79 C 353.50 242.98 366.06 241.83 375.54 234.53 C 381.38 230.15 383.74 221.80 380.92 215.05 C 378.90 210.00 374.07 206.78 369.23 204.76 C 359.99 200.96 349.98 199.73 340.13 198.58 C 323.74 196.50 307.12 193.23 292.38 185.44 C 281.27 179.70 271.99 169.92 268.32 157.80 C 263.84 142.99 264.76 126.11 272.65 112.58 C 279.31 101.07 290.31 92.61 302.41 87.40 Z" />
						</g>
						<g id="#cf3805ff">
							<path fill="#cf3805" opacity="1.00" d=" M 170.82 308.68 C 184.97 305.82 200.07 306.21 213.62 311.53 C 222.37 314.92 230.24 320.35 236.94 326.89 C 230.81 333.09 224.57 339.18 218.49 345.42 C 204.58 331.81 180.85 329.61 164.99 341.05 C 156.93 346.89 151.98 356.32 150.54 366.07 C 149.01 376.60 150.35 387.91 156.01 397.10 C 160.66 404.84 168.52 410.43 177.21 412.74 C 192.48 416.73 209.86 412.62 221.15 401.41 C 227.04 407.94 232.90 414.50 238.77 421.05 C 228.01 431.57 213.87 438.63 198.88 440.39 C 180.99 442.47 161.91 439.51 146.81 429.23 C 133.06 420.18 123.94 405.04 121.03 388.97 C 117.74 370.62 120.21 350.59 130.83 334.92 C 139.82 321.14 154.85 311.99 170.82 308.68 Z" />
							<path fill="#cf3805" opacity="1.00" d=" M 255.88 309.82 C 288.44 309.76 320.99 309.91 353.55 309.75 C 353.89 318.15 353.58 326.57 353.70 334.98 C 330.82 335.03 307.94 334.98 285.06 335.00 C 285.06 343.65 285.06 352.29 285.06 360.93 C 306.73 360.95 328.39 360.93 350.06 360.94 C 350.07 369.02 350.07 377.10 350.06 385.18 C 328.39 385.19 306.73 385.19 285.07 385.18 C 285.06 394.37 285.05 403.56 285.08 412.76 C 308.64 412.76 332.20 412.70 355.76 412.79 C 355.73 421.23 355.76 429.67 355.75 438.12 C 322.46 438.13 289.17 438.13 255.88 438.12 C 255.87 395.35 255.87 352.59 255.88 309.82 Z" />
						</g>
					</svg>
					GetSimple CMS Community Edition
				</h1>
			</div>
			<div class="col-1 text-right padding-big">
				<a href="javascript:void(0)" onclick="switchMode(this)" style="font-size:1.2em;">☀️</a>
			</div>
		</div>

		<hr>

		<div class="row">
			<div class="col">
				<?php if ($is_installed): ?>
					<h3 class="text-primary">🔄 Upgrade Options</h3>
					<p class="text-muted">Select an upgrade option for your existing installation.</p>
				<?php else: ?>
					<h3 class="text-primary">🚀 Installation Options</h3>
					<p class="text-muted">Select the installation type that best suits your needs.</p>
				<?php endif; ?>
			</div>
		</div>

		<!-- System Requirements - Above install buttons -->
		<div class="row">
			<div class="col-12">
				<div class="card" style="max-width: 800px; margin: 0 auto;">
					<h4 class="text-primary text-center">📋 System Requirements</h4>
					<table class="striped requirement-table">
						<thead>
							<tr>
								<th>Requirement</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>PHP 7.4+</td>
								<td><?php echo Installer::checkPhpRequirement(); ?></td>
							</tr>
							<tr>
								<td>cURL Extension</td>
								<td><?php echo Installer::checkExtension('curl', 'cURL'); ?></td>
							</tr>
							<tr>
								<td>GD Extension</td>
								<td><?php echo Installer::checkExtension('gd', 'GD'); ?></td>
							</tr>
							<tr>
								<td>Zip Extension</td>
								<td><?php echo Installer::checkExtension('zip', 'Zip'); ?></td>
							</tr>
							<tr>
								<td>OpenSSL Extension</td>
								<td><?php echo Installer::checkExtension('openssl', 'OpenSSL'); ?></td>
							</tr>
							<tr>
								<td>SimpleXML Extension</td>
								<td><?php echo Installer::checkExtension('SimpleXML', 'SimpleXML'); ?></td>
							</tr>
							<tr>
								<td>Apache Mod Rewrite</td>
								<td><?php echo Installer::checkModRewrite(); ?></td>
							</tr>
							<tr>
								<td>Folder Permissions</td>
								<td><?php echo is_writable(__DIR__) ?
										'<span class="badge success">✓ Writable</span>' :
										'<span class="badge error">✗ Not writable</span>'; ?>
								</td>
							</tr>
							<tr>
								<td>allow_url_fopen</td>
								<td><?php echo ini_get('allow_url_fopen') ?
										'<span class="badge success">✓ Enabled</span>' :
										'<span class="badge error">✗ Disabled</span>'; ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>

		<!-- Progress Bar - MOVED OUTSIDE FORM, below System Requirements -->
		<div class="row is-center">
			<div class="col-12" style="max-width: 800px; margin: 0 auto;">
				<div id="progress-container" class="progress-container">
					<div class="progress-bar">
						<div id="progress-fill" class="progress-bar-fill" style="width: 0%;"></div>
					</div>
					<div class="progress-text">
						<span class="progress-label">Installing...</span>
						<span id="progress-percent" class="progress-percent">0%</span>
					</div>
					<div id="status-message" class="status-message">
						<span class="spinner"></span> Initializing...
					</div>
				</div>
			</div>
		</div>

		<div style="margin: 20px 0;"></div>

		<form method="post" action="?" onsubmit="return showLoading()">
			<input type="hidden" name="token" value="<?php echo $csrf_token; ?>">

			<div class="row is-center">
				<?php
				$problems = Installer::hasProblem();
				if ($problems):
					echo '<div class="col-12">' . $problems . '</div>';
				else:
					echo Installer::items($default ?? null);
				endif;
				?>
			</div>

			<!-- Backup Option (inside form so checkbox value is submitted) -->
			<div class="row is-center">
				<div class="col-12">
					<div id="backup-option" class="backup-option" style="display:none; max-width: 600px; margin: 0 auto;">
						<label>
							<span class="backup-icon">💾</span>
							<input type="checkbox" name="create_backup" id="create_backup" value="1" checked>
							<strong>Create backup before upgrading</strong>
						</label>
						<div class="backup-info">
							Backup will be saved to: <span class="backup-location">/backups/zip/YYYYMMDDHHmmss.zip</span>
							<br>This allows you to restore your site if something goes wrong.
						</div>
					</div>
				</div>
			</div>

			<div class="row">
				<div class="col-12 text-center" style="margin-top: 10px;">
					<?php if (!$problems): ?>
						<button type="submit" class="button success btn-large">
							<span class="btn-icon">⚡</span>
							<?php echo $is_installed ? 'Upgrade' : 'Install'; ?>
						</button>
						<p class="text-muted small" style="margin-top: 10px;">
							<span class="warning-icon" style="vertical-align:middle;"></span>
							<strong>Important:</strong> <?php echo $is_installed ? 'Back up your site before upgrading!' : 'Back up any existing data before installing!'; ?>
						</p>
						<div id="install-status" class="install-status"></div>
					<?php endif; ?>
				</div>
			</div>
		</form>

		<hr>

		<div class="row">
			<div class="col-8 card" id="upgrade-info" style="display:none; margin: 0 auto;">
				<h4 class="text-primary">⚠️ Upgrade Information</h4>
				<dl>
					<dt><strong>Before You Upgrade:</strong></dt>
					<dd><span class="warning-icon"></span> <strong>Always create a complete backup</strong> before upgrading!</dd>
					<dd><span class="warning-icon"></span> GetSimple v3.3.16 or newer is required for upgrades.</dd>
					<dd><span class="warning-icon"></span> If you renamed the default <code>/admin/</code> folder, revert it before applying the update.</dd>
					<dd><span class="info-icon"></span> Plugins may require updating, especially when migrating from older PHP versions.</dd>
				</dl>

				<h4 class="text-primary" style="margin-top: 20px;">📝 Configuration Updates</h4>
				<p><span class="ok-icon"></span> Update your existing <code>gsconfig.php</code>:</p>

				<p><strong>Add these new settings:</strong></p>
				<pre># Login Page Default Language
$LANG = 'en_EN'; // es_ES, pl_PL, de_DE, uk_UK, etc.

# Sort admin page list by title or menu
define('GSSORTPAGELISTBY','menu');

# Set CodeMirror Theme (blackboard or default)
define('GSCMTHEME','blackboard');</pre>

				<p><strong>Replace the WYSIWYG section:</strong></p>
				<pre># WYSIWYG toolbars (advanced, basic, advanced, island, CEbar or [custom config])
define('GSEDITORTOOL', "CEbar");

# WYSIWYG Editor Options
define('GSEDITOROPTIONS', '
extraPlugins:"fontawesome5,youtube,codemirror,cmsgrid,colorbutton,oembed,simplebutton,spacingsliders",
disableNativeSpellChecker : false,
forcePasteAsPlainText : true
');</pre>
			</div>
		</div>

		<hr>

		<footer>
			<div class="row padding">
				<div class="col-6">
					💝 Your <a href="https://getsimple-ce.ovh/donate" target="_blank">donations</a> keep GetSimple CMS CE alive.
				</div>
				<div class="col-6 text-right">
					Made with ❤️ by the <span class="text-primary">GS-CE team</span> &copy; <?php echo date("Y"); ?>
				</div>
			</div>
		</footer>

		<!-- 
		  (\ /)
		  (^.^) -{hola}
		 C(")(")
		-->

	</div>
</body>
</html>