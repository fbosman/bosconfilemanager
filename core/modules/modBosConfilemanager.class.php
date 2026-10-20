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
 * \defgroup   bosconfilemanager     Module BosConfilemanager
 * \brief      File explorer for the Dolibarr root: browse, view, edit, upload and download files.
 *
 * \file       htdocs/custom/bosconfilemanager/core/modules/modBosConfilemanager.class.php
 * \ingroup    bosconfilemanager
 * \brief      Description and activation file for module BosConfilemanager
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module BosConfilemanager
 */
class modBosConfilemanager extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		$this->numero = 450170;
		$this->rights_class = 'bosconfilemanager';
		$this->family = "technic";
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "BosConfilemanagerDescription";
		$this->descriptionlong = "BosConfilemanagerDescriptionLong";
		$this->editor_name = 'Boscon';
		$this->editor_url = '';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-folder-open';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
		);

		// Data directory for backups of overwritten, restored and deleted files
		$this->dirs = array("/bosconfilemanager/backup");

		$this->config_page_url = array("setup.php@bosconfilemanager");

		$this->hidden = getDolGlobalInt('MODULE_BOSCONFILEMANAGER_DISABLED');
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array("bosconfilemanager@bosconfilemanager");

		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(18, 0);
		$this->need_javascript_ajax = 1;

		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants: array(name, type, value, note, visible, entity('current' or 'allentities'), deleteonunactive)
		$this->const = array(
			1 => array('BOSCONFILEMANAGER_BACKUP_ON_SAVE', 'chaine', '1', 'Backup a file before it is overwritten or deleted', 0, 'current', 0),
			2 => array('BOSCONFILEMANAGER_MAX_EDIT_SIZE', 'chaine', '2048', 'Max size (KB) of a file opened in the editor', 0, 'current', 0),
			3 => array('BOSCONFILEMANAGER_SHOW_HIDDEN', 'chaine', '1', 'Show files and directories starting with a dot', 0, 'current', 0),
			4 => array('BOSCONFILEMANAGER_PHP_SYNTAX_CHECK', 'chaine', '1', 'Refuse to save PHP files with a syntax error', 0, 'current', 0),
			5 => array('BOSCONFILEMANAGER_EDITOR_THEME', 'chaine', 'chrome', 'ACE editor theme', 0, 'current', 0),
		);

		if (!isModEnabled("bosconfilemanager")) {
			$conf->bosconfilemanager = new stdClass();
			$conf->bosconfilemanager->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();

		// Permissions (only effective for admin users, see bosconfilemanagerCan())
		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", 1);
		$this->rights[$r][1] = 'Browse, view and download files of the Dolibarr installation';
		$this->rights[$r][4] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", 2);
		$this->rights[$r][1] = 'Upload, edit, create and rename files and directories';
		$this->rights[$r][4] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", 3);
		$this->rights[$r][1] = 'Delete files and directories';
		$this->rights[$r][4] = 'delete';
		$r++;

		// Left menu entry under Home > Admin tools
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=home,fk_leftmenu=admintools',
			'type' => 'left',
			'titre' => 'BosConfilemanagerMenu',
			'prefix' => img_picto('', 'fa-folder-open', 'class="pictofixedwidth"'),
			'mainmenu' => 'home',
			'leftmenu' => 'bosconfilemanager',
			'url' => '/bosconfilemanager/explorer.php?mainmenu=home&leftmenu=admintools',
			'langs' => 'bosconfilemanager@bosconfilemanager',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("bosconfilemanager") && preg_match(\'/^(admintools|all)/\', $leftmenu)',
			'perms' => '$user->admin && $user->hasRight("bosconfilemanager", "read")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Function called when module is enabled.
	 * The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 * It also creates data directories
	 *
	 * @param  string $options Options when enabling module ('', 'noboxes')
	 * @return int<-1,1>       1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$this->remove($options);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 * Remove from database constants, boxes and permissions from Dolibarr database.
	 * Data directories are not deleted
	 *
	 * @param  string $options Options when enabling module ('', 'noboxes')
	 * @return int<-1,1>       1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
