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
 * \file    bosconfilemanager/explorer.php
 * \ingroup bosconfilemanager
 * \brief   File explorer: directory tree, file list, upload, new file/directory, rename, delete.
 */

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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/bosconfilemanager/lib/bosconfilemanager.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "other", "errors", "bosconfilemanager@bosconfilemanager"));

bosconfilemanagerCheckAccess($user, 'read');

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$name = GETPOST('name', 'none');
$sortfield = GETPOST('sortfield', 'aZ09');
$sortorder = strtolower(GETPOST('sortorder', 'aZ09'));
if (!in_array($sortfield, array('name', 'size', 'date'))) {
	$sortfield = 'name';
}
if ($sortorder !== 'desc') {
	$sortorder = 'asc';
}

$current = bosconfilemanagerResolve(GETPOST('dir', 'none'));
if ($current === false || !is_dir($current['abs'])) {
	if (GETPOSTISSET('dir')) {
		setEventMessages($langs->trans('BcfmErrorPathNotFound', GETPOST('dir', 'none')), null, 'errors');
	}
	$current = bosconfilemanagerResolve('');
}
$dir = $current['rel'];
$backurl = bosconfilemanagerUrl('explorer.php', array('dir' => $dir, 'sortfield' => $sortfield, 'sortorder' => $sortorder));

// The entry an action applies to (a name inside the current directory)
$item = false;
if ($name !== '' && bosconfilemanagerValidName($name)) {
	$item = bosconfilemanagerResolve(($dir === '' ? '' : $dir.'/').$name);
}


/*
 * Actions
 */

if ($action === 'upload' && bosconfilemanagerCan($user, 'write')) {
	bosconfilemanagerCheckToken();
	$overwrite = GETPOSTINT('overwrite');
	$nbok = 0;
	if (empty($_FILES['userfile']['name']) || !is_array($_FILES['userfile']['name'])) {
		setEventMessages($langs->trans('BcfmErrorNoFileSelected'), null, 'errors');
	} else {
		foreach ($_FILES['userfile']['name'] as $k => $filename) {
			$error = (int) $_FILES['userfile']['error'][$k];
			if ($error === UPLOAD_ERR_NO_FILE) {
				continue;
			}
			if ($error !== UPLOAD_ERR_OK) {
				$msg = ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) ? $langs->trans('ErrorFileSizeTooLarge') : $langs->trans('BcfmErrorUpload', (string) $error);
				setEventMessages(dol_escape_htmltag($filename).': '.$msg, null, 'errors');
				continue;
			}
			$target = bosconfilemanagerResolveNew($dir, $filename);
			if ($target === false) {
				setEventMessages($langs->trans('BcfmErrorBadName', bosconfilemanagerTransParam($filename)), null, 'errors');
				continue;
			}
			if (file_exists($target['abs'])) {
				if (!$overwrite || is_dir($target['abs'])) {
					setEventMessages($langs->trans('BcfmErrorExists', bosconfilemanagerTransParam($filename)), null, 'errors');
					continue;
				}
				if (bosconfilemanagerBackup($target['abs'], $target['rel']) === false) {
					setEventMessages($langs->trans('BcfmErrorBackup', bosconfilemanagerTransParam($filename)), null, 'errors');
					continue;
				}
			}
			$isnew = !file_exists($target['abs']);
			if (!@move_uploaded_file($_FILES['userfile']['tmp_name'][$k], $target['abs'])) {
				setEventMessages($langs->trans('BcfmErrorWrite', bosconfilemanagerTransParam($target['rel'])), null, 'errors');
				continue;
			}
			if ($isnew) {
				bosconfilemanagerChmod($target['abs']);
			}
			bosconfilemanagerInvalidate($target['abs']);
			bosconfilemanagerLog($user, 'upload', $target['rel'], $isnew ? '' : '(overwrite)');
			$nbok++;
		}
		if ($nbok) {
			setEventMessages($langs->trans('BcfmUploaded', $nbok), null, 'mesgs');
		}
	}
	header('Location: '.$backurl);
	exit;
}

if (in_array($action, array('confirm_mkdir', 'confirm_mkfile')) && $confirm === 'yes' && bosconfilemanagerCan($user, 'write')) {
	bosconfilemanagerCheckToken();
	$newname = trim(GETPOST('newname', 'none'));
	$target = bosconfilemanagerResolveNew($dir, $newname);
	if ($target === false) {
		setEventMessages($langs->trans('BcfmErrorBadName', bosconfilemanagerTransParam($newname)), null, 'errors');
	} elseif (file_exists($target['abs']) || is_link($target['abs'])) {
		setEventMessages($langs->trans('BcfmErrorExists', bosconfilemanagerTransParam($newname)), null, 'errors');
	} elseif ($action === 'confirm_mkdir') {
		if (dol_mkdir($target['abs']) < 0) {
			setEventMessages($langs->trans('BcfmErrorWrite', bosconfilemanagerTransParam($target['rel'])), null, 'errors');
		} else {
			bosconfilemanagerLog($user, 'mkdir', $target['rel']);
			setEventMessages($langs->trans('BcfmDirCreated', bosconfilemanagerTransParam($newname)), null, 'mesgs');
		}
	} else {
		if (!bosconfilemanagerWriteFile($target['abs'], '')) {
			setEventMessages($langs->trans('BcfmErrorWrite', bosconfilemanagerTransParam($target['rel'])), null, 'errors');
		} else {
			bosconfilemanagerLog($user, 'mkfile', $target['rel']);
			header('Location: '.bosconfilemanagerUrl('edit.php', array('file' => $target['rel'], 'mode' => 'edit')));
			exit;
		}
	}
	header('Location: '.$backurl);
	exit;
}

if ($action === 'confirm_rename' && $confirm === 'yes' && bosconfilemanagerCan($user, 'write')) {
	bosconfilemanagerCheckToken();
	$newname = trim(GETPOST('newname', 'none'));
	$target = bosconfilemanagerResolveNew($dir, $newname);
	// Use the link itself (not its target) when renaming a symbolic link
	$source = bosconfilemanagerRoot().($dir === '' ? '' : '/'.$dir).'/'.$name;
	if ($item === false) {
		setEventMessages($langs->trans('BcfmErrorPathNotFound', bosconfilemanagerTransParam($name)), null, 'errors');
	} elseif ($target === false) {
		setEventMessages($langs->trans('BcfmErrorBadName', bosconfilemanagerTransParam($newname)), null, 'errors');
	} elseif ((file_exists($target['abs']) || is_link($target['abs'])) && strtolower($newname) !== strtolower($name)) {
		// Same name with other case is allowed (case insensitive file systems report it as existing)
		setEventMessages($langs->trans('BcfmErrorExists', bosconfilemanagerTransParam($newname)), null, 'errors');
	} elseif ($newname !== $name) {
		if (!@rename($source, $target['abs'])) {
			setEventMessages($langs->trans('BcfmErrorWrite', bosconfilemanagerTransParam($target['rel'])), null, 'errors');
		} else {
			bosconfilemanagerInvalidate($source);
			bosconfilemanagerInvalidate($target['abs']);
			bosconfilemanagerLog($user, 'rename', ($dir === '' ? '' : $dir.'/').$name, 'to "'.$newname.'"');
			setEventMessages($langs->trans('BcfmRenamed', bosconfilemanagerTransParam($name), bosconfilemanagerTransParam($newname)), null, 'mesgs');
		}
	}
	header('Location: '.$backurl);
	exit;
}

if ($action === 'confirm_delete' && $confirm === 'yes' && bosconfilemanagerCan($user, 'delete')) {
	bosconfilemanagerCheckToken();
	$path = bosconfilemanagerRoot().($dir === '' ? '' : '/'.$dir).'/'.$name;
	$rel = ($dir === '' ? '' : $dir.'/').$name;
	if (!bosconfilemanagerValidName($name) || (!file_exists($path) && !is_link($path))) {
		setEventMessages($langs->trans('BcfmErrorPathNotFound', bosconfilemanagerTransParam($name)), null, 'errors');
	} elseif (!is_link($path) && $item === false) {
		// A real path outside the root (through a symlinked parent): refuse
		accessforbidden();
	} elseif (!is_link($path) && is_dir($path)) {
		if (!bosconfilemanagerDeleteTree($path)) {
			setEventMessages($langs->trans('BcfmErrorDelete', bosconfilemanagerTransParam($rel)), null, 'errors');
		} else {
			bosconfilemanagerLog($user, 'rmdir', $rel);
			setEventMessages($langs->trans('BcfmDeleted', bosconfilemanagerTransParam($name)), null, 'mesgs');
		}
	} else {
		if (!is_link($path) && bosconfilemanagerBackup($path, $rel) === false) {
			setEventMessages($langs->trans('BcfmErrorBackup', bosconfilemanagerTransParam($name)), null, 'errors');
		} elseif (!@unlink($path)) {
			setEventMessages($langs->trans('BcfmErrorDelete', bosconfilemanagerTransParam($rel)), null, 'errors');
		} else {
			bosconfilemanagerInvalidate($path);
			bosconfilemanagerLog($user, 'delete', $rel);
			setEventMessages($langs->trans('BcfmDeleted', bosconfilemanagerTransParam($name)), null, 'mesgs');
		}
	}
	header('Location: '.$backurl);
	exit;
}


/*
 * View
 */

$form = new Form($db);
$showhidden = (bool) getDolGlobalInt('BOSCONFILEMANAGER_SHOW_HIDDEN');
$canwrite = bosconfilemanagerCan($user, 'write');
$candelete = bosconfilemanagerCan($user, 'delete');

$morejs = array('/bosconfilemanager/js/bosconfilemanager.js');
$morecss = array('/bosconfilemanager/css/bosconfilemanager.css');
llxHeader('', $langs->trans('BosConfilemanagerMenu').($dir !== '' ? ' - /'.$dir : ''), '', '', 0, 0, $morejs, $morecss, '', 'mod-bosconfilemanager page-explorer');

print load_fiche_titre($langs->trans('BosConfilemanagerMenu'), '', 'fa-folder-open');

// Confirmation dialogs (plain forms, posted with the CSRF token)
$formconfirm = '';
$pageurl = bosconfilemanagerUrl('explorer.php', array('dir' => $dir, 'name' => $name, 'sortfield' => $sortfield, 'sortorder' => $sortorder));
if ($action === 'mkdir' && $canwrite) {
	$formquestion = array(array('type' => 'text', 'name' => 'newname', 'label' => $langs->trans('BcfmNewDirName'), 'value' => '', 'morecss' => 'minwidth300'));
	$formconfirm = $form->formconfirm($pageurl, $langs->trans('BcfmNewDir'), $langs->trans('BcfmNewDirIn', '/'.$dir), 'confirm_mkdir', $formquestion, 'yes', 0, 0, 500, 0, $langs->trans('Create'), $langs->trans('Cancel'));
} elseif ($action === 'mkfile' && $canwrite) {
	$formquestion = array(array('type' => 'text', 'name' => 'newname', 'label' => $langs->trans('BcfmNewFileName'), 'value' => '', 'morecss' => 'minwidth300'));
	$formconfirm = $form->formconfirm($pageurl, $langs->trans('BcfmNewFile'), $langs->trans('BcfmNewFileIn', '/'.$dir), 'confirm_mkfile', $formquestion, 'yes', 0, 0, 500, 0, $langs->trans('Create'), $langs->trans('Cancel'));
} elseif ($action === 'rename' && $canwrite && bosconfilemanagerValidName($name)) {
	$formquestion = array(array('type' => 'text', 'name' => 'newname', 'label' => $langs->trans('BcfmNewName'), 'value' => $name, 'morecss' => 'minwidth300'));
	$formconfirm = $form->formconfirm($pageurl, $langs->trans('Rename'), $langs->trans('BcfmConfirmRename', bosconfilemanagerTransParam($name)), 'confirm_rename', $formquestion, 'yes', 0, 0, 500, 0, $langs->trans('Rename'), $langs->trans('Cancel'));
} elseif ($action === 'delete' && $candelete && bosconfilemanagerValidName($name)) {
	$path = bosconfilemanagerRoot().($dir === '' ? '' : '/'.$dir).'/'.$name;
	$question = (!is_link($path) && is_dir($path)) ? $langs->trans('BcfmConfirmDeleteDir', bosconfilemanagerTransParam($name)) : $langs->trans('BcfmConfirmDeleteFile', bosconfilemanagerTransParam($name));
	$formconfirm = $form->formconfirm($pageurl, $langs->trans('Delete'), $question, 'confirm_delete', '', 'no', 0, 0, 500, 0, $langs->trans('Delete'), $langs->trans('Cancel'));
}
print $formconfirm;

$entries = bosconfilemanagerListDir($current['abs'], $showhidden, $sortfield, $sortorder);

// Toolbar
print '<div class="bcfm-toolbar">';
print bosconfilemanagerBreadcrumb($dir);
print '<div class="bcfm-buttons">';
if ($dir !== '') {
	print dolGetButtonAction('', img_picto('', 'fa-level-up-alt', 'class="pictofixedwidth"').$langs->trans('BcfmUp'), 'default', bosconfilemanagerUrl('explorer.php', array('dir' => bosconfilemanagerParent($dir))), '', 1);
}
if ($canwrite) {
	print dolGetButtonAction('', img_picto('', 'fa-folder-plus', 'class="pictofixedwidth"').$langs->trans('BcfmNewDir'), 'default', bosconfilemanagerUrl('explorer.php', array('dir' => $dir, 'action' => 'mkdir', 'token' => newToken())), '', 1);
	print dolGetButtonAction('', img_picto('', 'fa-file-medical', 'class="pictofixedwidth"').$langs->trans('BcfmNewFile'), 'default', bosconfilemanagerUrl('explorer.php', array('dir' => $dir, 'action' => 'mkfile', 'token' => newToken())), '', 1);
}
print dolGetButtonAction('', img_picto('', 'fa-file-archive', 'class="pictofixedwidth"').$langs->trans('BcfmDownloadZip'), 'default', bosconfilemanagerUrl('download.php', array('file' => $dir, 'zip' => 1)), '', class_exists('ZipArchive'));
print '</div>';
print '</div>';

print '<div class="bcfm-layout">';

// Directory tree: the path to the current directory is rendered expanded, the rest loads on demand
print '<div class="bcfm-tree" data-ajaxurl="'.dol_escape_htmltag(bosconfilemanagerUrl('ajax/tree.php')).'" data-explorerurl="'.dol_escape_htmltag(bosconfilemanagerUrl('explorer.php')).'">';
print '<ul class="bcfm-treelist">';
print '<li class="bcfm-node bcfm-open" data-rel=""><span class="bcfm-toggle"></span><a href="'.bosconfilemanagerUrl('explorer.php').'" class="'.($dir === '' ? 'bcfm-current' : '').'">'.img_picto('', 'fa-home', 'class="pictofixedwidth"').dol_escape_htmltag($langs->trans('BcfmRoot')).'</a>';
print bosconfilemanagerTreeLevel('', $dir, $showhidden);
print '</li></ul>';
print '</div>';

// File list
print '<div class="bcfm-main">';

if ($canwrite) {
	$maxsize = min(bosconfilemanagerIniBytes(ini_get('upload_max_filesize')), bosconfilemanagerIniBytes(ini_get('post_max_size')));
	print '<form class="bcfm-upload" method="POST" enctype="multipart/form-data" action="'.dol_escape_htmltag(bosconfilemanagerUrl('explorer.php', array('dir' => $dir))).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="upload">';
	print '<div class="bcfm-dropzone">';
	print img_picto('', 'fa-cloud-upload-alt', 'class="pictofixedwidth"');
	print '<input type="file" name="userfile[]" multiple class="bcfm-fileinput"> ';
	print '<label class="nowraponall"><input type="checkbox" name="overwrite" value="1"> '.$langs->trans('BcfmOverwrite').'</label> ';
	print '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Upload')).'">';
	print ' <span class="opacitymedium small">'.$langs->trans('BcfmDropHint').($maxsize > 0 ? ' - '.$langs->trans('BcfmMaxSize', dol_print_size($maxsize, 1, 1)) : '').'</span>';
	print '</div>';
	print '</form>';
}

if (!is_writable($current['abs'])) {
	print info_admin($langs->trans('BcfmDirNotWritable'), 0, 0, 'warning');
}

print '<div class="bcfm-filter"><input type="search" class="bcfm-filterinput minwidth200" placeholder="'.dol_escape_htmltag($langs->trans('BcfmFilter')).'"></div>';

$param = '&dir='.urlencode($dir);
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent bcfm-list">';
print '<tr class="liste_titre">';
print_liste_field_titre('Name', $_SERVER['PHP_SELF'], 'name', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Size', $_SERVER['PHP_SELF'], 'size', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('DateModification', $_SERVER['PHP_SELF'], 'date', '', $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre('BcfmPermissions', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre('', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'right ');
print '</tr>';

if ($dir !== '') {
	print '<tr class="oddeven bcfm-uprow">';
	print '<td colspan="5"><a href="'.bosconfilemanagerUrl('explorer.php', array('dir' => bosconfilemanagerParent($dir), 'sortfield' => $sortfield, 'sortorder' => $sortorder)).'">'.img_picto('', 'fa-level-up-alt', 'class="pictofixedwidth"').'..</a></td>';
	print '</tr>';
}

$nbdirs = 0;
$nbfiles = 0;
$totalsize = 0;
if ($entries === false) {
	print '<tr class="oddeven"><td colspan="5"><span class="error">'.$langs->trans('BcfmErrorRead', bosconfilemanagerTransParam('/'.$dir)).'</span></td></tr>';
} elseif (empty($entries)) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('BcfmEmptyDir').'</span></td></tr>';
} else {
	foreach ($entries as $e) {
		$rel = ($dir === '' ? '' : $dir.'/').$e['name'];
		$common = array('dir' => $dir, 'name' => $e['name'], 'sortfield' => $sortfield, 'sortorder' => $sortorder);
		print '<tr class="oddeven bcfm-row" data-name="'.dol_escape_htmltag(strtolower($e['name'])).'">';

		// Name
		print '<td class="tdoverflowmax500">';
		if ($e['isdir']) {
			$nbdirs++;
			print '<a href="'.bosconfilemanagerUrl('explorer.php', array('dir' => $rel, 'sortfield' => $sortfield, 'sortorder' => $sortorder)).'" class="bcfm-name">';
			print img_picto('', $e['islink'] ? 'fa-folder-minus' : 'fa-folder', 'class="pictofixedwidth bcfm-diricon"');
		} else {
			$nbfiles++;
			$totalsize += $e['size'];
			print '<a href="'.bosconfilemanagerUrl('edit.php', array('file' => $rel)).'" class="bcfm-name">';
			print img_mime($e['name'], '', 'pictofixedwidth');
		}
		print dol_escape_htmltag($e['name']).'</a>';
		if ($e['islink']) {
			print ' <span class="opacitymedium small" title="'.dol_escape_htmltag((string) @readlink($current['abs'].'/'.$e['name'])).'">'.img_picto('', 'fa-link').'</span>';
		}
		print '</td>';

		print '<td class="right nowraponall">'.($e['isdir'] ? '' : dol_print_size($e['size'], 1, 1)).'</td>';
		print '<td class="center nowraponall">'.dol_print_date($e['mtime'], 'dayhour', 'tzuser').'</td>';
		print '<td class="center nowraponall"><span class="bcfm-perms'.($e['writable'] ? '' : ' opacitymedium').'" title="'.dol_escape_htmltag($e['writable'] ? $langs->trans('BcfmWritable') : $langs->trans('BcfmReadOnly')).'">'.bosconfilemanagerTransParam($e['perms']).'</span></td>';

		// Actions
		print '<td class="right nowraponall bcfm-actions">';
		if (!$e['isdir']) {
			print '<a class="paddingleft" href="'.bosconfilemanagerUrl('edit.php', array('file' => $rel)).'" title="'.dol_escape_htmltag($langs->trans('BcfmView')).'">'.img_picto('', 'fa-eye').'</a>';
			if ($canwrite) {
				print '<a class="paddingleft" href="'.bosconfilemanagerUrl('edit.php', array('file' => $rel, 'mode' => 'edit')).'" title="'.dol_escape_htmltag($langs->trans('Modify')).'">'.img_picto('', 'fa-pen').'</a>';
			}
			print '<a class="paddingleft" href="'.bosconfilemanagerUrl('download.php', array('file' => $rel)).'" title="'.dol_escape_htmltag($langs->trans('Download')).'">'.img_picto('', 'fa-download').'</a>';
		} elseif (class_exists('ZipArchive')) {
			print '<a class="paddingleft" href="'.bosconfilemanagerUrl('download.php', array('file' => $rel, 'zip' => 1)).'" title="'.dol_escape_htmltag($langs->trans('BcfmDownloadZip')).'">'.img_picto('', 'fa-file-archive').'</a>';
		}
		if ($canwrite) {
			print '<a class="paddingleft" href="'.bosconfilemanagerUrl('explorer.php', array_merge($common, array('action' => 'rename', 'token' => newToken()))).'" title="'.dol_escape_htmltag($langs->trans('Rename')).'">'.img_picto('', 'fa-i-cursor').'</a>';
		}
		if ($candelete) {
			print '<a class="paddingleft" href="'.bosconfilemanagerUrl('explorer.php', array_merge($common, array('action' => 'delete', 'token' => newToken()))).'" title="'.dol_escape_htmltag($langs->trans('Delete')).'">'.img_picto('', 'fa-trash', 'class="bcfm-delete"').'</a>';
		}
		print '</td>';
		print '</tr>';
	}
}
print '</table>';
print '</div>';

print '<div class="bcfm-footer opacitymedium small">';
print $langs->trans('BcfmSummary', $nbdirs, $nbfiles, dol_print_size($totalsize, 1, 1));
print ' - '.dol_escape_htmltag($current['abs']);
print '</div>';

print '</div>'; // bcfm-main
print '</div>'; // bcfm-layout

llxFooter();
$db->close();


/**
 * Render the subdirectories of $rel as a nested list. Directories on the way to $currentdir are expanded.
 *
 * @param  string $rel        Directory relative to the root
 * @param  string $currentdir Current directory relative to the root
 * @param  bool   $showhidden Include hidden directories
 * @return string HTML
 */
function bosconfilemanagerTreeLevel($rel, $currentdir, $showhidden)
{
	$resolved = bosconfilemanagerResolve($rel);
	if ($resolved === false) {
		return '';
	}
	$entries = bosconfilemanagerListDir($resolved['abs'], $showhidden);
	if (empty($entries)) {
		return '';
	}
	$out = '<ul class="bcfm-treelist">';
	foreach ($entries as $e) {
		if (!$e['isdir']) {
			continue;
		}
		$childrel = ($rel === '' ? '' : $rel.'/').$e['name'];
		$onpath = ($currentdir === $childrel || strpos($currentdir.'/', $childrel.'/') === 0);
		$hassub = bosconfilemanagerHasSubdirs($resolved['abs'].'/'.$e['name'], $showhidden);
		$out .= '<li class="bcfm-node'.($onpath && $hassub ? ' bcfm-open bcfm-loaded' : '').($hassub ? '' : ' bcfm-leaf').'" data-rel="'.dol_escape_htmltag($childrel).'">';
		$out .= '<span class="bcfm-toggle"></span>';
		$out .= '<a href="'.bosconfilemanagerUrl('explorer.php', array('dir' => $childrel)).'" class="'.($currentdir === $childrel ? 'bcfm-current' : '').'">'.img_picto('', 'fa-folder', 'class="pictofixedwidth bcfm-diricon"').dol_escape_htmltag($e['name']).'</a>';
		if ($onpath && $hassub) {
			$out .= bosconfilemanagerTreeLevel($childrel, $currentdir, $showhidden);
		}
		$out .= '</li>';
	}
	$out .= '</ul>';
	return $out;
}

/**
 * Convert a php.ini size value (e.g. "8M") to bytes.
 *
 * @param  string|false $val Value
 * @return int
 */
function bosconfilemanagerIniBytes($val)
{
	$val = trim((string) $val);
	if ($val === '') {
		return 0;
	}
	$num = (int) $val;
	switch (strtolower(substr($val, -1))) {
		case 'g':
			$num *= 1024;
			// no break
		case 'm':
			$num *= 1024;
			// no break
		case 'k':
			$num *= 1024;
	}
	return $num;
}
