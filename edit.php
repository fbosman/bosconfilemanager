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
 * \file    bosconfilemanager/edit.php
 * \ingroup bosconfilemanager
 * \brief   View a file (syntax highlighted text, image or PDF preview) and edit text files.
 */

// The file content is posted as is: it may contain anything (scripts, SQL), skip the injection scan for that field only
if (!defined('NOSCANPOSTFORINJECTION')) {
	define('NOSCANPOSTFORINJECTION', array('content'));
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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/bosconfilemanager/lib/bosconfilemanager.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "other", "bosconfilemanager@bosconfilemanager"));

bosconfilemanagerCheckAccess($user, 'read');

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$mode = GETPOST('mode', 'aZ09');
$backup = GETPOST('backup', 'alphanohtml');

$target = bosconfilemanagerResolve(GETPOST('file', 'none'));
if ($target === false || !is_file($target['abs'])) {
	setEventMessages($langs->trans('BcfmErrorPathNotFound', bosconfilemanagerTransParam(GETPOST('file', 'none'))), null, 'errors');
	header('Location: '.bosconfilemanagerUrl('explorer.php', array('dir' => bosconfilemanagerParent(bosconfilemanagerCleanRel(GETPOST('file', 'none'))))));
	exit;
}
$abs = $target['abs'];
$rel = $target['rel'];
$filename = basename($rel);
$canwrite = bosconfilemanagerCan($user, 'write');
if ($mode !== 'edit' || !$canwrite) {
	$mode = 'view';
}

$maxsize = getDolGlobalInt('BOSCONFILEMANAGER_MAX_EDIT_SIZE', 2048) * 1024;
$size = (int) filesize($abs);
$istext = bosconfilemanagerIsText($abs);
$editable = $istext && $size <= $maxsize;
$selfurl = bosconfilemanagerUrl('edit.php', array('file' => $rel));

// Content kept when a save is refused, so nothing typed is lost
$postedcontent = null;
$conflict = false;
$syntaxerror = '';


/*
 * Actions
 */

if ($action === 'save' && $canwrite && $editable) {
	bosconfilemanagerCheckToken();

	$content = GETPOST('content', 'none');
	$encoding = GETPOST('encoding', 'aZ09');
	$orighash = GETPOST('orighash', 'alpha');
	$force = GETPOSTINT('force');
	$ignoresyntax = GETPOSTINT('ignoresyntax');

	$current = (string) file_get_contents($abs);

	// Browsers post textarea content with CRLF: keep the line endings of the original file
	if (strpos($current, "\r\n") === false || preg_match("/(?<!\r)\n/", $current)) {
		$content = str_replace("\r\n", "\n", $content);
	}
	if ($encoding === 'ISO88591') {
		$content = mb_convert_encoding($content, 'ISO-8859-1', 'UTF-8');
	}

	if (!$force && $orighash !== '' && $orighash !== md5($current)) {
		$conflict = true;
		$postedcontent = $content;
		setEventMessages($langs->trans('BcfmErrorConflict'), null, 'errors');
	} elseif (getDolGlobalInt('BOSCONFILEMANAGER_PHP_SYNTAX_CHECK') && !$ignoresyntax && preg_match('/\.(php|inc|phtml)$/i', $filename)
		&& ($syntaxerror = bosconfilemanagerPhpSyntaxError($content)) !== '') {
		$postedcontent = $content;
		setEventMessages($langs->trans('BcfmErrorPhpSyntax', bosconfilemanagerTransParam($syntaxerror)), null, 'errors');
	} elseif ($content === $current) {
		setEventMessages($langs->trans('BcfmNoChange'), null, 'warnings');
		header('Location: '.bosconfilemanagerUrl('edit.php', array('file' => $rel, 'mode' => 'edit')));
		exit;
	} elseif (bosconfilemanagerBackup($abs, $rel) === false) {
		$postedcontent = $content;
		setEventMessages($langs->trans('BcfmErrorBackup', bosconfilemanagerTransParam($filename)), null, 'errors');
	} elseif (!bosconfilemanagerWriteFile($abs, $content)) {
		$postedcontent = $content;
		setEventMessages($langs->trans('BcfmErrorWrite', bosconfilemanagerTransParam($rel)), null, 'errors');
	} else {
		bosconfilemanagerLog($user, 'save', $rel, strlen($content).' bytes');
		setEventMessages($langs->trans('BcfmSaved', bosconfilemanagerTransParam($filename)), null, 'mesgs');
		header('Location: '.bosconfilemanagerUrl('edit.php', array('file' => $rel, 'mode' => 'edit')));
		exit;
	}
	$mode = 'edit';
}

if ($action === 'confirm_restore' && $confirm === 'yes' && $canwrite) {
	bosconfilemanagerCheckToken();
	$path = bosconfilemanagerBackupDir().'/'.$rel.'.'.$backup.'.bak';
	if (!preg_match('/^\d{14}$/', $backup) || !is_file($path)) {
		setEventMessages($langs->trans('BcfmErrorPathNotFound', bosconfilemanagerTransParam($backup)), null, 'errors');
	} elseif (bosconfilemanagerBackup($abs, $rel) === false) {
		setEventMessages($langs->trans('BcfmErrorBackup', bosconfilemanagerTransParam($filename)), null, 'errors');
	} elseif (!bosconfilemanagerWriteFile($abs, (string) file_get_contents($path))) {
		setEventMessages($langs->trans('BcfmErrorWrite', bosconfilemanagerTransParam($rel)), null, 'errors');
	} else {
		bosconfilemanagerLog($user, 'restore', $rel, 'backup '.$backup);
		setEventMessages($langs->trans('BcfmRestored', dol_print_date(bosconfilemanagerBackupDate($backup), 'dayhoursec', 'tzuser')), null, 'mesgs');
	}
	header('Location: '.$selfurl);
	exit;
}

if ($action === 'confirm_deletebackup' && $confirm === 'yes' && bosconfilemanagerCan($user, 'delete')) {
	bosconfilemanagerCheckToken();
	$path = bosconfilemanagerBackupDir().'/'.$rel.'.'.$backup.'.bak';
	if (preg_match('/^\d{14}$/', $backup) && is_file($path) && @unlink($path)) {
		setEventMessages($langs->trans('BcfmBackupDeleted'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('BcfmErrorDelete', bosconfilemanagerTransParam($backup)), null, 'errors');
	}
	header('Location: '.$selfurl);
	exit;
}


/*
 * View
 */

$form = new Form($db);
clearstatcache(true, $abs);
$size = (int) filesize($abs);

$morejs = array('/bosconfilemanager/js/bosconfilemanager.js');
$morecss = array('/bosconfilemanager/css/bosconfilemanager.css');
$aceurl = '';
if ($editable && file_exists(DOL_DOCUMENT_ROOT.'/includes/ace/src/ace.js')) {
	$aceurl = DOL_URL_ROOT.'/includes/ace/src';
	array_unshift($morejs, '/includes/ace/src/ace.js');
}
llxHeader('', $filename.' - '.$langs->trans('BosConfilemanagerMenu'), '', '', 0, 0, $morejs, $morecss, '', 'mod-bosconfilemanager page-edit');

print load_fiche_titre(dol_escape_htmltag($filename), '', 'fa-file-code');

if ($action === 'restore' && $canwrite) {
	print $form->formconfirm(bosconfilemanagerUrl('edit.php', array('file' => $rel, 'backup' => $backup)), $langs->trans('BcfmRestore'), $langs->trans('BcfmConfirmRestore', dol_print_date(bosconfilemanagerBackupDate($backup), 'dayhoursec', 'tzuser')), 'confirm_restore', '', 'yes', 0, 0, 500, 0, $langs->trans('BcfmRestore'), $langs->trans('Cancel'));
} elseif ($action === 'deletebackup' && bosconfilemanagerCan($user, 'delete')) {
	print $form->formconfirm(bosconfilemanagerUrl('edit.php', array('file' => $rel, 'backup' => $backup)), $langs->trans('Delete'), $langs->trans('BcfmConfirmDeleteBackup', dol_print_date(bosconfilemanagerBackupDate($backup), 'dayhoursec', 'tzuser')), 'confirm_deletebackup', '', 'no', 0, 0, 500, 0, $langs->trans('Delete'), $langs->trans('Cancel'));
}

// Toolbar
print '<div class="bcfm-toolbar">';
print bosconfilemanagerBreadcrumb($rel);
print '<div class="bcfm-buttons">';
print dolGetButtonAction('', img_picto('', 'fa-folder-open', 'class="pictofixedwidth"').$langs->trans('BcfmBackToFolder'), 'default', bosconfilemanagerUrl('explorer.php', array('dir' => bosconfilemanagerParent($rel))), '', 1);
if ($editable && $canwrite) {
	if ($mode === 'view') {
		print dolGetButtonAction('', img_picto('', 'fa-pen', 'class="pictofixedwidth"').$langs->trans('Modify'), 'default', bosconfilemanagerUrl('edit.php', array('file' => $rel, 'mode' => 'edit')), '', 1);
	} else {
		print dolGetButtonAction('', img_picto('', 'fa-eye', 'class="pictofixedwidth"').$langs->trans('BcfmView'), 'default', $selfurl, '', 1);
	}
}
print dolGetButtonAction('', img_picto('', 'fa-download', 'class="pictofixedwidth"').$langs->trans('Download'), 'default', bosconfilemanagerUrl('download.php', array('file' => $rel)), '', 1);
print '</div>';
print '</div>';

// File info
print '<div class="bcfm-fileinfo opacitymedium small">';
print dol_print_size($size, 1, 1);
print ' - '.$langs->trans('DateModification').': '.dol_print_date(filemtime($abs), 'dayhoursec', 'tzuser');
print ' - '.dol_escape_htmltag(bosconfilemanagerPerms($abs));
print ' - '.(is_writable($abs) ? $langs->trans('BcfmWritable') : '<span class="warning">'.$langs->trans('BcfmReadOnly').'</span>');
print '</div>';

$previewtype = bosconfilemanagerPreviewType($filename);
$rawurl = bosconfilemanagerUrl('download.php', array('file' => $rel, 'inline' => 1));

if ($previewtype === 'image') {
	print '<div class="bcfm-preview"><img src="'.dol_escape_htmltag($rawurl).'" alt="'.dol_escape_htmltag($filename).'"></div>';
} elseif ($previewtype === 'pdf') {
	print '<div class="bcfm-preview"><iframe src="'.dol_escape_htmltag($rawurl).'" class="bcfm-pdf"></iframe></div>';
}

if ($editable) {
	$content = ($postedcontent !== null) ? $postedcontent : (string) file_get_contents($abs);
	$encoding = 'UTF8';
	if (!mb_check_encoding($content, 'UTF-8')) {
		$encoding = 'ISO88591';
		$content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
	}
	$orighash = GETPOST('orighash', 'alpha');
	if ($postedcontent === null || $conflict || $orighash === '') {
		// After a conflict the user saves against the version on disk now (with "overwrite" ticked)
		$orighash = md5((string) file_get_contents($abs));
	}
	$readonly = ($mode !== 'edit');

	print '<form method="POST" action="'.dol_escape_htmltag(bosconfilemanagerUrl('edit.php', array('file' => $rel))).'" class="bcfm-editform">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="save">';
	print '<input type="hidden" name="mode" value="edit">';
	print '<input type="hidden" name="orighash" value="'.dol_escape_htmltag($orighash).'">';
	print '<input type="hidden" name="encoding" value="'.$encoding.'">';

	if (!$readonly) {
		print '<div class="bcfm-savebar">';
		print '<input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"> ';
		print '<a class="button button-cancel" href="'.dol_escape_htmltag($selfurl).'">'.$langs->trans('Cancel').'</a> ';
		print '<span class="opacitymedium small">'.$langs->trans('BcfmSaveHint').'</span>';
		if ($conflict) {
			print ' <label class="warning"><input type="checkbox" name="force" value="1"> '.$langs->trans('BcfmForceSave').'</label>';
		}
		if ($syntaxerror !== '') {
			print ' <label class="warning"><input type="checkbox" name="ignoresyntax" value="1"> '.$langs->trans('BcfmIgnoreSyntax').'</label>';
		}
		if ($encoding === 'ISO88591') {
			print ' <span class="warning small">'.$langs->trans('BcfmEncodingLatin1').'</span>';
		}
		print '</div>';
	}

	print '<textarea name="content" id="bcfm-content" class="bcfm-textarea" spellcheck="false" wrap="off"'.($readonly ? ' readonly' : '').'>'.htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</textarea>';
	print '<div id="bcfm-editor" class="bcfm-editor hideobject"'
		.' data-acebase="'.dol_escape_htmltag($aceurl).'"'
		.' data-mode="'.dol_escape_htmltag(bosconfilemanagerAceMode($filename)).'"'
		.' data-theme="'.dol_escape_htmltag(getDolGlobalString('BOSCONFILEMANAGER_EDITOR_THEME', 'chrome')).'"'
		.' data-readonly="'.($readonly ? '1' : '0').'"></div>';
	print '</form>';
} elseif ($istext) {
	print info_admin($langs->trans('BcfmTooLargeToEdit', dol_print_size($maxsize, 1, 1)), 0, 0, 'warning');
} elseif ($previewtype === '') {
	print info_admin($langs->trans('BcfmBinaryFile'), 0, 0, 'info');
}

// Backups of this file
$backups = bosconfilemanagerListBackups($rel);
if (!empty($backups)) {
	print '<br>';
	print load_fiche_titre($langs->trans('BcfmBackups'), '', 'fa-history');
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>'.$langs->trans('Date').'</th><th class="right">'.$langs->trans('Size').'</th><th></th></tr>';
	foreach ($backups as $b) {
		$id = substr($b['name'], -18, 14);
		print '<tr class="oddeven">';
		print '<td>'.dol_print_date($b['date'], 'dayhoursec', 'tzuser').'</td>';
		print '<td class="right">'.dol_print_size($b['size'], 1, 1).'</td>';
		print '<td class="right nowraponall">';
		print '<a class="paddingleft" href="'.bosconfilemanagerUrl('download.php', array('file' => $rel, 'backup' => $id)).'" title="'.dol_escape_htmltag($langs->trans('Download')).'">'.img_picto('', 'fa-download').'</a>';
		if ($canwrite) {
			print '<a class="paddingleft" href="'.bosconfilemanagerUrl('edit.php', array('file' => $rel, 'backup' => $id, 'action' => 'restore', 'token' => newToken())).'" title="'.dol_escape_htmltag($langs->trans('BcfmRestore')).'">'.img_picto('', 'fa-undo').'</a>';
		}
		if (bosconfilemanagerCan($user, 'delete')) {
			print '<a class="paddingleft" href="'.bosconfilemanagerUrl('edit.php', array('file' => $rel, 'backup' => $id, 'action' => 'deletebackup', 'token' => newToken())).'" title="'.dol_escape_htmltag($langs->trans('Delete')).'">'.img_picto('', 'fa-trash').'</a>';
		}
		print '</td>';
		print '</tr>';
	}
	print '</table>';
	print '</div>';
}

llxFooter();
$db->close();

