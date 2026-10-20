<?php
/* Copyright (C) 2026 Boscon
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    bosconfilemanager/lib/bosconfilemanager.lib.php
 * \ingroup bosconfilemanager
 * \brief   Library functions for BosConfilemanager: path resolution, access checks, backups.
 *
 * All paths handled by the module are relative to the Dolibarr root (DOL_DOCUMENT_ROOT), use "/" as
 * separator and never start or end with "/". The empty string is the root itself.
 */

/**
 * Prepare admin pages header
 *
 * @return array<array{0:string,1:string,2:string}>
 */
function bosconfilemanagerAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("bosconfilemanager@bosconfilemanager");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/bosconfilemanager/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'bosconfilemanager@bosconfilemanager');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'bosconfilemanager@bosconfilemanager', 'remove');

	return $head;
}

/**
 * Stop with "access forbidden" unless the user is a Dolibarr admin with the given module right.
 *
 * @param  User   $user  Current user
 * @param  string $right 'read', 'write' or 'delete'
 * @return void
 */
function bosconfilemanagerCheckAccess($user, $right = 'read')
{
	if (!isModEnabled('bosconfilemanager') || !bosconfilemanagerCan($user, $right)) {
		accessforbidden();
	}
}

/**
 * Return true if the user is a Dolibarr admin with the given module right.
 *
 * @param  User   $user  Current user
 * @param  string $right 'read', 'write' or 'delete'
 * @return bool
 */
function bosconfilemanagerCan($user, $right)
{
	return !empty($user->admin) && $user->hasRight('bosconfilemanager', $right);
}

/**
 * Stop unless the request carries the CSRF token of the form that was shown (main.inc.php checks it too,
 * this is an extra guard for every state changing action).
 *
 * @return void
 */
function bosconfilemanagerCheckToken()
{
	if (GETPOST('token', 'alpha') === '' || GETPOST('token', 'alpha') !== currentToken()) {
		accessforbidden('Bad value for CSRF token');
	}
}

/**
 * Real path of the root of the file manager (the Dolibarr root).
 *
 * @return string Absolute path without trailing slash
 */
function bosconfilemanagerRoot()
{
	static $root = null;
	if ($root === null) {
		$root = rtrim(str_replace('\\', '/', (string) realpath(DOL_DOCUMENT_ROOT)), '/');
	}
	return $root;
}

/**
 * Normalize a relative path given by the user: unify separators and strip leading/trailing slashes.
 *
 * @param  string $rel Relative path
 * @return string
 */
function bosconfilemanagerCleanRel($rel)
{
	$rel = str_replace('\\', '/', (string) $rel);
	$rel = preg_replace('#/+#', '/', $rel);
	return trim($rel, '/');
}

/**
 * Resolve an existing file or directory inside the root.
 *
 * Symbolic links are followed, but the target must also be inside the root.
 *
 * @param  string       $rel Path relative to the root
 * @return array{abs:string,rel:string}|false  Absolute and canonical relative path, or false if outside the root or missing
 */
function bosconfilemanagerResolve($rel)
{
	$rel = bosconfilemanagerCleanRel($rel);
	if (strpos($rel, "\0") !== false) {
		return false;
	}
	$root = bosconfilemanagerRoot();
	$real = realpath($root.($rel === '' ? '' : '/'.$rel));
	if ($real === false) {
		return false;
	}
	$real = rtrim(str_replace('\\', '/', $real), '/');
	if ($real === $root) {
		return array('abs' => $root, 'rel' => '');
	}
	if (strpos($real, $root.'/') !== 0) {
		return false;
	}
	return array('abs' => $real, 'rel' => substr($real, strlen($root) + 1));
}

/**
 * Resolve a new (not yet existing) entry with name $name inside the existing directory $dirrel.
 *
 * @param  string $dirrel Existing directory relative to the root
 * @param  string $name   New file or directory name (no slashes)
 * @return array{abs:string,rel:string}|false
 */
function bosconfilemanagerResolveNew($dirrel, $name)
{
	if (!bosconfilemanagerValidName($name)) {
		return false;
	}
	$dir = bosconfilemanagerResolve($dirrel);
	if ($dir === false || !is_dir($dir['abs'])) {
		return false;
	}
	return array(
		'abs' => $dir['abs'].'/'.$name,
		'rel' => ($dir['rel'] === '' ? '' : $dir['rel'].'/').$name,
	);
}

/**
 * Check a single file or directory name.
 *
 * @param  string $name Name
 * @return bool
 */
function bosconfilemanagerValidName($name)
{
	$name = (string) $name;
	if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 255) {
		return false;
	}
	return !preg_match('#[/\\\\\x00-\x1f]#', $name);
}

/**
 * Relative path of the parent directory.
 *
 * @param  string $rel Relative path
 * @return string
 */
function bosconfilemanagerParent($rel)
{
	$pos = strrpos($rel, '/');
	return $pos === false ? '' : substr($rel, 0, $pos);
}

/**
 * Read the entries of a directory, directories first, sorted.
 *
 * @param  string $abs        Absolute directory path
 * @param  bool   $showhidden Include entries starting with a dot
 * @param  string $sortfield  'name', 'size' or 'date'
 * @param  string $sortorder  'asc' or 'desc'
 * @return array<array{name:string,isdir:bool,islink:bool,size:int,mtime:int,perms:string,writable:bool}>|false
 */
function bosconfilemanagerListDir($abs, $showhidden = true, $sortfield = 'name', $sortorder = 'asc')
{
	$names = @scandir($abs);
	if ($names === false) {
		return false;
	}
	$dirs = array();
	$files = array();
	foreach ($names as $name) {
		if ($name === '.' || $name === '..' || (!$showhidden && $name[0] === '.')) {
			continue;
		}
		$path = $abs.'/'.$name;
		$isdir = is_dir($path);
		$entry = array(
			'name' => $name,
			'isdir' => $isdir,
			'islink' => is_link($path),
			'size' => $isdir ? 0 : (int) @filesize($path),
			'mtime' => (int) @filemtime($path),
			'perms' => bosconfilemanagerPerms($path),
			'writable' => is_writable($path),
		);
		if ($isdir) {
			$dirs[] = $entry;
		} else {
			$files[] = $entry;
		}
	}
	$cmp = function ($a, $b) use ($sortfield, $sortorder) {
		if ($sortfield === 'size' && $a['size'] != $b['size']) {
			$r = $a['size'] < $b['size'] ? -1 : 1;
		} elseif ($sortfield === 'date' && $a['mtime'] != $b['mtime']) {
			$r = $a['mtime'] < $b['mtime'] ? -1 : 1;
		} else {
			$r = strnatcasecmp($a['name'], $b['name']);
		}
		return $sortorder === 'desc' ? -$r : $r;
	};
	usort($dirs, $cmp);
	usort($files, $cmp);
	return array_merge($dirs, $files);
}

/**
 * Return true if the directory contains at least one subdirectory (used by the tree).
 *
 * @param  string $abs        Absolute directory path
 * @param  bool   $showhidden Count hidden directories
 * @return bool
 */
function bosconfilemanagerHasSubdirs($abs, $showhidden = true)
{
	$dh = @opendir($abs);
	if ($dh === false) {
		return false;
	}
	$found = false;
	while (($name = readdir($dh)) !== false) {
		if ($name === '.' || $name === '..' || (!$showhidden && $name[0] === '.')) {
			continue;
		}
		if (is_dir($abs.'/'.$name)) {
			$found = true;
			break;
		}
	}
	closedir($dh);
	return $found;
}

/**
 * Unix style permission string, e.g. "rw-r--r--".
 *
 * @param  string $path Absolute path
 * @return string
 */
function bosconfilemanagerPerms($path)
{
	$p = @fileperms($path);
	if ($p === false) {
		return '';
	}
	$s = '';
	$bits = array(0400, 0200, 0100, 040, 020, 010, 04, 02, 01);
	$chars = 'rwxrwxrwx';
	foreach ($bits as $i => $bit) {
		$s .= ($p & $bit) ? $chars[$i] : '-';
	}
	return $s;
}

/**
 * Detect whether a file is text (editable) by looking for NUL bytes in its first 8 KB.
 *
 * @param  string $abs Absolute path
 * @return bool
 */
function bosconfilemanagerIsText($abs)
{
	$fh = @fopen($abs, 'rb');
	if ($fh === false) {
		return false;
	}
	$chunk = (string) fread($fh, 8192);
	fclose($fh);
	return strpos($chunk, "\0") === false;
}

/**
 * Kind of inline preview available for a file, based on its extension.
 *
 * @param  string $name File name
 * @return string 'image', 'pdf' or ''
 */
function bosconfilemanagerPreviewType($name)
{
	$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
	if (in_array($ext, array('png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'ico', 'svg'))) {
		return 'image';
	}
	if ($ext === 'pdf') {
		return 'pdf';
	}
	return '';
}

/**
 * ACE editor mode for a file name.
 *
 * @param  string $name File name
 * @return string ACE mode name (without "ace/mode/")
 */
function bosconfilemanagerAceMode($name)
{
	$lower = strtolower($name);
	if ($lower === '.htaccess' || $lower === 'htaccess.txt') {
		return 'apache_conf';
	}
	if ($lower === 'dockerfile') {
		return 'dockerfile';
	}
	if ($lower === 'makefile') {
		return 'makefile';
	}
	$map = array(
		'php' => 'php', 'phtml' => 'php', 'inc' => 'php', 'lib' => 'php', 'class' => 'php', 'tpl' => 'php',
		'js' => 'javascript', 'mjs' => 'javascript', 'cjs' => 'javascript', 'ts' => 'typescript',
		'json' => 'json', 'css' => 'css', 'scss' => 'scss', 'less' => 'less',
		'html' => 'html', 'htm' => 'html', 'xml' => 'xml', 'svg' => 'svg', 'xsl' => 'xml', 'odt' => 'xml',
		'sql' => 'sql', 'md' => 'markdown', 'markdown' => 'markdown', 'yml' => 'yaml', 'yaml' => 'yaml',
		'ini' => 'ini', 'conf' => 'ini', 'lang' => 'ini', 'sh' => 'sh', 'bash' => 'sh', 'py' => 'python',
		'txt' => 'text', 'csv' => 'text', 'log' => 'text', 'twig' => 'twig', 'vue' => 'html',
	);
	$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
	return isset($map[$ext]) ? $map[$ext] : 'text';
}

/**
 * Directory where backups are stored.
 *
 * @return string Absolute path
 */
function bosconfilemanagerBackupDir()
{
	global $conf;
	$base = !empty($conf->bosconfilemanager->dir_output) ? $conf->bosconfilemanager->dir_output : DOL_DATA_ROOT.'/bosconfilemanager';
	return $base.'/backup';
}

/**
 * Copy a file into the backup directory before it is overwritten, restored or deleted.
 * Backups keep the relative path of the file and get a timestamp suffix: <rel>.<YYYYmmddHHMMSS>.bak
 *
 * @param  string      $abs Absolute path of the existing file
 * @param  string      $rel Relative path of the file
 * @return string|bool      Absolute path of the backup, true if backups are disabled, false on error
 */
function bosconfilemanagerBackup($abs, $rel)
{
	if (!getDolGlobalInt('BOSCONFILEMANAGER_BACKUP_ON_SAVE') || !is_file($abs)) {
		return true;
	}
	$target = bosconfilemanagerBackupDir().'/'.$rel.'.'.dol_print_date(dol_now(), '%Y%m%d%H%M%S', 'gmt').'.bak';
	if (dol_mkdir(dirname($target)) < 0) {
		return false;
	}
	// Two saves in the same second: keep the first backup (it holds the oldest content)
	if (file_exists($target)) {
		return $target;
	}
	return @copy($abs, $target) ? $target : false;
}

/**
 * List the backups of one file, newest first.
 *
 * @param  string $rel Relative path of the file
 * @return array<array{name:string,abs:string,size:int,date:int}>
 */
function bosconfilemanagerListBackups($rel)
{
	$dir = bosconfilemanagerBackupDir().'/'.bosconfilemanagerParent($rel);
	$base = basename($rel);
	$result = array();
	$names = @scandir($dir);
	if ($names === false) {
		return $result;
	}
	foreach ($names as $name) {
		if (preg_match('/^'.preg_quote($base, '/').'\.(\d{14})\.bak$/', $name, $m)) {
			$result[] = array(
				'name' => $name,
				'abs' => $dir.'/'.$name,
				'size' => (int) filesize($dir.'/'.$name),
				'date' => (int) bosconfilemanagerBackupDate($m[1]),
			);
		}
	}
	usort($result, function ($a, $b) {
		return strcmp($b['name'], $a['name']);
	});
	return $result;
}

/**
 * Delete a directory recursively. Symbolic links are removed, never followed.
 *
 * @param  string $abs Absolute path
 * @return bool
 */
function bosconfilemanagerDeleteTree($abs)
{
	if (is_link($abs) || !is_dir($abs)) {
		return @unlink($abs);
	}
	$names = @scandir($abs);
	if ($names === false) {
		return false;
	}
	$ok = true;
	foreach ($names as $name) {
		if ($name === '.' || $name === '..') {
			continue;
		}
		$ok = bosconfilemanagerDeleteTree($abs.'/'.$name) && $ok;
	}
	return @rmdir($abs) && $ok;
}

/**
 * Write content to a file (atomically when possible) and clear the opcode cache for it.
 *
 * @param  string $abs     Absolute path
 * @param  string $content Content
 * @return bool
 */
function bosconfilemanagerWriteFile($abs, $content)
{
	$isnew = !file_exists($abs);
	if (@file_put_contents($abs, $content, LOCK_EX) === false) {
		return false;
	}
	if ($isnew) {
		bosconfilemanagerChmod($abs);
	}
	bosconfilemanagerInvalidate($abs);
	return true;
}

/**
 * Apply the Dolibarr umask to a newly created file.
 *
 * @param  string $abs Absolute path
 * @return void
 */
function bosconfilemanagerChmod($abs)
{
	if (function_exists('dolChmod')) {
		dolChmod($abs);
	} elseif (getDolGlobalString('MAIN_UMASK')) {
		@chmod($abs, octdec(getDolGlobalString('MAIN_UMASK')));
	}
}

/**
 * Drop a PHP file from the opcode cache so the new version is used at once.
 *
 * @param  string $abs Absolute path
 * @return void
 */
function bosconfilemanagerInvalidate($abs)
{
	clearstatcache(true, $abs);
	if (function_exists('opcache_invalidate') && preg_match('/\.(php|inc|phtml)$/i', $abs)) {
		@opcache_invalidate($abs, true);
	}
}

/**
 * Check PHP syntax of a source string without executing it.
 *
 * @param  string $code PHP source
 * @return string       Error message ('' when the syntax is valid)
 */
function bosconfilemanagerPhpSyntaxError($code)
{
	try {
		token_get_all($code, TOKEN_PARSE);
	} catch (ParseError $e) {
		return $e->getMessage().' (line '.$e->getLine().')';
	}
	return '';
}

/**
 * Log a change made through the file manager.
 *
 * @param  User   $user   Current user
 * @param  string $action Action code
 * @param  string $rel    Relative path
 * @param  string $more   Extra info
 * @return void
 */
function bosconfilemanagerLog($user, $action, $rel, $more = '')
{
	dol_syslog('BosConfilemanager: user '.$user->login.' '.$action.' "/'.$rel.'"'.($more !== '' ? ' '.$more : ''), LOG_NOTICE);
}

/**
 * URL of a page of the module with a path parameter.
 *
 * @param  string               $page  Page file name, e.g. 'explorer.php'
 * @param  array<string,string> $params Query parameters
 * @return string
 */
function bosconfilemanagerUrl($page, $params = array())
{
	$url = dol_buildpath('/bosconfilemanager/'.$page, 1);
	if (!empty($params)) {
		$url .= '?'.http_build_query($params, '', '&');
	}
	return $url;
}

/**
 * Print the breadcrumb of a path, every part linking to the explorer.
 *
 * @param  string $rel       Relative path
 * @param  bool   $lastislink Make the last part a link too
 * @return string HTML
 */
function bosconfilemanagerBreadcrumb($rel, $lastislink = false)
{
	global $langs;

	$out = '<div class="bcfm-breadcrumb">';
	$out .= '<a href="'.bosconfilemanagerUrl('explorer.php').'" title="'.dol_escape_htmltag(bosconfilemanagerRoot()).'">'.img_picto('', 'fa-home', 'class="pictofixedwidth"').dol_escape_htmltag($langs->trans('BcfmRoot')).'</a>';
	if ($rel !== '') {
		$parts = explode('/', $rel);
		$acc = '';
		$n = count($parts);
		foreach ($parts as $i => $part) {
			$acc .= ($acc === '' ? '' : '/').$part;
			$out .= '<span class="bcfm-sep">/</span>';
			if ($i < $n - 1 || $lastislink) {
				$out .= '<a href="'.bosconfilemanagerUrl('explorer.php', array('dir' => $acc)).'">'.dol_escape_htmltag($part).'</a>';
			} else {
				$out .= '<strong>'.dol_escape_htmltag($part).'</strong>';
			}
		}
	}
	$out .= '</div>';
	return $out;
}

/**
 * Send headers that stop the browser from running scripts in a served file.
 *
 * @return void
 */
function bosconfilemanagerSafeHeaders()
{
	header('X-Content-Type-Options: nosniff');
	header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
}

/**
 * Stop with a plain text HTTP error (for download and ajax endpoints).
 *
 * @param  string $message Message
 * @param  int    $code    HTTP status code
 * @return never
 */
function bosconfilemanagerHttpError($message, $code = 403)
{
	http_response_code($code);
	header('Content-Type: text/plain; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
	print $message;
	exit;
}

/**
 * Timestamp of a backup id (YYYYmmddHHMMSS, UTC).
 *
 * @param  string $id Backup id
 * @return int|string Timestamp, '' if the id is not valid
 */
function bosconfilemanagerBackupDate($id)
{
	if (!preg_match('/^\d{14}$/', (string) $id)) {
		return '';
	}
	return dol_mktime((int) substr($id, 8, 2), (int) substr($id, 10, 2), (int) substr($id, 12, 2), (int) substr($id, 4, 2), (int) substr($id, 6, 2), (int) substr($id, 0, 4), 'gmt');
}

/**
 * Prepare a file name or message for use as parameter of $langs->trans(). trans() html-encodes its result
 * but keeps some tags ("<a ", "<span", "<b>"...) as is, so angle brackets are replaced to keep names inert.
 *
 * @param  string $value Value
 * @return string
 */
function bosconfilemanagerTransParam($value)
{
	return str_replace(array('<', '>'), array("\u{2039}", "\u{203A}"), (string) $value);
}
