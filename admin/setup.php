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
 * \file    bosconfilemanager/admin/setup.php
 * \ingroup bosconfilemanager
 * \brief   BosConfilemanager setup page.
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

require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT."/core/lib/files.lib.php";
require_once DOL_DOCUMENT_ROOT."/core/class/html.form.class.php";
dol_include_once('/bosconfilemanager/lib/bosconfilemanager.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "bosconfilemanager@bosconfilemanager"));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

$yesnosettings = array('BOSCONFILEMANAGER_BACKUP_ON_SAVE', 'BOSCONFILEMANAGER_SHOW_HIDDEN', 'BOSCONFILEMANAGER_PHP_SYNTAX_CHECK');

// ACE themes shipped with Dolibarr
$themes = array();
foreach ((array) glob(DOL_DOCUMENT_ROOT.'/includes/ace/src/theme-*.js') as $file) {
	$theme = substr(basename((string) $file, '.js'), 6);
	$themes[$theme] = ucfirst(str_replace('_', ' ', $theme));
}


/*
 * Actions
 */

if ($action == 'update') {
	bosconfilemanagerCheckToken();
	$error = 0;
	foreach ($yesnosettings as $constname) {
		if (dolibarr_set_const($db, $constname, GETPOSTINT($constname) ? '1' : '0', 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}
	$maxsize = GETPOSTINT('BOSCONFILEMANAGER_MAX_EDIT_SIZE');
	if ($maxsize <= 0) {
		setEventMessages($langs->trans("ErrorBadValueForParameter", (string) $maxsize, $langs->transnoentitiesnoconv('BOSCONFILEMANAGER_MAX_EDIT_SIZE')), null, 'errors');
		$error++;
	} elseif (dolibarr_set_const($db, 'BOSCONFILEMANAGER_MAX_EDIT_SIZE', (string) $maxsize, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}
	$theme = GETPOST('BOSCONFILEMANAGER_EDITOR_THEME', 'aZ09');
	if (isset($themes[$theme]) && dolibarr_set_const($db, 'BOSCONFILEMANAGER_EDITOR_THEME', $theme, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}
	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

if ($action == 'confirm_purgebackups' && $confirm == 'yes') {
	bosconfilemanagerCheckToken();
	$dir = bosconfilemanagerBackupDir();
	if (is_dir($dir) && !bosconfilemanagerDeleteTree($dir)) {
		setEventMessages($langs->trans("BcfmErrorDelete", bosconfilemanagerTransParam($dir)), null, 'errors');
	} else {
		dol_mkdir($dir);
		bosconfilemanagerLog($user, 'purgebackups', '');
		setEventMessages($langs->trans("BcfmBackupsPurged"), null, 'mesgs');
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}


/*
 * View
 */

$form = new Form($db);

llxHeader('', $langs->trans("BosConfilemanagerSetup"), '', '', 0, 0, '', '', '', 'mod-bosconfilemanager page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("BosConfilemanagerSetup"), $linkback, 'title_setup');

$head = bosconfilemanagerAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans("BosConfilemanagerName"), -1, 'fa-folder-open');

if ($action == 'purgebackups') {
	print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans("BcfmPurgeBackups"), $langs->trans("BcfmConfirmPurgeBackups"), 'confirm_purgebackups', '', 'no', 0, 0, 500, 0, $langs->trans("Delete"), $langs->trans("Cancel"));
}

print '<span class="opacitymedium">'.$langs->trans("BosConfilemanagerSetupPage", bosconfilemanagerTransParam(bosconfilemanagerRoot())).'</span><br><br>';

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';

foreach ($yesnosettings as $constname) {
	print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans($constname), $langs->trans($constname.'Tooltip')).'</td>';
	print '<td>'.$form->selectyesno($constname, getDolGlobalInt($constname) ? 1 : 0, 1).'</td></tr>';
}

print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('BOSCONFILEMANAGER_MAX_EDIT_SIZE'), $langs->trans('BOSCONFILEMANAGER_MAX_EDIT_SIZETooltip')).'</td>';
print '<td><input type="number" min="1" name="BOSCONFILEMANAGER_MAX_EDIT_SIZE" class="width75" value="'.getDolGlobalInt('BOSCONFILEMANAGER_MAX_EDIT_SIZE', 2048).'"> KB</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('BOSCONFILEMANAGER_EDITOR_THEME').'</td>';
print '<td>'.$form->selectarray('BOSCONFILEMANAGER_EDITOR_THEME', $themes, getDolGlobalString('BOSCONFILEMANAGER_EDITOR_THEME', 'chrome'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1).'</td></tr>';

print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
print '</form>';

// Backups
$backupdir = bosconfilemanagerBackupDir();
$nb = 0;
$total = 0;
if (is_dir($backupdir)) {
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backupdir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $file) {
		if ($file->isFile()) {
			$nb++;
			$total += $file->getSize();
		}
	}
}
print '<br>';
print load_fiche_titre($langs->trans("BcfmBackups"), '', 'fa-history');
print '<table class="noborder centpercent">';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("Directory").'</td><td>'.dol_escape_htmltag($backupdir).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("BcfmBackupCount").'</td><td>'.$nb.' ('.dol_print_size($total, 1, 1).')</td></tr>';
print '</table>';
if ($nb) {
	print '<div class="tabsAction">';
	print dolGetButtonAction('', $langs->trans("BcfmPurgeBackups"), 'delete', $_SERVER["PHP_SELF"].'?action=purgebackups&token='.newToken(), '', 1);
	print '</div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
