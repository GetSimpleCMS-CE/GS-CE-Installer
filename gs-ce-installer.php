<?php

	/* **********
	* Plugin Name: gs-ce-installer
	* Description: Single file script to install or update GetSimpleCMS in 1 click.
	* Version: 2.4
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

set_time_limit(0);
ini_set('max_execution_time', 0);

$installer_version = '2.4';
$default = 'Full';

if (extension_loaded('xdebug')) {
    ini_set('xdebug.max_nesting_level', 100000);
}

if (!empty($_GET['target']) && is_string($_GET['target']) && Installer::doInstall($_GET['target'])) {
    exit;
}

header('Content-Type: text/html; charset=utf-8');

class Installer{
    public static $packageInfo = [
        'Full' => [
            'tree' => 'Get-Simple CMS CE v3.3.21',
            'name' => 'New Installation',
            'link' => 'https://github.com/GetSimpleCMS-CE/GetSimpleCMS-CE/archive/refs/tags/v3.3.21.zip',
            'location' => 'admin/install.php'
        ],
        'Upgrade' => [
            'tree' => 'Get-Simple CMS CE v3.3.21 Upgrade',
            'name' => 'Upgrade Only',
            'link' => 'https://github.com/GetSimpleCMS-CE/update-GetSimpleCMS-CE/archive/refs/heads/3.3.21.zip',
            'location' => 'admin/install.php'
        ],
        'Dev' => [
            'tree' => 'Development Version',
            'name' => 'Current Beta',
            'link' => 'https://github.com/GetSimpleCMS-CE/GetSimpleCMS-CE/archive/refs/heads/main.zip',
            'location' =>'admin/install.php'
        ]
    ];

    public static function items($default=null) {
        $ItemGrid = [];
        foreach(static::$packageInfo as $ver=>$item){
            $ItemGrid[$item['tree']][$ver] = $item;
        }
        $rs = [];
        foreach($ItemGrid as $tree=>$item){
            $rs[] = '<div class="col card"><header class="text-primary">'.strtoupper($tree);
            foreach($item as $version => $itemInfo){
                $rs[] = sprintf(
                    '</header><label><input type="radio" name="target" value="%s"> <span>%s</span></label><br>',
                    $version,
                    $itemInfo['name']
                );
            }
            $rs[] = '</div>';
        }
		
        if(!$default) {
            return implode("\n", $rs);
        }

        return str_replace(
            sprintf('value="%s"', $default),
            sprintf('value="%s" checked', $default),
            implode("\n", $rs)
        );
    }

    public static function hasProblem() {
        if (!ini_get('allow_url_fopen')) {
            return '<div class="col-12"><span class="bg-error padding"><span class="warning"></span> Cannot download the files - url_fopen is not enabled on this server.</span></div>';
        }
        
        if (!class_exists('ZipArchive')) {
            return '<div class="col-12"><span class="bg-error padding"><span class="warning"></span> Cannot extract files - Zip extension is not available.</span></div>';
        }
        
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit != '-1' && self::convertToBytes($memory_limit) < 128 * 1024 * 1024) {
            return '<div class="col-12"><span class="bg-error padding"><span class="warning"></span> Low memory limit detected ('.htmlspecialchars($memory_limit).'). Consider increasing memory_limit to at least 128M.</span></div>';
        }
        
        if (!defined('LOCALHOST_BYPASS') && !Installer::hasDirPerm()) {
            return '<div class="col-12"><span class="bg-error padding"><span class="warning"></span> Cannot download the files - The directory does not have write permission.</span></div>';
        }
        
        return false;
    }
    
    private static function convertToBytes($value) {
        $value = trim($value);
        $last = strtolower($value[strlen($value)-1]);
        $value = (int)$value;
        
        switch($last) {
            case 'g': $value *= 1024;
            case 'm': $value *= 1024;
            case 'k': $value *= 1024;
        }
        
        return $value;
    }

    private static function downloadFile($url, $path) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'header' => "User-Agent: GetSimple CMS Installer\r\n"
            ]
        ]);
        
        $rs = file_get_contents($url, false, $context);
        if(!$rs) {
            return false;
        }
        return file_put_contents($path, $rs);
    }

    private static function moveFiles($src, $dest) {
        $path = realpath($src);
        $dest = realpath($dest);
        $objects = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach($objects as $name => $object) {
            $startsAt = substr(dirname($name), strlen($path));
            self::mmkDir($dest.$startsAt);
            if ($object->isDir()) {
                self::mmkDir($dest.substr($name, strlen($path)));
            }

            if(is_writable($dest.$startsAt) && $object->isFile()) {
                rename((string)$name, $dest.$startsAt.'/'.basename($name));
            }
        }
    }

    private static function mmkDir($folder, $perm=0777) {
        if(is_dir($folder)) {
            return;
        }
        if (mkdir($folder, $perm, true) || is_dir($folder)) {
            return;
        }
        throw new \RuntimeException(
            sprintf(
                'Directory "%s" was not created', $folder
            )
        );
    }

    public static function doInstall($target_version=null) {
        if (empty($target_version) || !is_scalar($target_version)) {
            return false;
        }
        if (!isset(static::$packageInfo[$target_version])) {
            return false;
        }

        $rowInstall = static::$packageInfo[$target_version];
        $base_dir = str_replace('\\','/',__DIR__);
        $temp_dir = $base_dir.'/_temp_'.bin2hex(random_bytes(8));

        try {
            // Download the file
            if (!static::downloadFile($rowInstall['link'], 'fetch.zip')) {
                throw new \RuntimeException('Failed to download the package');
            }

            // Extract the zip
            $zip = new ZipArchive;
            if ($zip->open($base_dir.'/fetch.zip') !== true) {
                throw new \RuntimeException('Failed to open the downloaded package');
            }
            
            if (!$zip->extractTo($temp_dir)) {
                $zip->close();
                throw new \RuntimeException('Failed to extract the package');
            }
            $zip->close();
            unlink($base_dir.'/fetch.zip');

            // Find the extracted directory
            $dir = '';
            if ($handle = opendir($temp_dir)) {
                while ($name = readdir($handle)) {
                    if ($name === '.' || $name === '..') {
                        continue;
                    }
                    $dir = $name;
                    break;
                }
                closedir($handle);
            }

            if (empty($dir)) {
                throw new \RuntimeException('No files found in the downloaded package');
            }

            // Move files to destination
            static::moveFiles($temp_dir.'/'.$dir, $base_dir.'/');
            static::rmdirs($temp_dir);
            
            // Remove installer and redirect
            if (file_exists(__FILE__)) {
                unlink(__FILE__);
            }
            
            header('Location: '.$rowInstall['location']);
            return true;
            
        } catch (\Exception $e) {
            // Clean up on error
            if (file_exists($base_dir.'/fetch.zip')) {
                unlink($base_dir.'/fetch.zip');
            }
            if (is_dir($temp_dir)) {
                static::rmdirs($temp_dir);
            }
            
            if (defined('LOCALHOST_BYPASS')) {
                die('<div class="col-12"><span class="bg-error padding"><span class="warning"></span>Installation failed: '.htmlspecialchars($e->getMessage()).'</span></div>');
            } else {
                die('<div class="col-12"><span class="bg-error padding"><span class="warning"></span>Installation failed. Please check server permissions and try again.</span></div>');
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
        $test_file = __DIR__.'/installer_test_'.time().'.tmp';
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
}

?>
<!DOCTYPE html>
<html>

<head>
	<title>GS-CE Installer v<?= $installer_version ?></title>
	<link rel="icon" type="image/png" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAA3FpVFh0WE1MOmNvbS5hZG9iZS54bXAAAAAAADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IkFkb2JlIFhNUCBDb3JlIDkuMS1jMDAyIDc5LmI3YzY0Y2NmOSwgMjAyNC8wNy8xNi0xMjozOTowNCAgICAgICAgIj4gPHJkZjpSREYgeG1sbnM6cmRmPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5LzAyLzIyLXJkZi1zeW50YXgtbnMjIj4gPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9IiIgeG1sbnM6eG1wTU09Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9tbS8iIHhtbG5zOnN0UmVmPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvc1R5cGUvUmVzb3VyY2VSZWYjIiB4bWxuczp4bXA9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC8iIHhtcE1NOk9yaWdpbmFsRG9jdW1lbnRJRD0ieG1wLmRpZDowNDBhYmFhNy0wYjA3LWZjNDEtOGJiNC0yNDllY2MzMzU4MmUiIHhtcE1NOkRvY3VtZW50SUQ9InhtcC5kaWQ6MDYzRDIxM0YyRkZBMTFGMEFCNzZFRDE5RDE2MkNGNDMiIHhtcE1NOkluc3RhbmNlSUQ9InhtcC5paWQ6MDYzRDIxM0UyRkZBMTFGMEFCNzZFRDE5RDE2MkNGNDMiIHhtcDpDcmVhdG9yVG9vbD0iQWRvYmUgUGhvdG9zaG9wIENDIDIwMTkgKFdpbmRvd3MpIj4gPHhtcE1NOkRlcml2ZWRGcm9tIHN0UmVmOmluc3RhbmNlSUQ9InhtcC5paWQ6QjRCMEEwNDVENEU4MTFFRkFFOTZDQTFCRjkwQTZGMTUiIHN0UmVmOmRvY3VtZW50SUQ9InhtcC5kaWQ6QjRCMEEwNDZENEU4MTFFRkFFOTZDQTFCRjkwQTZGMTUiLz4gPC9yZGY6RGVzY3JpcHRpb24+IDwvcmRmOlJERj4gPC94OnhtcG1ldGE+IDw/eHBhY2tldCBlbmQ9InIiPz7kdE9kAAAIrklEQVR42qxXCVSU1xX+BgZh2AYBZV/cwRhxYzGJLBGlemhNtQc8scQtGrVWjdWctnJq0sQTa0NIa1KX0hbTqkfpqcuhSERQQURQsYbNuCCLbAKyDdvAcHvfG2bCgI3a9s55Z97233ff9757330KPL/YsMwPDg4Omzp16suenp7jHBwcxihYWltbm2pqah6WlZUV5+fnZ7e3t2fy/Db8n2RCRETE748cOdJYW1tLz5KGhgY6duxYS1RU1EH+1v9/WtnPz++jtLS0gaEL9PX1UXd3N3V1dZkU0SfGhsqFCxcoICAg4b9Ze+y6desuDwyYrE06Xf+IhYeXPq12BCrbtm27zjp9n3vjiYmJj4cq6OjooNa2NhOlnZ2dxkVFfaiIPvHNUElOTtaw7sDhiymHte158WtssbpXq8UXScm4kn8Dj5uaoNPpMFqtRtirIXhn5Qo4cJ1hB+uGtbW1/Dj5eArSLmShvqERxD8He3u87D8Fy5ctwcqVK214bu7q1at9eGrdU7f+9tq1BcLazOxcmhwUSrB3JVioCUp7/b+5nezzDgim5OMnaUCnI11/Pz1paaVFMXG8ort+npmtfi6s9G1rZ9q1Z59EYvv27XeeigC71Yd/TEoKvHm7GPN/GAulUgmVnS1emR+OoFkzYG5ujpI7d/HP85moup2P8ooqKMzMoOBvf7U3Aef+fhouUyZigq8P5oe+CmuVCrX1j5GWkYkHRSUImhkg10lISJiSnp5+oLS0dONQQ1wKCvKlhbNfX0xKF1+y9Z5CH+z7dAShPvn8EP36t4nGtobPf0ZYFJmN8abZEYtGzC8uu0On09JlvaenR/4XFhaScG/j6oGBgX+QbnM5hxX5kJX7RIrbuJWeRwQBZ0V8jyxcx9H00IVUePvr/zhPFO2gl0RGRp4wHMGoLVu2rBGNS1fyMNDfB2L4Y9+Ilsaxb+NizlVkZOfAebQjzMwUknjtHRr8OGYp/CZNRNgrISi8eBn1Dg5YvHwVpjHxPNxc4eXhjteC5yDq9XBJVPYOsAGwsLAArxnDcWI9LC0to1taWqRV7+56n+DkRU6TplNewQ2j9W9t2iog42JBUNiQgueI+snTqXJcuOib6zeTjddkgs0YPQlhTbAcTZbuE2jBsjepvKLS6KKG4+BQHqecO3fuAq7I3VpZWvEyBB6EprNL9gn3mxcSjDv3ouHj5YlO7r9z7z6amKB2tjZyjprd7eih/cjJy2e3vY7K6ho0NjWjmEnb0NiIjIwsLFu1HpmnT0BtZydR5Y0jPDw8UkCRZdjpoeS/EUZ7SBLu2vMb2dfP4VVEQINUVT8iF/+ZZOk2gc5duPid/KipraO4DVvI3tdf6k05k2qCQnx8/A0zLy+v8QYyLoyYBxfXsbC1scHhL4/hq6xLMGc+mJmZGwlbU1ePbkaIL0H09ffLvlru25e4H03NT0ziijvzYNpUP+ZLB1gJOjQak3Fe202pVqudRaO3txe+3t7Yun4NfvlePBx9vPHWpnexfOkPEDJ7Fvu8At/ce4Az6edho7JGe91DWI0ahX42YuPOXTh7NBknU9MRGfYaxvt4SaMLvy5GOm/C0WE0G9uHuXNmmRjAR6/G4cOHOw1uwsokNBt+9nOOam76yCZIxfDByZNg5SijGtRuFLt2k7wBW1pbaUpIOM9z1o+pnPQRVBQ7F4Kjh/znsC5180aNR3DixAmNkpOHZjbGWkDa09PLWYc1DnzyMcf8uTiacgr3H1ZK6HgY9kwgv0kT8MaiKMTFLpO7EBEy/6sz+GvKP5B5+Qrul1egjV2UaEAepYiM76xage9HLZDkE4iJtYS0tbV1gC+eSwZiGG42Xf+3pKt+VEP/Kimjkvon1DSMZBq+8bqH9TXyDV7W0kFFlXWSsAbRanuNN6jh9ty9e3ehsqioqJiNCRvF5ymChLBO3IRCLJiAnhxMREF3O/quZuBJezuUk1+C9Xg/KDmgaKvuo7ulDYpRCliyDmdG0FkxCnBVc4izk26sHdRn2LkIREKKi4vLoFKplrYN3vUmyQVb2ctIiMBZ8fEGuuYCyoa+XLUF3d+xlHScsFR+tpPyHCHHc7n/qh3oIs8pjZtNAseuHu2IpEWPiJacnJzWKJlI51j6Y2NjlSLEGqxk/4OSz7f6s5+g/BcH4RCkgvfeXTBT2aL+iwT0Vt2V0zhQQMfe5xa/AipfP+hEu3UAqun+4BjCEwZMmC/WEJKVlYXm5uazsjFv3ry/GPI9Y2rF7bYHpXTNFXQzQE2axjppuUjSegZ01K3jFI3r5R+toxzW2155zzgudQnP0mhYV6fJ7g2XUXR09FmTmJCbm2uSagnl9Wf+TJdZecXeNfoj6tETSVyqogiIK/b9lK6NBd0KVupLkJk8jpoj+6SOoYsbyFdSUkKGjNls0IBqTpkSRUXcWgaYMHgcAuahbcVgGcSUx5lvXpOgmhoIK7/ZDP9MKB3ZigEygZ75JutxcXFf8l/ZiJRs8+bNpQaCCKDaKu5SgSfohp+1EWKJBLufpvmxhLv8w7flEWga600zaIFmh0aSWexcBKDB+F/9XRmxS1JSkjykPt2AhLjqwE7J6hv+oKpPt1LNwXi6FeJAN6eDU/Beqvzde9KAbzZFUsWe9VT+/mq6uy2Oqg9/QD3sRZ29WuO5p6SkCEgmPistn8NGGPNsYXfV/vfo+sRv3TDfTbjhj0jLu6o+uJvyPRRUMA6Ux1wQpBW8KVrsQT1DEOGwq2PdEc/7Nhi3Y8eOWyZvg/paasw+T40Xz1F79UOJTg/vrqO2ir3lrjwiQ2nldndDjfFbjngiE572ws+joKCgz6/k5Iy457XDPEI7WHTD5vFDlUJDQ//0lPfHC8mMJUuWHDl16lRX27DX0dNEw76fmpraFxMTc1zs4VnKFS9giAvLQk7hxPP8JX5HuPN97iCe52xYKz/P6zjXL83Ly8vmV3QGz3/0PEr/LcAATkvYzuD32gUAAAAASUVORK5CYII=">
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<link rel="stylesheet" href="https://unpkg.com/chota@latest" crossorigin="anonymous" referrerpolicy="no-referrer">
	<style>
		:root {
			--bg-color: #ffffff;
			--bg-secondary-color: #f3f3f6;
			--color-primary: #cf3805;
		}
		body.dark {
			--bg-color: #000;
			--bg-secondary-color: #131316;
			--font-color: #f5f5f5;
			--color-grey: #ccc;
			--color-darkGrey: #777;
		}
		.btn-small {padding: 5px 10px}
		.padding {padding: 5px 10px}
		.padding-big {padding: 25px}
		pre {color:deeppink; font-size:.85em}
		.info {
			display: inline-block;
			vertical-align:middle;
			width: 1.2em;
			height: 1.2em;
			background-repeat: no-repeat;
			background-size: 100% 100%;
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%23CC7A00' d='M12 17q.425 0 .713-.288T13 16v-4q0-.425-.288-.712T12 11t-.712.288T11 12v4q0 .425.288.713T12 17m0-8q.425 0 .713-.288T13 8t-.288-.712T12 7t-.712.288T11 8t.288.713T12 9m0 13q-2.075 0-3.9-.788t-3.175-2.137T2.788 15.9T2 12t.788-3.9t2.137-3.175T8.1 2.788T12 2t3.9.788t3.175 2.137T21.213 8.1T22 12t-.788 3.9t-2.137 3.175t-3.175 2.138T12 22'/%3E%3C/svg%3E");
		}
		.ok {
			display: inline-block;
			vertical-align:middle;
			width: 1.2em;
			height: 1.2em;
			background-repeat: no-repeat;
			background-size: 100% 100%;
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpath fill='%2300A300' d='M1 6a5 5 0 1 1 10 0A5 5 0 0 1 1 6m7.354-.896a.5.5 0 1 0-.708-.708L5.5 6.543L4.354 5.396a.5.5 0 1 0-.708.708l1.5 1.5a.5.5 0 0 0 .708 0z'/%3E%3C/svg%3E");
		}
		.warning {
			display: inline-block;
			vertical-align:middle;
			width: 1.2em;
			height: 1.2em;
			background-repeat: no-repeat;
			background-size: 100% 100%;
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cg fill='none'%3E%3Cpath d='m12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035q-.016-.005-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427q-.004-.016-.017-.018m.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093q.019.005.029-.008l.004-.014l-.034-.614q-.005-.018-.02-.022m-.715.002a.02.02 0 0 0-.027.006l-.006.014l-.034.614q.001.018.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01z'/%3E%3Cpath fill='%23CC0000' d='M12 2c5.523 0 10 4.477 10 10s-4.477 10-10 10S2 17.523 2 12S6.477 2 12 2m0 13a1 1 0 1 0 0 2a1 1 0 0 0 0-2m0-9a1 1 0 0 0-.993.883L11 7v6a1 1 0 0 0 1.993.117L13 13V7a1 1 0 0 0-1-1'/%3E%3C/g%3E%3C/svg%3E");
		}
	</style>
	<script>
		function switchMode(el) {
			const bodyClass = document.body.classList;
			bodyClass.contains('dark')
			? (el.innerHTML = '☀️', bodyClass.remove('dark'))
			: (el.innerHTML = '🌙', bodyClass.add('dark')); 
		}
	</script>
</head>

<body>
<div class="container">

	<div class="row">
		<div class="col-11">
			<h1 class="text-primary text-center" style="font-weight:600"><svg style="vertical-align:middle;" width="1.2em" height="1.2em" viewBox="0 0 500 500" version="1.1" xmlns="http://www.w3.org/2000/svg"><g id="#000000fe"><path fill="#000000" opacity="1.00" d=" M 242.40 0.00 L 256.59 0.00 C 297.90 1.22 338.93 12.52 374.70 33.29 C 423.60 61.35 462.49 106.42 482.91 158.99 C 493.41 185.54 498.84 213.93 500.00 242.43 L 500.00 257.55 C 498.57 298.78 487.19 339.69 466.35 375.34 C 435.92 428.06 385.61 468.96 327.66 487.69 C 304.76 495.24 280.75 499.16 256.67 500.00 L 243.37 500.00 C 198.43 498.71 153.85 485.32 115.96 461.06 C 86.50 442.33 61.03 417.37 41.71 388.29 C 15.89 349.76 1.55 303.89 0.00 257.58 L 0.00 242.45 C 1.04 216.48 5.62 190.61 14.43 166.13 C 34.05 110.56 74.16 62.61 125.28 33.30 C 160.76 12.68 201.43 1.44 242.40 0.00 M 240.08 5.21 C 193.20 6.78 147.06 22.66 108.82 49.75 C 77.93 71.67 51.95 100.66 34.10 134.11 C -1.70 199.60 -4.65 281.83 26.18 349.77 C 52.21 408.73 102.64 456.35 162.93 479.10 C 208.55 496.69 259.48 499.50 307.02 488.45 C 343.60 479.56 378.23 462.35 407.02 438.04 C 435.35 414.31 458.50 384.25 473.51 350.43 C 493.16 307.62 499.18 259.02 492.19 212.53 C 485.22 166.10 464.21 121.94 432.88 86.99 C 409.84 61.25 381.46 40.19 349.84 26.21 C 315.58 10.65 277.63 3.68 240.08 5.21 Z" /></g><g id="#f6f6f6ff"><path fill="#f6f6f6" opacity="1.00" d=" M 240.08 5.21 C 277.63 3.68 315.58 10.65 349.84 26.21 C 381.46 40.19 409.84 61.25 432.88 86.99 C 464.21 121.94 485.22 166.10 492.19 212.53 C 499.18 259.02 493.16 307.62 473.51 350.43 C 458.50 384.25 435.35 414.31 407.02 438.04 C 378.23 462.35 343.60 479.56 307.02 488.45 C 259.48 499.50 208.55 496.69 162.93 479.10 C 102.64 456.35 52.21 408.73 26.18 349.77 C -4.65 281.83 -1.70 199.60 34.10 134.11 C 51.95 100.66 77.93 71.67 108.82 49.75 C 147.06 22.66 193.20 6.78 240.08 5.21 M 102.22 92.26 C 83.95 102.02 69.18 118.04 60.85 137.00 C 51.14 158.85 49.97 183.78 54.47 207.04 C 59.18 230.87 72.90 253.24 93.48 266.52 C 115.84 281.38 143.79 285.23 170.06 282.52 C 195.60 279.97 220.95 269.51 238.65 250.54 C 238.74 221.51 238.67 192.47 238.68 163.44 C 208.14 163.42 177.59 163.47 147.05 163.41 C 146.94 175.60 147.03 187.80 147.00 200.00 C 164.65 200.00 182.29 199.99 199.94 200.00 C 199.93 209.92 199.95 219.84 199.92 229.77 C 186.07 241.69 166.87 245.07 149.10 243.62 C 133.32 242.35 117.90 234.41 108.77 221.30 C 98.08 206.31 95.64 186.92 97.98 169.03 C 99.72 155.25 105.91 141.78 116.50 132.58 C 126.73 123.39 140.56 119.15 154.12 118.70 C 172.75 117.68 191.74 124.66 204.98 137.86 C 214.16 128.70 223.43 119.63 232.59 110.46 C 217.84 95.01 198.39 83.70 177.11 80.49 C 151.80 76.68 124.88 79.90 102.22 92.26 M 302.41 87.40 C 290.31 92.61 279.31 101.07 272.65 112.58 C 264.76 126.11 263.84 142.99 268.32 157.80 C 271.99 169.92 281.27 179.70 292.38 185.44 C 307.12 193.23 323.74 196.50 340.13 198.58 C 349.98 199.73 359.99 200.96 369.23 204.76 C 374.07 206.78 378.90 210.00 380.92 215.05 C 383.74 221.80 381.38 230.15 375.54 234.53 C 366.06 241.83 353.50 242.98 341.94 242.79 C 321.11 242.54 300.40 235.47 284.07 222.48 C 276.12 233.48 268.36 244.62 260.34 255.57 C 277.30 269.58 298.57 277.59 320.09 281.29 C 340.25 284.46 361.33 284.27 380.93 278.11 C 395.76 273.42 409.89 264.71 418.43 251.43 C 427.53 237.60 429.16 219.66 424.55 203.96 C 420.92 191.67 411.86 181.55 400.88 175.20 C 384.86 165.84 366.29 162.18 348.08 160.01 C 337.62 158.49 326.58 157.19 317.43 151.49 C 310.26 147.17 308.10 136.79 312.83 129.92 C 317.23 123.77 324.75 120.88 331.87 119.28 C 354.68 114.82 379.10 120.26 398.04 133.68 C 405.21 122.99 412.34 112.26 419.54 101.59 C 401.55 88.75 379.79 81.83 357.90 79.80 C 339.19 78.26 319.78 79.85 302.41 87.40 M 170.82 308.68 C 154.85 311.99 139.82 321.14 130.83 334.92 C 120.21 350.59 117.74 370.62 121.03 388.97 C 123.94 405.04 133.06 420.18 146.81 429.23 C 161.91 439.51 180.99 442.47 198.88 440.39 C 213.87 438.63 228.01 431.57 238.77 421.05 C 232.90 414.50 227.04 407.94 221.15 401.41 C 209.86 412.62 192.48 416.73 177.21 412.74 C 168.52 410.43 160.66 404.84 156.01 397.10 C 150.35 387.91 149.01 376.60 150.54 366.07 C 151.98 356.32 156.93 346.89 164.99 341.05 C 180.85 329.61 204.58 331.81 218.49 345.42 C 224.57 339.18 230.81 333.09 236.94 326.89 C 230.24 320.35 222.37 314.92 213.62 311.53 C 200.07 306.21 184.97 305.82 170.82 308.68 M 255.88 309.82 C 255.87 352.59 255.87 395.35 255.88 438.12 C 289.17 438.13 322.46 438.13 355.75 438.12 C 355.76 429.67 355.73 421.23 355.76 412.79 C 332.20 412.70 308.64 412.76 285.08 412.76 C 285.05 403.56 285.06 394.37 285.07 385.18 C 306.73 385.19 328.39 385.19 350.06 385.18 C 350.07 377.10 350.07 369.02 350.06 360.94 C 328.39 360.93 306.73 360.95 285.06 360.93 C 285.06 352.29 285.06 343.65 285.06 335.00 C 307.94 334.98 330.82 335.03 353.70 334.98 C 353.58 326.57 353.89 318.15 353.55 309.75 C 320.99 309.91 288.44 309.76 255.88 309.82 Z" /></g><g id="#283840ff"><path fill="#283840" opacity="1.00" d=" M 102.22 92.26 C 124.88 79.90 151.80 76.68 177.11 80.49 C 198.39 83.70 217.84 95.01 232.59 110.46 C 223.43 119.63 214.16 128.70 204.98 137.86 C 191.74 124.66 172.75 117.68 154.12 118.70 C 140.56 119.15 126.73 123.39 116.50 132.58 C 105.91 141.78 99.72 155.25 97.98 169.03 C 95.64 186.92 98.08 206.31 108.77 221.30 C 117.90 234.41 133.32 242.35 149.10 243.62 C 166.87 245.07 186.07 241.69 199.92 229.77 C 199.95 219.84 199.93 209.92 199.94 200.00 C 182.29 199.99 164.65 200.00 147.00 200.00 C 147.03 187.80 146.94 175.60 147.05 163.41 C 177.59 163.47 208.14 163.42 238.68 163.44 C 238.67 192.47 238.74 221.51 238.65 250.54 C 220.95 269.51 195.60 279.97 170.06 282.52 C 143.79 285.23 115.84 281.38 93.48 266.52 C 72.90 253.24 59.18 230.87 54.47 207.04 C 49.97 183.78 51.14 158.85 60.85 137.00 C 69.18 118.04 83.95 102.02 102.22 92.26 Z" /><path fill="#283840" opacity="1.00" d=" M 302.41 87.40 C 319.78 79.85 339.19 78.26 357.90 79.80 C 379.79 81.83 401.55 88.75 419.54 101.59 C 412.34 112.26 405.21 122.99 398.04 133.68 C 379.10 120.26 354.68 114.82 331.87 119.28 C 324.75 120.88 317.23 123.77 312.83 129.92 C 308.10 136.79 310.26 147.17 317.43 151.49 C 326.58 157.19 337.62 158.49 348.08 160.01 C 366.29 162.18 384.86 165.84 400.88 175.20 C 411.86 181.55 420.92 191.67 424.55 203.96 C 429.16 219.66 427.53 237.60 418.43 251.43 C 409.89 264.71 395.76 273.42 380.93 278.11 C 361.33 284.27 340.25 284.46 320.09 281.29 C 298.57 277.59 277.30 269.58 260.34 255.57 C 268.36 244.62 276.12 233.48 284.07 222.48 C 300.40 235.47 321.11 242.54 341.94 242.79 C 353.50 242.98 366.06 241.83 375.54 234.53 C 381.38 230.15 383.74 221.80 380.92 215.05 C 378.90 210.00 374.07 206.78 369.23 204.76 C 359.99 200.96 349.98 199.73 340.13 198.58 C 323.74 196.50 307.12 193.23 292.38 185.44 C 281.27 179.70 271.99 169.92 268.32 157.80 C 263.84 142.99 264.76 126.11 272.65 112.58 C 279.31 101.07 290.31 92.61 302.41 87.40 Z" /></g><g id="#cf3805ff"><path fill="#cf3805" opacity="1.00" d=" M 170.82 308.68 C 184.97 305.82 200.07 306.21 213.62 311.53 C 222.37 314.92 230.24 320.35 236.94 326.89 C 230.81 333.09 224.57 339.18 218.49 345.42 C 204.58 331.81 180.85 329.61 164.99 341.05 C 156.93 346.89 151.98 356.32 150.54 366.07 C 149.01 376.60 150.35 387.91 156.01 397.10 C 160.66 404.84 168.52 410.43 177.21 412.74 C 192.48 416.73 209.86 412.62 221.15 401.41 C 227.04 407.94 232.90 414.50 238.77 421.05 C 228.01 431.57 213.87 438.63 198.88 440.39 C 180.99 442.47 161.91 439.51 146.81 429.23 C 133.06 420.18 123.94 405.04 121.03 388.97 C 117.74 370.62 120.21 350.59 130.83 334.92 C 139.82 321.14 154.85 311.99 170.82 308.68 Z" /><path fill="#cf3805" opacity="1.00" d=" M 255.88 309.82 C 288.44 309.76 320.99 309.91 353.55 309.75 C 353.89 318.15 353.58 326.57 353.70 334.98 C 330.82 335.03 307.94 334.98 285.06 335.00 C 285.06 343.65 285.06 352.29 285.06 360.93 C 306.73 360.95 328.39 360.93 350.06 360.94 C 350.07 369.02 350.07 377.10 350.06 385.18 C 328.39 385.19 306.73 385.19 285.07 385.18 C 285.06 394.37 285.05 403.56 285.08 412.76 C 308.64 412.76 332.20 412.70 355.76 412.79 C 355.73 421.23 355.76 429.67 355.75 438.12 C 322.46 438.13 289.17 438.13 255.88 438.12 C 255.87 395.35 255.87 352.59 255.88 309.82 Z" /></g></svg> GetSimple CMS Community Edition</h1> </div>
		<div class="col-1 text-right padding-big"><a href="javascript:void(0)" onclick="switchMode(this)">☀️</a></div>
	</div>
	
	<hr>
	
	<div class="col"><strong>Choose a version to install:</strong></div>
	
	<form>		
		<div class="row is-center">
		
				<?= Installer::items($default) ?>
				<?= Installer::hasProblem() ?: '<div class="col-12 padding-big"><button class="button success">Install &rarr;</button></div>' ?>
			
		</div>
	</form>	
	
	<hr>
	
	<div class="row">
		<div class="col-6 card">
			<h4 class="text-primary">Requirements:</h4>
			<?php
				$error = false;
				if (version_compare(PHP_VERSION, '7.4') >= 0) {
					$requirement1 = "<span class='button btn-small success'>v." . PHP_VERSION . '</span>';
				} else {
					$error        = true;
					$requirement1 = "<span class='button btn-small error'>Your PHP version is " . PHP_VERSION . '</span>';
				}

				if (!extension_loaded('curl')) {
					$error = true;
					$requirement4 = "<span class='button btn-small error'>Not enabled</span>";
				} else {
					$requirement4 = "<span class='button btn-small success'>Enabled</span>";
				}

				if (!extension_loaded('gd')) {
					$error = true;
					$requirement9 = "<span class='button btn-small error'>Not enabled</span>";
				} else {
					$requirement9 = "<span class='button btn-small success'>Enabled</span>";
				}

				if (!extension_loaded('zip')) {
					$error = true;
					$requirement10 = "<span class='button btn-small error'>Zip Extension is not enabled</span>";
				} else {
					$requirement10 = "<span class='button btn-small success'>Enabled</span>";
				}

				if (!extension_loaded('SimpleXML')) {
					$error = true;
					$requirement12 = "<span class='button btn-small error'>Not enabled</span>";
				} else {
					$requirement12 = "<span class='button btn-small success'>Enabled</span>";
				}

				if (!extension_loaded('openssl')) {
					$error = true;
					$requirement5 = "<span class='button btn-small error'>Not enabled</span>";
				} else {
					$requirement5 = "<span class='button btn-small success'>Enabled</span>";
				}

				// Replace current mod_rewrite check with:
				$mod_rewrite = false;
				if (function_exists('apache_get_modules')) {
					$mod_rewrite = in_array('mod_rewrite', apache_get_modules());
				} else {
					// For non-Apache servers, we'll assume mod_rewrite is available
					// since most alternatives (nginx, etc.) have equivalent functionality
					$mod_rewrite = true;
				}

				if (!$mod_rewrite) {
					$error = true;
					$requirement13 = "<span class='button btn-small error'>Not enabled</span>";
				} else {
					$requirement13 = "<span class='button btn-small success'>Enabled</span>";
				}
				
				$writable = is_writable(__DIR__);
				if (!$writable) {
					$error = true;
					$requirement14 = "<span class='button btn-small error'>Not writable</span>";
				} else {
					$requirement14 = "<span class='button btn-small success'>Writable</span>";
				}
				
				if (!ini_get('allow_url_fopen')) {
					$error = true;
					$requirement16 = "<span class='button btn-small error'>Disabled</span>";
				} else {
					$requirement16 = "<span class='button btn-small success'>Enabled</span>";
				}

			?>
			
			<table class="striped">
				<thead>
					<tr>
						<th>Requirement</th>
						<th>Purpose</th>
						<th>Result</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>PHP 7.4+ </td>
						<td>Minimum version to install.</td>
						<td><?php echo $requirement1; ?></td>
					</tr>
					<tr>
						<td>cURL PHP Extension</td>
						<td>Needed to to check for updates.</td>
						<td><?php echo $requirement4; ?></td>
					</tr>
					<tr>
						<td>GD PHP Extension</td>
						<td>Needed in to create thumbnails.</td>
						<td><?php echo $requirement9; ?></td>
					</tr>
					<tr>
						<td>Zip PHP Extension</td>
						<td>Needed for making zipping and unzipping archives.</td>
						<td><?php echo $requirement10; ?></td>
					</tr>
					<tr>
						<td>OpenSSL PHP Extension</td>
						<td>Used for secure HTTPS connections.</td>
						<td><?php echo $requirement5; ?></td>
					</tr>
					<tr>
						<td>SimpleXML</td>
						<td>Used to parse XML files, both data and config.</td>
						<td><?php echo $requirement12; ?></td>
					</tr>
					<tr>
						<td>Apache Mod Rewrite</td>
						<td>Needed if you want to use FancyURLs</td>
						<td><?php echo $requirement13; ?></td>
					</tr>
					<tr>
						<td>Folder Permissions</td>
						<td>To be able to write/save to directories.</td>
						<td><?php echo $requirement14; ?></td>
					</tr>
					<tr>
						<td>allow_url_fopen</td>
						<td>Required to download files directly from URLs.</td>
						<td><?php echo $requirement16; ?></td>
					</tr>
				</tbody>
			</table>
		
		</div>
		
		<div class="col-6 card">
		
			<dl>
				<dt class="text-primary"><h4>Upgrades:</h4></dt>
				<dd><span class="warning"></span> Before any upgrade, always create a backup to protect against the unexpected!</dd>
				<dd><span class="warning"></span> GetSimple v3.3.16 or newer required.</dd>
				<dd><span class="warning"></span> If you have renamed the default <b>/admin/</b> folder, this needs to be reverted back before applying this update. After you have applied the update, you may again personalize this.</dd>
				<dd><span class="info"></span> Plugins may also require updating, especilly if migrating from older versions of PHP.</dd>
			</dl>
			<p><span class="ok"></span> Update your existing "<b>gsconfig.php</b>" with the following:</p>
			<p>Add New:</p>
<pre class="card">
# Login Page Default Language;
$LANG = 'en_EN'; // es_ES, pl_PL, de_DE, uk_UK, etc.

# Sort admin page list by title or menu
define('GSSORTPAGELISTBY','menu');

# Set CodeMirror Theme (blackboard or default)
define('GSCMTHEME','blackboard');
</pre>
			
			<p>Replace section:</p>
<pre class="card">
# WYSIWYG toolbars (advanced, basic or [custom config]) 
# define('GSEDITORTOOL', 'advanced');

# WYSIWYG Editor Options
# define('GSEDITOROPTIONS', '');
</pre>
					
			<p>With updated:</p>
<pre class="card">
# WYSIWYG toolbars (advanced, basic, advanced, island, CEbar or [custom config])
define('GSEDITORTOOL', "CEbar");

# WYSIWYG Editor Options
define('GSEDITOROPTIONS', '
extraPlugins:"fontawesome5,youtube,codemirror,cmsgrid,colorbutton,oembed,simplebutton,spacingsliders",
disableNativeSpellChecker : false,
forcePasteAsPlainText : true
');
</pre>
			
		</div>
	</div>
	
	<hr>
	
	<footer>
	<div class="row padding">
		<div class="col-6">
			Your <a href="https://getsimple-ce.ovh/donate" target="_blank">donations</a> keep GetSimple CMS CE alive.
		</div>
		<div class="col-6">Made with ❤️ by the <span class="text-primary">GS-CE team</span>. © <?php echo date("Y"); ?></div>
	</footer>
		
	<!-- ----------
	  (\ /)
	  (^.^) -{hola)
	 C(")(")
	---------- -->
	
</div>

</body>
</html>
