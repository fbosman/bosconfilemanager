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
 * \file    bosconfilemanager/ajax/tree.php
 * \ingroup bosconfilemanager
 * \brief   Return the subdirectories of a directory as JSON (lazy loading of the directory tree).
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

dol_include_once('/bosconfilemanager/lib/bosconfilemanager.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

if (!isModEnabled('bosconfilemanager') || !bosconfilemanagerCan($user, 'read')) {
	bosconfilemanagerHttpError('Access forbidden', 403);
}

$target = bosconfilemanagerResolve(GETPOST('dir', 'none'));
if ($target === false || !is_dir($target['abs'])) {
	bosconfilemanagerHttpError('Not found', 404);
}

$showhidden = (bool) getDolGlobalInt('BOSCONFILEMANAGER_SHOW_HIDDEN');
$result = array();
$entries = bosconfilemanagerListDir($target['abs'], $showhidden);
foreach ((array) $entries as $e) {
	if (!$e['isdir']) {
		continue;
	}
	$rel = ($target['rel'] === '' ? '' : $target['rel'].'/').$e['name'];
	$result[] = array(
		'name' => $e['name'],
		'rel' => $rel,
		'hassub' => bosconfilemanagerHasSubdirs($target['abs'].'/'.$e['name'], $showhidden),
		'url' => bosconfilemanagerUrl('explorer.php', array('dir' => $rel)),
	);
}

top_httphead('application/json');
print json_encode($result);
$db->close();
