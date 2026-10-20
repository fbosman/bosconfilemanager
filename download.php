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
 * \file    bosconfilemanager/download.php
 * \ingroup bosconfilemanager
 * \brief   Download a file, show an image/PDF inline, download a directory as ZIP or download a backup.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');	// Images in the editor page must not invalidate the token of its form
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
dol_include_once('/bosconfilemanager/lib/bosconfilemanager.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("bosconfilemanager@bosconfilemanager"));

bosconfilemanagerCheckAccess($user, 'read');

$inline = GETPOSTINT('inline');
$zip = GETPOSTINT('zip');
$backup = GETPOST('backup', 'none');

$target = bosconfilemanagerResolve(GETPOST('file', 'none'));
if ($target === false) {
	bosconfilemanagerHttpError($langs->trans('BcfmErrorPathNotFound', ''), 404);
}

// A backup of the given file
if ($backup !== '') {
	if (!preg_match('/^\d{14}$/', $backup)) {
		bosconfilemanagerHttpError('Bad backup id', 400);
	}
	$path = bosconfilemanagerBackupDir().'/'.$target['rel'].'.'.$backup.'.bak';
	if (!is_file($path)) {
		bosconfilemanagerHttpError($langs->trans('BcfmErrorPathNotFound', ''), 404);
	}
	bosconfilemanagerSendFile($path, basename($target['rel']).'.'.$backup.'.bak', false);
	exit;
}

// A directory as ZIP archive
if (is_dir($target['abs'])) {
	if (!$zip || !class_exists('ZipArchive')) {
		bosconfilemanagerHttpError('Not a file', 400);
	}
	@set_time_limit(0);
	$tmpfile = tempnam(sys_get_temp_dir(), 'bcfm');
	$archive = new ZipArchive();
	if ($tmpfile === false || $archive->open($tmpfile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
		bosconfilemanagerHttpError('Unable to create ZIP archive', 500);
	}
	$basename = ($target['rel'] === '' ? 'htdocs' : basename($target['rel']));
	bosconfilemanagerZipAdd($archive, $target['abs'], $basename);
	$archive->close();
	bosconfilemanagerLog($user, 'downloadzip', $target['rel']);
	bosconfilemanagerSendFile($tmpfile, $basename.'.zip', false, 'application/zip');
	@unlink($tmpfile);
	exit;
}

// A single file
$filename = basename($target['abs']);
$previewtype = bosconfilemanagerPreviewType($filename);
bosconfilemanagerSendFile($target['abs'], $filename, $inline && $previewtype !== '');
exit;


/**
 * Send a file to the browser.
 *
 * @param  string $path     Absolute path
 * @param  string $filename File name proposed to the browser
 * @param  bool   $inline   Show in the browser instead of downloading
 * @param  string $mime     Mime type (detected from the name when empty)
 * @return void
 */
function bosconfilemanagerSendFile($path, $filename, $inline, $mime = '')
{
	if ($mime === '') {
		$mime = $inline ? dol_mimetype($filename) : 'application/octet-stream';
	}
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	header('Content-Type: '.$mime);
	header('Content-Length: '.filesize($path));
	header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.str_replace(array('"', "\r", "\n"), '', $filename).'"; filename*=UTF-8\'\''.rawurlencode($filename));
	header('Cache-Control: private, no-cache, no-store, must-revalidate');
	if ($inline && $mime !== 'application/pdf') {
		// Images (also SVG) may never run scripts in the Dolibarr origin. PDF viewers do not work in a sandbox.
		bosconfilemanagerSafeHeaders();
	} else {
		header('X-Content-Type-Options: nosniff');
	}
	readfile($path);
}

/**
 * Add a directory recursively to a ZIP archive. Symbolic links are skipped.
 *
 * @param  ZipArchive $archive Archive
 * @param  string     $abs     Absolute directory path
 * @param  string     $prefix  Path inside the archive
 * @return void
 */
function bosconfilemanagerZipAdd($archive, $abs, $prefix)
{
	$archive->addEmptyDir($prefix);
	$names = @scandir($abs);
	if ($names === false) {
		return;
	}
	foreach ($names as $name) {
		if ($name === '.' || $name === '..' || is_link($abs.'/'.$name)) {
			continue;
		}
		if (is_dir($abs.'/'.$name)) {
			bosconfilemanagerZipAdd($archive, $abs.'/'.$name, $prefix.'/'.$name);
		} elseif (is_readable($abs.'/'.$name)) {
			$archive->addFile($abs.'/'.$name, $prefix.'/'.$name);
		}
	}
}
