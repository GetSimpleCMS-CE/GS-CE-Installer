<?php

	/* **********
	* Plugin Name: gs-ce-installer
	* Description: Single file script to install or update GetSimpleCMS in 1 click.
	* Version: 2.2
	* Author: Islander / Risingisland
	* Author URI: https://github.com/risingisland
	********** */

	error_reporting(0);
	ini_set('display_errors', 0);
	set_time_limit(0);
	ini_set('max_execution_time',0);

	$installer_version = '2.2';
	$default = 'GS-CE';

	if(extension_loaded('xdebug')) {
		ini_set('xdebug.max_nesting_level', 100000);
	}

	if (!empty($_GET['target']) && Installer::doInstall($_GET['target'])) {
		exit;
	}

	header('Content-Type: text/html; charset=utf-8');

	//@TODO : add check installer version

	class Installer{
		public static $packageInfo = [
			'GS-CE' => [
				'tree' => 'GetSimpleCMS-CE ',
				'name' => 'Get-Simple CMS (v3.3.19.1)',
				'link' => 'https://github.com/GetSimpleCMS-CE/GetSimpleCMS-CE/archive/refs/tags/v3.3.19.1b.zip',
				'location' => 'admin/install.php'
			],
			'Dev' => [
				'tree' => 'Dev Branch',
				'name' => 'Dev Branch (pre-release)',
				'link' => 'https://github.com/GetSimpleCMS-CE/GetSimpleCMS-CE/archive/refs/heads/main.zip',
				'location' => 'admin/install.php'
			],
			'Patch' => [
				'tree' => 'Upgrade Patch',
				'name' => 'Upgrade to latest Version (v3.3.19.1)',
				'link' => 'https://github.com/GetSimpleCMS-CE/update-GetSimpleCMS-CE/archive/refs/heads/3.3.19.1.zip',
				'location' => 'admin/'
			]
		];

		public static function items($default=null) {
			$ItemGrid = [];
			foreach(static::$packageInfo as $ver=>$item){
				$ItemGrid[$item['tree']][$ver] = $item;
			}
			$rs = [];
			foreach($ItemGrid as $tree=>$item){
				$rs[] = '<div class="column">'.strtoupper($tree);
				foreach($item as $version => $itemInfo){
					$rs[] = sprintf(
						'<label><input type="radio" name="target" value="%s"> <span>%s</span></label><br>',
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
				return '<h2 class="warning">Cannot download the files - url_fopen is not enabled on this server.</h2>';
			}
			if (!Installer::hasDirPerm()) {
				return '<h2 class="warning">Cannot download the files - The directory does not have write permission.</h2>';
			}
			return false;
		}

		private static function downloadFile ($url, $path) {
			$rs = file_get_contents($url);
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
				if ( $object->isDir() ) {
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
			if (mkdir($folder, $perm) || is_dir($folder)) {
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
			$temp_dir = str_replace('\\','/',__DIR__).'/_temp'.md5(time());

			//run unzip and install
			static::downloadFile($rowInstall['link'] ,'fetch.zip');
			$zip = new ZipArchive;
			$zip->open($base_dir.'/fetch.zip');
			$zip->extractTo($temp_dir);
			$zip->close();
			unlink($base_dir.'/fetch.zip');

			$dir = '';
			if ($handle = opendir($temp_dir)) {
				while ($name = readdir($handle)) {
					if (!$name) {
						break;
					}
					if ($name === '.' || $name === '..') {
						continue;
					}
					$dir = $name;
				}
				closedir($handle);
			}

			static::moveFiles($temp_dir.'/'.$dir, $base_dir.'/');
			static::rmdirs($temp_dir);
			unlink(__FILE__);
			header('Location: '.$rowInstall['location']);
			return true;
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

			if (basename(__FILE__) !== 'gs-ce-installer.php') {
				return false;
			}

			$r = __DIR__.'/_index_tmp.php';
			if (!@ copy(__FILE__,$r)) {
				return false;
			}
			if (!@ unlink(__FILE__)) {
				return false;
			}
			if (!@ copy($r,__FILE__)) {
				return false;
			}
			if (!@ unlink($r)) {
				return false;
			}

			return  true;
		}
	}

	$footer="<hr class='style-two'><p style='padding-left:50px;'><small><a href='#disclaimer'>Disclaimer</a></small></p>";
	
?>

<!DOCTYPE html>
<html lang="en">
	<head>
		<title>GS-CE Installer v<?= $installer_version ?></title>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width,initial-scale=1">
		<meta name="description" content="Single File Install/Patch">
		
		<link rel="icon" type="image/png" href="  data:image/gif;base64,R0lGODlhFAAUAMQfAGtwdElOVMXHydbX2CkwNgcOFvr6+ra4uuzs7KmsruLj5PLz85ibnREYHx4lKz5ESYqNkIaJjTY8Qpyfos3P0K6xs3Z6f6CipcDCxFZbYby+wGJmaxYdJBceJf///////yH/C1hNUCBEYXRhWE1QPD94cGFja2V0IGJlZ2luPSLvu78iIGlkPSJXNU0wTXBDZWhpSHpyZVN6TlRjemtjOWQiPz4gPHg6eG1wbWV0YSB4bWxuczp4PSJhZG9iZTpuczptZXRhLyIgeDp4bXB0az0iQWRvYmUgWE1QIENvcmUgNS42LWMxNDUgNzkuMTYzNDk5LCAyMDE4LzA4LzEzLTE2OjQwOjIyICAgICAgICAiPiA8cmRmOlJERiB4bWxuczpyZGY9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkvMDIvMjItcmRmLXN5bnRheC1ucyMiPiA8cmRmOkRlc2NyaXB0aW9uIHJkZjphYm91dD0iIiB4bWxuczp4bXBNTT0iaHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wL21tLyIgeG1sbnM6c3RSZWY9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9zVHlwZS9SZXNvdXJjZVJlZiMiIHhtbG5zOnhtcD0iaHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wLyIgeG1wTU06RG9jdW1lbnRJRD0ieG1wLmRpZDo4MjMxRjU2ODc0REIxMUVEOTUwN0Y4NDgzMDIxQkRBNCIgeG1wTU06SW5zdGFuY2VJRD0ieG1wLmlpZDo4MjMxRjU2Nzc0REIxMUVEOTUwN0Y4NDgzMDIxQkRBNCIgeG1wOkNyZWF0b3JUb29sPSJBZG9iZSBQaG90b3Nob3AgQ0MgMjAxOSAoV2luZG93cykiPiA8eG1wTU06RGVyaXZlZEZyb20gc3RSZWY6aW5zdGFuY2VJRD0ieG1wLmlpZDo2RkQxQzcwQzczMkYxMUVEQjQwNUQ5QzAzQTJBRDM2OCIgc3RSZWY6ZG9jdW1lbnRJRD0ieG1wLmRpZDo2RkQxQzcwRDczMkYxMUVEQjQwNUQ5QzAzQTJBRDM2OCIvPiA8L3JkZjpEZXNjcmlwdGlvbj4gPC9yZGY6UkRGPiA8L3g6eG1wbWV0YT4gPD94cGFja2V0IGVuZD0iciI/PgH//v38+/r5+Pf29fTz8vHw7+7t7Ovq6ejn5uXk4+Lh4N/e3dzb2tnY19bV1NPS0dDPzs3My8rJyMfGxcTDwsHAv769vLu6ubi3trW0s7KxsK+urayrqqmop6alpKOioaCfnp2cm5qZmJeWlZSTkpGQj46NjIuKiYiHhoWEg4KBgH9+fXx7enl4d3Z1dHNycXBvbm1sa2ppaGdmZWRjYmFgX15dXFtaWVhXVlVUU1JRUE9OTUxLSklIR0ZFRENCQUA/Pj08Ozo5ODc2NTQzMjEwLy4tLCsqKSgnJiUkIyIhIB8eHRwbGhkYFxYVFBMSERAPDg0MCwoJCAcGBQQDAgEAACH5BAEAAB8ALAAAAAAUABQAAAX/4EcFTdGUTaeipTOJUhNMghYRaUNAAnUFnMmjYTF4jB6FpEBQeDAaj2fjaEiMFocE4xEULJ5E4dstQcKmwvbAAQ+AskCncZgWVJyTFSEdCAt5Ah4BTAMICm9qFXxSFiV1AAUOEQwLgx1VBBtcCwRkAhxjBJYBCQqEgE5DDk4CABZOHg9nFA8EAB4IDnMSglIKFYMcwVJJGSlzHQEAVA4WnmobABk4KniiAZ4NDwEPDxJ51ngNEEYGAA0UxbLI1g0ZHhMBGggEAxUS3g4c4h0FDAj8OXjQgYICDAIucOAnrsAFBHlOcDB4QAODhSp2+cO1oUGEAfYYmGjXgQEeB5zCHTQY4GGBSxIdNnxgQGCOAwAQNiwEEAGCTwkxEYQAADs=">
		
		<style>
			* { margin: 0; padding: 0; box-sizing: border-box;
		  
				/* Color scheme */
				--textcolor: #121212;
				--bgcolor: #fff;
				--highlight: #AD4F4F; /*links*/
				--title1: ##464646;
				--title1a: #9A8C8C;
				--title2: #545454;
				--title3: #AD4F4F;
			}

			@media (prefers-color-scheme: dark) {
				* {
					--textcolor: #dadada;
					--bgcolor: #141414;
					--highlight: #ffc400;
				}
				#phpinfo{color:#111;}
			}

			body,section{background:var(--bgcolor)}b,h2,h3,h4,nav a,strong{font-weight:600}hr,hr.style-one,hr.style-two{height:1px;border:0}a,abbr{text-decoration:none}blockquote,ol,ul{padding-left:2ch}article,blockquote,ol,ul{margin-bottom:.6em;max-width:60ch}blockquote,body,pre,textarea{position:relative}label+input,label+input+small,section{display:none}a,label:hover{color:var(--highlight)}body{font-size:18px;font-family:system-ui,sans-serif;line-height:1.4;color:var(--textcolor);max-width:64em;margin:0 auto}blockquote:before,header,section,textarea+label{position:absolute}section{padding:calc(6em + 5vw) 5vw 3vw;top:0;min-height:100vh;width:100%}section#home,section:target{display:block}header{padding:5vw 5vw 0;display:flex;flex-wrap:wrap;width:100%;z-index:2}header h1{font-size:1.2em;flex:1;white-space:nowrap;padding:0 5vw .5em 0;color:var(--title1)}header h1 a{color:var(--title1a)!important}nav a:not(:last-of-type){margin-right:1.5vw}a:hover{border-bottom:1px solid}section h1{font-size:1em;margin:0 0 1em}h2,h3,h4{font-size:1em;margin:1.6em 0 .6em;color:var(--title2)}p{max-width:90ch;margin-bottom:.6em;text-align:justify;text-justify:inter-word}ul{list-style-type:none}ul li::marker{content:"\2022   "}li{margin-bottom:.2em}small{font-size:.85em}hr{background:currentColor;opacity:.1;margin:1.2em 0}hr.style-one{opacity:.8;margin:-1em 0 1.2em;background:#333;background-image:linear-gradient(to right,#ccc,#333,#ccc)}hr.style-two{opacity:.8;margin:1.5em 0 3em;background:#333;background-image:linear-gradient(to right,#ccc,#333,#ccc)}abbr[title]:hover{opacity:.7;cursor:help}blockquote{opacity:.7}blockquote:before{content:"";left:0;top:.3em;bottom:.3em;background:currentColor;width:1px;opacity:.2}audio,img,svg,video{display:block;max-width:100%;height:auto;fill:currentColor}code,textarea{font-family:ui-monospace,SF Mono,Menlo,Monaco,Andale Mono,monospace;font-size:1em;opacity:.7}a code{opacity:1}pre,textarea{font-size:.9em;color:inherit;line-height:inherit;padding:.6em .9em;margin:.8em 0 1em;display:block;width:100%;white-space:pre;border:0;border-radius:4px;background:rgba(255,255,100,.075);box-shadow:inset 1px 1px 0 rgba(0,0,0,.2),inset -1px -1px 0 rgba(0,0,0,.04)}label{cursor:pointer;vertical-align:super;line-height:1;font-size:.75em;padding-left:.1em}.column label,a[href*="//"]:after{color:var(--textcolor)}input:checked+small{display:block;padding:.8em 0 1em 2.5vw}a[href*="//"]:after{font-weight:300;font-size:.85em;content:"\2197";opacity:.25}a[href*="//"]:hover:after{color:var(--highlight);opacity:1}a:before{font-size:.7em;margin-right:.4em}a[href$=".pdf"]:before{content:"PDF"}a[href$=".txt"]:before{content:"TXT"}a[href$=".mp3"]:before{content:"MP3"}a[href$=".zip"]:before{content:"ZIP"}a[href$=".rar"]:before{content:"RAR"}a[href$=".gif"]:before,a[href$=".jpeg"]:before,a[href$=".jpg"]:before,a[href$=".png"]:before{content:"IMG"}article+article{margin-top:4.5em}article h2{font-weight:700;margin:0 0 1em}article time{margin-left:.6em;font-size:.8em;font-weight:400;opacity:.7}.column{color:var(--title3)}@media only screen and (max-width:680px){body{font-size:16px}}@media only screen and (max-width:540px){nav{width:100%}}textarea+label{text-indent:-99999em}
			
			.git-button,button{color:#fff;display:inline-block;padding:3px 10px;xfont-size:20px;text-decoration:none;border:5px solid #fff;border-radius:8px;background-color:#67a749;background-image:linear-gradient(to top,#333 0,#333 27.76%,#ccc 100%);text-shadow:0 0 2px rgba(0,0,0,.64);margin-left:30px;margin-top:-3px}
			
			.content{float:left;padding:30px}.content h2{margin:0;line-height:20px}.content form{margin:10px 0 50px}.content form .column{float:left;box-sizing:border-box;width:33%;margin:20px 0}.column h3{display:inline-block;padding:0 0 5px;margin:0 0 20px;border-bottom:2px solid #000}.column label{float:left;width:100%;clear:both;padding:5px 0;font-size:16px}form button{float:left;width:120px;clear:both;margin-top:15px;padding:10px 20px;background-image:linear-gradient(to top,#67a749 0,#67a749 27.76%,#a1c755 100%)}label>span{border-bottom:1px dotted #555}label>input{margin:0 5px 0 0}.footer{position:absolute;bottom:20px;right:20px;font-size:10px;color:#ccc}.footer a{color:#aaa}.warning{float:left;padding:10px;background-color:#f9caca}
			
			.dot,.label{border-radius:4px}table{width:100%;padding:10px;border-radius:3px}table thead th{text-align:left;padding:5px 0;border:0!important}table tbody td{padding:5px 0}table tbody td:last-child,table thead th:last-child{text-align:right}.label{padding:3px 10px;color:#fff}.label.label-success{background:#4ac700}.label.label-warning{background:#dc2020}#loader{position:relative;width:44px;height:8px;margin:5px auto;padding-top:35px;padding-bottom:30px}.dot{display:inline-block;width:8px;height:8px;background:#ccc;position:absolute}.dot_1{animation:1.5s linear infinite animateDot1;left:12px;background:#e579b8}.dot_2{animation:1.5s linear .5s infinite animateDot2;left:24px}.dot_3{animation:1.5s linear infinite animateDot3;left:12px}.dot_4{animation:1.5s linear .5s infinite animateDot4;left:24px}.logo{margin-bottom:35px;margin-top:20px;display:block}.logo img{margin:0 auto;display:block}@keyframes animateDot1{0%{transform:rotate(0) translateX(-12px)}25%,75%{transform:rotate(180deg) translateX(-12px)}100%{transform:rotate(360deg) translateX(-12px)}}@keyframes animateDot2{0%{transform:rotate(0) translateX(-12px)}25%,75%{transform:rotate(-180deg) translateX(-12px)}100%{transform:rotate(-360deg) translateX(-12px)}}@keyframes animateDot3{0%{transform:rotate(0) translateX(12px)}25%,75%{transform:rotate(180deg) translateX(12px)}100%{transform:rotate(360deg) translateX(12px)}}@keyframes animateDot4{0%{transform:rotate(0) translateX(12px)}25%,75%{transform:rotate(-180deg) translateX(12px)}100%{transform:rotate(-360deg) translateX(12px)}}
		</style>
		
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/9000.0.1/themes/prism-coy.min.css" integrity="sha512-XcB0I04SuOVkb6ewfVz0qMhU5QADIiFBFxPRRNWZUANF1W5onx8GlbHYYIivw3gXrTuZfu+1gAG8HvvKQG3oGA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
		
		<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/9000.0.1/prism.min.js" integrity="sha512-UOoJElONeUNzQbbKQbjldDf9MwOHqxNz49NNJJ1d90yp+X9edsHyJoAs6O4K19CZGaIdjI5ohK+O2y5lBTW6uQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
		
		<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/9000.0.1/components/prism-php.min.js" integrity="sha512-6UGCfZS8v5U+CkSBhDy+0cA3hHrcEIlIy2++BAjetYt+pnKGWGzcn+Pynk41SIiyV2Oj0IBOLqWCKS3Oa+v/Aw==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
		
		<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/9000.0.1/components/prism-php-extras.min.js" integrity="sha512-slk6u22Z59/OgxTpC6/+BRJXb8f97I04A2KbD2nmvdrkBzequmHsf3Tm6n4iWW+Scf1j1f3qe+xj3DWtAgCXfg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    
	</head>
	
	<body>
    
    <header>
		<h1><img style="vertical-align:middle;float:left;width:40px;padding-right:10px;" src="  data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADkAAAA5CAYAAACMGIOFAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAyZpVFh0WE1MOmNvbS5hZG9iZS54bXAAAAAAADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IkFkb2JlIFhNUCBDb3JlIDUuNi1jMTQ1IDc5LjE2MzQ5OSwgMjAxOC8wOC8xMy0xNjo0MDoyMiAgICAgICAgIj4gPHJkZjpSREYgeG1sbnM6cmRmPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5LzAyLzIyLXJkZi1zeW50YXgtbnMjIj4gPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9IiIgeG1sbnM6eG1wTU09Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9tbS8iIHhtbG5zOnN0UmVmPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvc1R5cGUvUmVzb3VyY2VSZWYjIiB4bWxuczp4bXA9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC8iIHhtcE1NOkRvY3VtZW50SUQ9InhtcC5kaWQ6MTFBMzk3MUY3NERCMTFFREEzRjZFQ0RFQzQ5MzQzQkEiIHhtcE1NOkluc3RhbmNlSUQ9InhtcC5paWQ6MTFBMzk3MUU3NERCMTFFREEzRjZFQ0RFQzQ5MzQzQkEiIHhtcDpDcmVhdG9yVG9vbD0iQWRvYmUgUGhvdG9zaG9wIENDIDIwMTkgKFdpbmRvd3MpIj4gPHhtcE1NOkRlcml2ZWRGcm9tIHN0UmVmOmluc3RhbmNlSUQ9InhtcC5paWQ6NkZEMUM3MEM3MzJGMTFFREI0MDVEOUMwM0EyQUQzNjgiIHN0UmVmOmRvY3VtZW50SUQ9InhtcC5kaWQ6NkZEMUM3MEQ3MzJGMTFFREI0MDVEOUMwM0EyQUQzNjgiLz4gPC9yZGY6RGVzY3JpcHRpb24+IDwvcmRmOlJERj4gPC94OnhtcG1ldGE+IDw/eHBhY2tldCBlbmQ9InIiPz69oLqyAAAJkklEQVR42uxb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" > Single File GetSimpleCMS-CE Installer <small style="color:#ccc">v<?= $installer_version ?></small></h1>
		
		<nav>
			<a href="#home">Start</a>
			<a href="#require">Requirements</a>
			<a href="#phpinfo">PHP-Info</a>
			<a href="#help">Help</a>
		</nav>
		
    </header>
	
    <main>
      
		<!-- ----------
		---- HOME -----
		----------- -->
      
		<section id="home"> <hr class="style-one">
		
			<div class="content">
				<h2>Choose a version to install:</h2>
				<form>
					<?= Installer::items($default) ?>
					<?= Installer::hasProblem() ?: '<br><button>Install &rarr;</button>' ?>
				</form>
			</div>
			
			<p>&nbsp </p><hr class="style-two">

			<h3 style="color:#579ACD">GetSimpleCMS-CE:</h3>
			<p>🚀 <b>Full Package</b> — Adds compatability  with php 7.4 - 8.2, as well as many modern new features and additional security updates and bug fixesas, including responsive admin (Massive Admin) and base theme (ResponsiveCE), and offering many new feature and options by default. (user management, theme selection, etc.)</p>

			<h3 style="color:#579ACD">Dev Branch:</h3>
			<p>⚙️ <b>Pre Realease</b> — New improvments and features planned for the next CE release.</p>

			<h3 style="color:#579ACD">Upgrade Patch:</h3>
			<p>⚠️ GS v3.3.16 or new required.</p>
			<p>This will update your current installation, <u>over-writing existing files</u>.</p>
			
			<ul>
				<li><p><em>You should <b><u>back up your files</u></b> and store them in a safe place.<br> Just in case something goes wrong, you can restore the backup and start again. </em></p></li>
				<li><p>Updating from versions prior to v3.3.19 will need to manually update your <em style="color:orange">gsconfig.php</em> file with the following:</p>
				<xblockquote>
				<p>Add:</p>
				<code class="language-php">
# Sort admin page list by title or menu
define('GSSORTPAGELISTBY','menu');
				</code>
				<p>Replace:</p>
				<code class="language-php">
# WYSIWYG editor height (default 500)
# define('GSEDITORHEIGHT', '400');

# WYSIWYG toolbars (advanced, basic or [custom config]) 
# define('GSEDITORTOOL', 'advanced');

# WYSIWYG editor language (default en)
# define('GSEDITORLANG', 'en');

# WYSIWYG Editor Options
# define('GSEDITOROPTIONS', '');
				</code>
				<p>With:</p>
				<code class="language-php">
# WYSIWYG editor height (default 500)
# define('GSEDITORHEIGHT', '400');

# WYSIWYG editor language (default en)
# define('GSEDITORLANG', 'en');

# WYSIWYG toolbars (advanced, basic, advanced, island, CEbar or [custom config])
define('GSEDITORTOOL', "CEbar");

# WYSIWYG Editor Options
define('GSEDITOROPTIONS', '
extraPlugins:"fontawesome5,youtube,codemirror,cmsgrid,colorbutton,oembed,simplebutton,spacingsliders",
disableNativeSpellChecker : false,
forcePasteAsPlainText : true
');
				</code>
				</blockquote></li>
				<li><p><em>Some plugins, themes, language packs may be incompatible with the new version and may need to be updated. It’s recommended to check for related announcements from their authors. </em></p></li>
			</ul>
			
			<?php echo $footer; ?>
		</section> 
      
		<!-- ----------
		---- PAGE  ----
		----------- -->
      
		<section id="require" style="min-height: 500vh"> <hr class="style-one">
		
			<?php
				$error = false;
				if (version_compare(PHP_VERSION, '7.2') >= 0) {
					$requirement1 = "<span class='label label-success'>v." . PHP_VERSION . '</span>';
				} else {
					$error        = true;
					$requirement1 = "<span class='label label-warning'>Your PHP version is " . PHP_VERSION . '</span>';
				}

				if (!extension_loaded('curl')) {
					$error = true;
					$requirement4 = "<span class='label label-warning'>Not enabled</span>";
				} else {
					$requirement4 = "<span class='label label-success'>Enabled</span>";
				}

				if (!extension_loaded('gd')) {
					$error = true;
					$requirement9 = "<span class='label label-warning'>Not enabled</span>";
				} else {
					$requirement9 = "<span class='label label-success'>Enabled</span>";
				}

				if (!extension_loaded('zip')) {
					$error = true;
					$requirement10 = "<span class='label label-warning'>Zip Extension is not enabled</span>";
				} else {
					$requirement10 = "<span class='label label-success'>Enabled</span>";
				}

				if (!extension_loaded('SimpleXML')) {
					$error = true;
					$requirement12 = "<span class='label label-warning'>Not enabled</span>";
				} else {
					$requirement12 = "<span class='label label-success'>Enabled</span>";
				}

				if (!extension_loaded('openssl')) {
					$error = true;
					$requirement5 = "<span class='label label-warning'>Not enabled</span>";
				} else {
					$requirement5 = "<span class='label label-success'>Enabled</span>";
				}

				if (!apache_get_modules('mod_rewrite')) {
					$error = true;
					$requirement13 = "<span class='label label-warning'>Not enabled</span>";
				} else {
					$requirement13 = "<span class='label label-success'>Enabled</span>";
				}
				
				if ($kill == '0755') { // folder permission
					$error = true;
					$requirement14 = "<span class='label label-warning'>Not enabled</span>";
				} else {
					$requirement14 = "<span class='label label-success'>Writable</span>";
				}

			?>
			
			<h2>System Requirements:</h2>
			
			<div id="loader">
				<span class="dot dot_1"></span>
				<span class="dot dot_2"></span>
				<span class="dot dot_3"></span>
				<span class="dot dot_4"></span>
			</div>
			<table class="table table-hover" id="requirements" style="display:none;">
				<thead>
					<tr>
						<th>Requirements</th>
						<th>Result</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>PHP 7.2+ </td>
						<td><?php echo $requirement1; ?></td>
					</tr>
					<tr>
						<td>cURL PHP Extension</td>
						<td><?php echo $requirement4; ?></td>
					</tr>
					<tr>
						<td>GD PHP Extension</td>
						<td><?php echo $requirement9; ?></td>
					</tr>
					<tr>
						<td>Zip PHP Extension</td>
						<td><?php echo $requirement10; ?></td>
					</tr>
					<tr>
						<td>SimpleXML</td>
						<td><?php echo $requirement12; ?></td>
					</tr>
					<tr>
						<td>OpenSSL PHP Extension</td>
						<td><?php echo $requirement5; ?></td>
					</tr>
					<tr>
						<td>Apache Mod Rewrite</td>
						<td><?php echo $requirement13; ?></td>
					</tr>
					<tr>
						<td>Folder Permissions</td>
						<td><?php echo $requirement14; ?></td>
					</tr>
				</tbody>
			</table>
			
			<hr class="style-two">
			
			<h3>GetSimpleCMS CE 3.3.18.1:</h3>
			<p>Although these installations may work on previous versions of PHP, it is recommended to be using a minimum version of php7.2 and preferably php7.4+.</p>
			
			<h3>GetSimpleCMS (Legacy)  v3.3.16:</h3>
			<p>Via the GetSimpleCMS website, it is stated that this can be installed on php5.2 and above, we would strongly recommended a newer PHP version.</p>
			
			<?php echo $footer; ?>
		</section>

		<!-- ----------
		---- PAGE  ----
		----------- -->
      
		<section id="phpinfo">  <hr class="style-one">
		
			<h2>PHP Info:</h2>
			
			<style type="text/css">
				#phpinfo pre{margin:0;font-family:monospace}#phpinfo a[href*="//"]::after{content:""}#phpinfo a:link{color:#009;text-decoration:none;background-color:#fff}#phpinfo a:hover{text-decoration:underline}#phpinfo table{border-collapse:collapse;border:0;width:934px;box-shadow:1px 2px 3px #ccc}#phpinfo .center{text-align:center}#phpinfo .center table{margin:1em auto;text-align:left}#phpinfo .center th{text-align:center!important}#phpinfo td,th{border:1px solid #666;font-size:75%;vertical-align:baseline;padding:4px 5px}#phpinfo h1{font-size:150%}#phpinfo h2{font-size:125%}#phpinfo .p{text-align:left}#phpinfo .e{background-color:#ccf;width:300px;font-weight:700}#phpinfo .h{background-color:#99c;font-weight:700}#phpinfo .v{background-color:#ddd;max-width:300px;overflow-x:auto;word-wrap:break-word}#phpinfo .v i{color:#999}#phpinfo img{float:right;border:0}#phpinfo hr{width:934px;background-color:#ccc;border:0;height:1px;}ul li.li-spacer::marker {content: "";}
				a[href*="//"].paypal::after {content: "";}
			</style>
			
			<div id="phpinfo">
				<?php
					ob_start () ;
					phpinfo () ;
					$pinfo = ob_get_contents () ;
					ob_end_clean () ;
					
					// the name attribute "module_Zend Optimizer" of an anker-tag is not xhtml valide, so replace it with "module_Zend_Optimizer"
					echo ( str_replace ( "module_Zend Optimizer", "module_Zend_Optimizer", preg_replace ( '%^.*<body>(.*)</body>.*$%ms', '$1', $pinfo ) ) ) ;
				?>
			</div>
			
			<?php echo $footer; ?>
		</section>
	  
		<section id="help" style="min-height: 500vh"> <hr class="style-one">

			<article>
				<h2>Contributing</h2>
				<p>Contributions are always welcome!</p>
				<p>Did you find a new issue or have an improvement? Let us know via the Github button above.<br> We will try to apply a fix as soon as we can, time permitting.</p>
			</article>
			
			<article>
				<h2>Links of Importance</h2>
				<ul>
					<li>
						<a href="https://getsimple-ce.ovh/" target="_blank" rel="noopener">GetSimple CMS CE Home Page</a>
						<label for="CE-Repo">ℹ️</label><input type="checkbox" id="CE-Repo"><small>Info, news, plugins and themes.</small>
					</li>
					<li>
						<a href="https://github.com/GetSimpleCMS-CE/GetSimpleCMS-CE" target="_blank" rel="noopener">GetSimple CMS CE Repo</a>
						<label for="CE-Repo">ℹ️</label><input type="checkbox" id="CE-Repo"><small>GetSimple CMS CE Realeses.</small>
					</li>
					<li>
						<a href="https://getsimple-ce.ovh/ce-plugins/" target="_blank" rel="noopener">GetSimple CMS CE Plugins</a>
						<label for="CE-Plugins">ℹ️</label><input type="checkbox" id="CE-Plugins"><small>Growing list of php8.x compatible plugins.</small>
					</li>
					<li>
						<a href="https://discord.gg/EyjWNYTZG7" target="_blank" rel="noopener">GetSimple CMS CE Discord</a>
						<label for="CE-Discord">ℹ️</label><input type="checkbox" id="CE-Discord"><small>CE Help, Ideas, suggestions.</small>
					</li>
					
					<li class="li-spacer"></li>
					
					<li>
						<a href="http://get-simple.info/" target="_blank" rel="noopener">GetSimple CMS</a> (Official Site)
						<label for="GetSimple">ℹ️</label><input type="checkbox" id="GetSimple"><small>The Simplest Content Management System. Ever.</small>
					</li>
					<li>
						<a href="http://get-simple.info/extend/" target="_blank" rel="noopener">Plugin Repository </a> (Official Site)
						<label for="Plugin">ℹ️</label><input type="checkbox" id="Plugin"><small>Please take note that not all plugins will work with php8.x.</small>
					</li>
					<li>
						<a href="http://get-simple.info/forums/" target="_blank" rel="noopener">Support Forum</a> (Official Site)
						<label for="Forum">ℹ️</label><input type="checkbox" id="Forum"><small>Many help. Much wow.</small>
					</li>
				</ul>
			
			</article>

			<article>
				<h2>Help us help you...</h2>
				
				<blockquote>
					<p style="padding: 20px;">A small amount of gratitude goes a long ways for our time and efforts.<br>
					If you find our updates, plugins and help useful, consider buying us a coffee ☕ </p>
					<p><a href="https://www.paypal.com/donate/?hosted_button_id=C3FTNQ78HH8BE" target="_blank" class="paypal"><img alt="PayPal" src="https://www.paypalobjects.com/en_US/i/btn/btn_donate_LG.gif" border="0"></a></p>
				</blockquote>
			
			</article>  
			
			<?php echo $footer; ?>
		</section>

		<!-- ----------
		- Hidden PAGE -
		----------- -->

		<section id="disclaimer"> 
			<article>
			<h2>Disclaimer</h2>
			<h3>Do not remove this disclaimer under penalty of law.</h3>
			<p>For optimum performance and safety, please read these instructions carefully. </p>
			
			<p>Void where prohibited. No representation or warranty, express or implied, with respect to the completeness, accuracy, fitness for a particular purpose, or utility of these materials or any information or opinion contained herein. Actual mileage may vary. Prices slightly higher west of the Mississippi. All models over 18 years of age. No animals were harmed during the production of this product. Any resemblance to actual people, living or dead, or events, past, present or future, is purely coincidental. This product not to be construed as an endorsement of any product or company, nor as the adoption or promulgation of any guidelines, standards or recommendations. Some names have been changed to protect the innocent. This product is meant for educational purposes only. Some assembly required. Batteries not included. Package sold by weight, not volume. Contents may settle during shipment. No user-serviceable parts inside. Use only as directed. </p>
			<p>Do not eat. Not a toy.</p>
			<p>Postage will be paid by addressee. If condition persists, consult your physician. Subject to change without notice. Times approximate. One size fits all. Colors may, in time, fade. For office use only. Edited for television. List was current at time of printing. At participating locations only. Keep away from fire or flame. Avoid contact with skin. Sanitised for your protection. Employees and their families are not eligible. Beware of the dog. Limited time offer. No purchase necessary. Not recommended for children under 12. Prerecorded for this time zone. Some of the trademarks mentioned in this product appear for identification purposes only. Freshest if eaten before date on carton. Subject to change without notice. Please allow 4 to 6 weeks for delivery. Not responsible for direct, indirect, incidental or consequential damages resulting from any defect, error or failure to perform. Slippery when wet. Substantial penalty for early withdrawal. For recreational use only. No Canadian coins. List each check separately by bank number. This is not an offer to sell securities. </p>
			<p>Read at your own risk. Ask your doctor or pharmacist. Parental guidance advised. Always read the label. Do not use while operating a motor vehicle or heavy equipment. Do not stamp. Breaking seal constitutes acceptance of agreement. Contains non-milk fat. Date as postmark. Lost ticket pays maximum rate. Use only in well-ventilated area. Price does not include taxes. Not for resale. Hand wash only. Keep away from sunlight. For a limited time only. No preservatives or additives. Keep away from pets and small children. Safety goggles required during use. If rash, irritation, redness, or swelling develops, discontinue use. Do not fold, spindle or mutilate. Please remain seated until the web page has come to a complete stop. Refrigerate after opening. Flammable. Must be 18 years or older. Seat backs and tray tables must be in the upright position. Repeat as necessary. Do not look directly into light. Avoid extreme temperatures and store in a cool dry place. No salt, MSG, artificial colouring or flavoring added. Reproduction strictly prohibited. Pregnant women, the elderly, and children should avoid prolonged exposure to this product. If ingested, do not induce vomiting. May contain nuts. Objects in mirror may be closer than they appear. Do not use if safety seal is broken. </p>
			<p>Apply only to affected area. Do not use this product if you have high blood pressure, heart disease, diabetes, thyroid disease, asthma, glaucoma, or difficulty in urination. May be too intense for some viewers. In case of accidental ingestion, seek professional assistance or contact a poison control center immediately. Many suitcases look alike. Post office will not deliver without postage. Not the Beatles. Products are not authorized for use as critical components in life support devices or systems. Driver does not carry cash. Do not puncture or incinerate. Do not play your headset at high volume. Discontinue use of this product if any of the following occurs: itching, aching, vertigo, dizziness, ringing in your ears, vomiting, giddiness, aural or visual hallucinations, tingling in extremities, loss of balance or coordination, slurred speech, temporary blindness, drowsiness, insomnia, profuse sweating, shivering, or heart palpitations. Video+ and Video- are at ECL voltage levels, HSYNC and VSYNC are at TTL voltage levels. It is a violation of federal law to use this product in a manner inconsistent with its labeling. Intentional misuse by deliberately concentrating and inhaling the contents can be harmful or fatal. This product has been shown to cause cancer in laboratory rats. Do not use the AC adaptor provided with this player for other products. </p>
			<p>DO NOT DELETE THIS LINE -- make depend depends on it.</p>
			<p>Warranty does not cover normal wear and tear, misuse, accident, lightning, flood, hail storm, tornado, tsunami, volcanic eruption, avalanche, earthquake or tremor, hurricane, solar activity, meteorite strike, nearby supernova and other Acts of God, neglect, damage from improper or unauthorised use, incorrect line voltage, unauthorised use, unauthorised repair, improper installation, typographical errors, broken antenna or marred cabinet, missing or altered serial numbers, electromagnetic radiation from nuclear blasts, microwave ovens or mobile phones, sonic boom vibrations, ionising radiation, customer adjustments that are not covered in this list, and incidents owing to an airplane crash, ship sinking or taking on water, motor vehicle crashing, dropping the item, falling rocks, leaky roof, broken glass, disk failure, accidental file deletions, mud slides, forest fire, riots or other civil unrest, acts of terrorism or war, whether declared or not, explosive devices or projectiles (which can include, but may not be limited to, arrows, crossbow bolts, air gun pellets, bullets, shot, cannon balls, BBs, shrapnel, lasers, napalm, torpedoes, ICBMs, or emissions of electromagnetic radiation such as radio waves, microwaves, infra-red radiation, visible light, UV, X-rays, alpha, beta and gamma rays, neutrons, neutrinos, positrons, N-rays, knives, stones, bricks, spit-wads, spears, javelins etc.). </p>
			<p>Other restrictions may apply. Breach of these conditions is likely to cause unquantifiable loss that may not be capable of remedy by the payment of damages.</p>
			<h3>This supersedes all previous disclaimers</h3>
			<p>Entire contents (c) 2023 by Our Group, Inc. This disclaimer is protected by copyright and its use, copying, distribution and decompilation is restricted. All rights reserved. No part of this disclaimer or any attachments may be copied or reproduced, stored in a retrieval system, or transmitted, in any form, or by any means, optical, electronic, mechanical, photocopying, recording, telepathic, or otherwise, without the express witnessed and notarised prior written consent of the all holders of the relevant copyrights.  </p>
			<p>The information contained herein has been obtained from sources believed to be reliable. However, no warranty as to the accuracy, completeness or adequacy of such information is implied. No liability is accepted for errors, omissions or inadequacies in the information contained herein or for interpretations thereof. The reader assumes sole responsibility for the selection of these materials to achieve its intended results. The opinions expressed herein are subject to change without notice. </p>
			<blockquote>
				<p>The information in this document and any attached files is strictly private and confidential and may also be privileged. It is intended solely for and should be read only by the individual(s) or organisation(s) to whom or which it is addressed. If you are not the intended recipient, or a person responsible for delivering it to the intended recipient, notify the sender by return, delete the message, and destroy all copies of the email and associated files in your possession; you are not authorised to and must not disclose, copy, distribute, or retain this message or any part of it. It may contain information that is confidential and/or covered by legal professional or other privilege (or other rules or laws with similar effect in jurisdictions outside England and Wales). </p>
				<p>We have an anti-virus system installed on all our PCs and therefore any files leaving us via email will have been checked for known viruses, but are not guaranteed to be virus free. We accept no responsibility once an email transmission and any attachments have left us. </p>
			</blockquote>
			<p><strong>No part of this message is intended to form any part of any contract. The views expressed in this message are not necessarily the views of my employer, and the company, its directors, officers or employees make no representation or accept any liability for its accuracy or completeness, unless expressly stated to the contrary. This message is not intended to be relied upon without subsequent written confirmation of its contents. This company therefore shall not accept any liability of any kind which may arise from any person acting upon the contents of this message without having had written confirmation. </strong></p>
			<p>This document originates from the Internet, and therefore may not be from the alleged source. If you have any doubts about the origin or content of this document please contact our Support Desk. </p>
			</article>
			
			<p><a href="#home">← back</a></p>
			<?php echo $footer; ?>
		</section>
      
    </main>
	
	<script>
        var loading = {
            complete: function () {
                var loading = document.getElementById("loader");
                loading.remove(loading);
            }
        };
        document.addEventListener("readystatechange", function () {
            if (document.readyState === "complete") {
                setTimeout(function(){
                    loading.complete();
                    var requirements = document.getElementById("requirements");
                    requirements.style['display'] = null;
                },3000);
            }
        });
    </script>
	
	<!-- ----------
	  (\ /)
	  (^.^) -{hola)
	 C(")(")
	---------- -->
	
  </body>
</html>
