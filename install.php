<?php

/* **********
* Plugin Name: gs-ce-installer
* Description: Single file script to install or update GetSimpleCMS in 1 click.
* Version: 2.6
* Author: RisingIsland
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
	<link rel="icon" type="image/png" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAA3FpVFh0WE1MOmNvbS5hZG9iZS54bXAAAAAAADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IkFkb2JlIFhNUCBDb3JlIDkuMS1jMDAyIDc5LmI3YzY0Y2NmOSwgMjAyNC8wNy8xNi0xMjozOTowNCAgICAgICAgIj4gPHJkZjpSREYgeG1sbnM6cmRmPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5LzAyLzIyLXJkZi1zeW50YXgtbnMjIj4gPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9IiIgeG1sbnM6eG1wTU09Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9tbS8iIHhtbG5zOnN0UmVmPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvc1R5cGUvUmVzb3VyY2VSZWYjIiB4bWxuczp4bXA9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC8iIHhtcE1NOk9yaWdpbmFsRG9jdW1lbnRJRD0ieG1wLmRpZDowNDBhYmFhNy0wYjA3LWZjNDEtOGJiNC0yNDllY2MzMzU4MmUiIHhtcE1NOkRvY3VtZW50SUQ9InhtcC5kaWQ6MDYzRDIxM0YyRkZBMTFGMEFCNzZFRDE5RDE2MkNGNDMiIHhtcE1NOkluc3RhbmNlSUQ9InhtcC5paWQ6MDYzRDIxM0UyRkZBMTFGMEFCNzZFRDE5RDE2MkNGNDMiIHhtcDpDcmVhdG9yVG9vbD0iQWRvYmUgUGhvdG9zaG9wIENDIDIwMTkgKFdpbmRvd3MpIj4gPHhtcE1NOkRlcml2ZWRGcm9tIHN0UmVmOmluc3RhbmNlSUQ9InhtcC5paWQ6QjRCMEEwNDVENEU4MTFFRkFFOTZDQTFCRjkwQTZGMTUiIHN0UmVmOmRvY3VtZW50SUQ9InhtcC5kaWQ6QjRCMEEwNDZENEU4MTFFRkFFOTZDQTFCRjkwQTZGMTUiLz4gPC9yZGY6RGVzY3JpcHRpb24+IDwvcmRmOlJERj4gPC94OnhtcG1ldGE+IDw/eHBhY2tldCBlbmQ9InIiPz7kdE9kAAAIrklEQVR42qxXCVSU1xX+BgZh2AYBZV/cwRhxYzGJLBGlemhNtQc8scQtGrVWjdWctnJq0sQTa0NIa1KX0hbTqkfpqcuhSERQQURQsYbNuCCLbAKyDdvAcHvfG2bCgI3a9s55Z97233ff9757330KPL/YsMwPDg4Omzp16suenp7jHBwcxihYWltbm2pqah6WlZUV5+fnZ7e3t2fy/Db8n2RCRETE748cOdJYW1tLz5KGhgY6duxYS1RU1EH+1v9/WtnPz++jtLS0gaEL9PX1UXd3N3V1dZkU0SfGhsqFCxcoICAg4b9Ze+y6desuDwyYrE06Xf+IhYeXPq12BCrbtm27zjp9n3vjiYmJj4cq6OjooNa2NhOlnZ2dxkVFfaiIPvHNUElOTtaw7sDhiymHte158WtssbpXq8UXScm4kn8Dj5uaoNPpMFqtRtirIXhn5Qo4cJ1hB+uGtbW1/Dj5eArSLmShvqERxD8He3u87D8Fy5ctwcqVK214bu7q1at9eGrdU7f+9tq1BcLazOxcmhwUSrB3JVioCUp7/b+5nezzDgim5OMnaUCnI11/Pz1paaVFMXG8ort+npmtfi6s9G1rZ9q1Z59EYvv27XeeigC71Yd/TEoKvHm7GPN/GAulUgmVnS1emR+OoFkzYG5ujpI7d/HP85moup2P8ooqKMzMoOBvf7U3Aef+fhouUyZigq8P5oe+CmuVCrX1j5GWkYkHRSUImhkg10lISJiSnp5+oLS0dONQQ1wKCvKlhbNfX0xKF1+y9Z5CH+z7dAShPvn8EP36t4nGtobPf0ZYFJmN8abZEYtGzC8uu0On09JlvaenR/4XFhaScG/j6oGBgX+QbnM5hxX5kJX7RIrbuJWeRwQBZ0V8jyxcx9H00IVUePvr/zhPFO2gl0RGRp4wHMGoLVu2rBGNS1fyMNDfB2L4Y9+Ilsaxb+NizlVkZOfAebQjzMwUknjtHRr8OGYp/CZNRNgrISi8eBn1Dg5YvHwVpjHxPNxc4eXhjteC5yDq9XBJVPYOsAGwsLAArxnDcWI9LC0to1taWqRV7+56n+DkRU6TplNewQ2j9W9t2iog42JBUNiQgueI+snTqXJcuOib6zeTjddkgs0YPQlhTbAcTZbuE2jBsjepvKLS6KKG4+BQHqecO3fuAq7I3VpZWvEyBB6EprNL9gn3mxcSjDv3ouHj5YlO7r9z7z6amKB2tjZyjprd7eih/cjJy2e3vY7K6ho0NjWjmEnb0NiIjIwsLFu1HpmnT0BtZydR5Y0jPDw8UkCRZdjpoeS/EUZ7SBLu2vMb2dfP4VVEQINUVT8iF/+ZZOk2gc5duPid/KipraO4DVvI3tdf6k05k2qCQnx8/A0zLy+v8QYyLoyYBxfXsbC1scHhL4/hq6xLMGc+mJmZGwlbU1ePbkaIL0H09ffLvlru25e4H03NT0ziijvzYNpUP+ZLB1gJOjQak3Fe202pVqudRaO3txe+3t7Yun4NfvlePBx9vPHWpnexfOkPEDJ7Fvu8At/ce4Az6edho7JGe91DWI0ahX42YuPOXTh7NBknU9MRGfYaxvt4SaMLvy5GOm/C0WE0G9uHuXNmmRjAR6/G4cOHOw1uwsokNBt+9nOOam76yCZIxfDByZNg5SijGtRuFLt2k7wBW1pbaUpIOM9z1o+pnPQRVBQ7F4Kjh/znsC5180aNR3DixAmNkpOHZjbGWkDa09PLWYc1DnzyMcf8uTiacgr3H1ZK6HgY9kwgv0kT8MaiKMTFLpO7EBEy/6sz+GvKP5B5+Qrul1egjV2UaEAepYiM76xage9HLZDkE4iJtYS0tbV1gC+eSwZiGG42Xf+3pKt+VEP/Kimjkvon1DSMZBq+8bqH9TXyDV7W0kFFlXWSsAbRanuNN6jh9ty9e3ehsqioqJiNCRvF5ymChLBO3IRCLJiAnhxMREF3O/quZuBJezuUk1+C9Xg/KDmgaKvuo7ulDYpRCliyDmdG0FkxCnBVc4izk26sHdRn2LkIREKKi4vLoFKplrYN3vUmyQVb2ctIiMBZ8fEGuuYCyoa+XLUF3d+xlHScsFR+tpPyHCHHc7n/qh3oIs8pjZtNAseuHu2IpEWPiJacnJzWKJlI51j6Y2NjlSLEGqxk/4OSz7f6s5+g/BcH4RCkgvfeXTBT2aL+iwT0Vt2V0zhQQMfe5xa/AipfP+hEu3UAqun+4BjCEwZMmC/WEJKVlYXm5uazsjFv3ry/GPI9Y2rF7bYHpXTNFXQzQE2axjppuUjSegZ01K3jFI3r5R+toxzW2155zzgudQnP0mhYV6fJ7g2XUXR09FmTmJCbm2uSagnl9Wf+TJdZecXeNfoj6tETSVyqogiIK/b9lK6NBd0KVupLkJk8jpoj+6SOoYsbyFdSUkKGjNls0IBqTpkSRUXcWgaYMHgcAuahbcVgGcSUx5lvXpOgmhoIK7/ZDP9MKB3ZigEygZ75JutxcXFf8l/ZiJRs8+bNpQaCCKDaKu5SgSfohp+1EWKJBLufpvmxhLv8w7flEWga600zaIFmh0aSWexcBKDB+F/9XRmxS1JSkjykPt2AhLjqwE7J6hv+oKpPt1LNwXi6FeJAN6eDU/Beqvzde9KAbzZFUsWe9VT+/mq6uy2Oqg9/QD3sRZ29WuO5p6SkCEgmPistn8NGGPNsYXfV/vfo+sRv3TDfTbjhj0jLu6o+uJvyPRRUMA6Ux1wQpBW8KVrsQT1DEOGwq2PdEc/7Nhi3Y8eOWyZvg/paasw+T40Xz1F79UOJTg/vrqO2ir3lrjwiQ2nldndDjfFbjngiE572ws+joKCgz6/k5Iy457XDPEI7WHTD5vFDlUJDQ//0lPfHC8mMJUuWHDl16lRX27DX0dNEw76fmpraFxMTc1zs4VnKFS9giAvLQk7hxPP8JX5HuPN97iCe52xYKz/P6zjXL83Ly8vmV3QGz3/0PEr/LcAATkvYzuD32gUAAAAASUVORK5CYII=">
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
			background-image: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAlgAAAJYCAIAAAAxBA+LAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAydpVFh0WE1MOmNvbS5hZG9iZS54bXAAAAAAADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IkFkb2JlIFhNUCBDb3JlIDEwLjAtYzAwMCA3OS5kMjBlNDY2MzAsIDIwMjUvMTIvMDktMDI6MTE6MjMgICAgICAgICI+IDxyZGY6UkRGIHhtbG5zOnJkZj0iaHR0cDovL3d3dy53My5vcmcvMTk5OS8wMi8yMi1yZGYtc3ludGF4LW5zIyI+IDxyZGY6RGVzY3JpcHRpb24gcmRmOmFib3V0PSIiIHhtbG5zOnhtcD0iaHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wLyIgeG1sbnM6eG1wTU09Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9tbS8iIHhtbG5zOnN0UmVmPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvc1R5cGUvUmVzb3VyY2VSZWYjIiB4bXA6Q3JlYXRvclRvb2w9IkFkb2JlIFBob3Rvc2hvcCAyNy45IChXaW5kb3dzKSIgeG1wTU06SW5zdGFuY2VJRD0ieG1wLmlpZDowRDJGNDFDRUFDRUExMUYxQkYwRkM3MTQ5QjU3QjIwOCIgeG1wTU06RG9jdW1lbnRJRD0ieG1wLmRpZDowRDJGNDFDRkFDRUExMUYxQkYwRkM3MTQ5QjU3QjIwOCI+IDx4bXBNTTpEZXJpdmVkRnJvbSBzdFJlZjppbnN0YW5jZUlEPSJ4bXAuaWlkOjBEMkY0MUNDQUNFQTExRjFCRjBGQzcxNDlCNTdCMjA4IiBzdFJlZjpkb2N1bWVudElEPSJ4bXAuZGlkOjBEMkY0MUNEQUNFQTExRjFCRjBGQzcxNDlCNTdCMjA4Ii8+IDwvcmRmOkRlc2NyaXB0aW9uPiA8L3JkZjpSREY+IDwveDp4bXBtZXRhPiA8P3hwYWNrZXQgZW5kPSJyIj8+BPrucQAARQJJREFUeNrs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)
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
			background-image:  url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAlgAAAJYCAIAAAAxBA+LAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAydpVFh0WE1MOmNvbS5hZG9iZS54bXAAAAAAADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IkFkb2JlIFhNUCBDb3JlIDEwLjAtYzAwMCA3OS5kMjBlNDY2MzAsIDIwMjUvMTIvMDktMDI6MTE6MjMgICAgICAgICI+IDxyZGY6UkRGIHhtbG5zOnJkZj0iaHR0cDovL3d3dy53My5vcmcvMTk5OS8wMi8yMi1yZGYtc3ludGF4LW5zIyI+IDxyZGY6RGVzY3JpcHRpb24gcmRmOmFib3V0PSIiIHhtbG5zOnhtcD0iaHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wLyIgeG1sbnM6eG1wTU09Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9tbS8iIHhtbG5zOnN0UmVmPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvc1R5cGUvUmVzb3VyY2VSZWYjIiB4bXA6Q3JlYXRvclRvb2w9IkFkb2JlIFBob3Rvc2hvcCAyNy45IChXaW5kb3dzKSIgeG1wTU06SW5zdGFuY2VJRD0ieG1wLmlpZDpCNkRGQTNCNUFDRTkxMUYxQkUxRUY4NzQ4RThFMEE4NiIgeG1wTU06RG9jdW1lbnRJRD0ieG1wLmRpZDpCNkRGQTNCNkFDRTkxMUYxQkUxRUY4NzQ4RThFMEE4NiI+IDx4bXBNTTpEZXJpdmVkRnJvbSBzdFJlZjppbnN0YW5jZUlEPSJ4bXAuaWlkOkI2REZBM0IzQUNFOTExRjFCRTFFRjg3NDhFOEUwQTg2IiBzdFJlZjpkb2N1bWVudElEPSJ4bXAuZGlkOkI2REZBM0I0QUNFOTExRjFCRTFFRjg3NDhFOEUwQTg2Ii8+IDwvcmRmOkRlc2NyaXB0aW9uPiA8L3JkZjpSREY+IDwveDp4bXBtZXRhPiA8P3hwYWNrZXQgZW5kPSJyIj8+SnicdgAAWMNJREFUeNrs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)
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
			border: 2px solid var(--color-grey);
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
			border-top: 3px solid var(--color-grey-light);
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
		
		.gui-key{
			font-family: monospace;
			font-size: 12px;
			color: #0891b2;
			background: #ecfeff;
			border: 1px solid #a5f3fc;
			padding: 1px 6px;
			border-radius: 3px;
			white-space: nowrap;
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
				<h1 class="text-primary text-center" style="font-weight:700; margin:20px 0;">
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
					<dd><span class="info-icon"></span> Existing plugins may require updating when migrating from older PHP versions.</dd>
				</dl>
				
				<h4 style="color: #4CAF50;font-size:1.2em;font-weight:600; background-color:#ffffcc; padding:10px;">
				<svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="1.2em" height="1.2em" viewBox="0 0 24 24"><path fill="currentColor" d="M12 9a1 1 0 0 0-1 1v3a1 1 0 0 0 2 0v-3a1 1 0 0 0-1-1m7-7H5a3 3 0 0 0-3 3v10a3 3 0 0 0 3 3h11.59l3.7 3.71A1 1 0 0 0 21 22a.84.84 0 0 0 .38-.08A1 1 0 0 0 22 21V5a3 3 0 0 0-3-3m1 16.59l-2.29-2.3A1 1 0 0 0 17 16H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1ZM12 6a1 1 0 1 0 1 1a1 1 0 0 0-1-1"></path></svg>
				<span style="font-weight:600"> Info &amp; Instructions:</span>
				</h4>
			
				<ul class="w3-ul">
					<li>
						<p><b>1.</b> <u>After</u> updating, you may need to <u>Activate</u> the following <a href="plugins.php">[ <svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;" width="16px" height="16px" viewBox="0 0 24 24"><rect width="24" height="24" fill="none"></rect><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 2v5m8-5v5M5 7v3a1 1 0 0 0 14 0V7zm7 10v5"></path></svg> Plugins ]</a>:</p>
						<ul>
							<li><b>Dashboard</b></li>
							<li><b>GS Config GUI</b></li>
							<li><b>Massive Admin Theme</b></li>
						</ul>
					</li>
					<li style="padding-top:30px;">
						<p><b>2.</b> <u>After</u> activating, visit the <b style="font-size:12px;padding:3px 5px;border:1px solid grey;border-radius:5px;">GS Config GUI <svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;" width="18px" height="18px" viewBox="0 0 24 24"><rect width="24" height="24" fill="none"></rect><path fill="#3B82F6" fill-rule="evenodd" d="M7 3a4 4 0 0 1 3.874 3H19v2h-8.126A4.002 4.002 0 0 1 3 7a4 4 0 0 1 4-4m0 6a2 2 0 1 0 0-4a2 2 0 0 0 0 4m10 11a4 4 0 0 1-3.874-3H5v-2h8.126A4.002 4.002 0 0 1 21 16a4 4 0 0 1-4 4m0-2a2 2 0 1 0 0-4a2 2 0 0 0 0 4" clip-rule="evenodd"></path></svg></b> settings page, found in the <a href="settings.php">[ <svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;" width="16px" height="16px" viewBox="0 0 24 24"><path fill="currentColor" d="M19.9 12.66a1 1 0 0 1 0-1.32l1.28-1.44a1 1 0 0 0 .12-1.17l-2-3.46a1 1 0 0 0-1.07-.48l-1.88.38a1 1 0 0 1-1.15-.66l-.61-1.83a1 1 0 0 0-.95-.68h-4a1 1 0 0 0-1 .68l-.56 1.83a1 1 0 0 1-1.15.66L5 4.79a1 1 0 0 0-1 .48L2 8.73a1 1 0 0 0 .1 1.17l1.27 1.44a1 1 0 0 1 0 1.32L2.1 14.1a1 1 0 0 0-.1 1.17l2 3.46a1 1 0 0 0 1.07.48l1.88-.38a1 1 0 0 1 1.15.66l.61 1.83a1 1 0 0 0 1 .68h4a1 1 0 0 0 .95-.68l.61-1.83a1 1 0 0 1 1.15-.66l1.88.38a1 1 0 0 0 1.07-.48l2-3.46a1 1 0 0 0-.12-1.17ZM18.41 14l.8.9-1.28 2.22-1.18-.24a3 3 0 0 0-3.45 2L12.92 20h-2.56L10 18.86a3 3 0 0 0-3.45-2l-1.18.24-1.3-2.21.8-.9a3 3 0 0 0 0-4l-.8-.9 1.28-2.2 1.18.24a3 3 0 0 0 3.45-2L10.36 4h2.56l.38 1.14a3 3 0 0 0 3.45 2l1.18-.24 1.28 2.22-.8.9a3 3 0 0 0 0 3.98Zm-6.77-6a4 4 0 1 0 4 4 4 4 0 0 0-4-4Zm0 6a2 2 0 1 1 2-2 2 2 0 0 1-2 2Z"></path></svg> Settings ]</a> tab, sidebar menu.</p>
					</li>
					<li><p><b>3.</b> In the <span class="gui-title">Editor</span> section: </p>
						<ul>
							<li><u>Activate</u> <span class="gui-key">GSEDITORTOOL</span> <b>Editor Toolbar</b>, <u>if</u> not already activated.</li>
							<li><u>Activate</u> <span class="gui-key">GSEDITOROPTIONS</span> <b>Editor Options</b>, <u>if</u> not already activated.
								<ul>
									<li><u>If</u> the [textarea] is blank, click the "<span style="color:#3b82f6;font-weight:600;"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg> Restor defaults</span>" option.</li>
								</ul>
							</li>
							<li>Scroll to bottom of page and click the <b style="padding:3px 5px; background-color:#4781F1;border-radius:5px;color:white">Save Changes</b> button.</li>
						</ul>
					</li>
					<li style="padding-top:30px;"><b style="color:#CF3805"><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;" width="1.6em" height="1.6em" viewBox="0 0 24 24"><rect width="24" height="24" fill="none"></rect><path fill="currentColor" d="M9 21v-5q-1.55-.125-3.037-.35T3 15l.5-2q2.075.575 4.2.788T12 14q2.15 0 4.275-.213T20.5 13l.5 2q-1.5.425-2.988.65T15 16v5zm3-8q-.85 0-1.425-.575T10 11q0-.825.575-1.412T12 9q.825 0 1.413.588T14 11q0 .85-.587 1.425T12 13m-7.5-3q-.65 0-1.075-.425T3 8.5q0-.625.425-1.062T4.5 7q.625 0 1.063.438T6 8.5q0 .65-.437 1.075T4.5 10m15 0q-.65 0-1.075-.425T18 8.5q0-.625.425-1.062T19.5 7q.625 0 1.063.438T21 8.5q0 .65-.437 1.075T19.5 10M7.25 6.25q-.65 0-1.075-.425T5.75 4.75q0-.625.425-1.062T7.25 3.25q.625 0 1.063.438T8.75 4.75q0 .65-.437 1.075T7.25 6.25m9.5 0q-.65 0-1.075-.425T15.25 4.75q0-.625.425-1.062t1.075-.438q.625 0 1.063.438t.437 1.062q0 .65-.437 1.075t-1.063.425M12 5q-.65 0-1.075-.425T10.5 3.5q0-.625.425-1.062T12 2q.625 0 1.063.438T13.5 3.5q0 .65-.437 1.075T12 5"></path></svg> Congratulations, update complete!</b></li>
				</ul>
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