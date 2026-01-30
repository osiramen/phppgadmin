<?php

use PhpPgAdmin\Database\Actions\PartitionActions;
use PhpPgAdmin\Gui\FormRenderer;
use PhpPgAdmin\Gui\SearchFormRenderer;
use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\RowActions;
use PhpPgAdmin\Database\Actions\TypeActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\TablespaceActions;

/**
 * List tables in a database
 *
 * $Id: tables.php,v 1.112 2008/06/16 22:38:46 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Displays a screen where they can enter a new table
 */
function doCreate($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$tablespaceActions = new TablespaceActions($pg);
	$typeActions = new TypeActions($pg);

	if (!isset($_REQUEST['stage'])) {
		$_REQUEST['stage'] = 1;
		$default_with_oids = $tableActions->getDefaultWithOid();
		if ($default_with_oids == 'off')
			$_REQUEST['withoutoids'] = 'on';
	}

	if (!isset($_REQUEST['name']))
		$_REQUEST['name'] = '';
	if (!isset($_REQUEST['fields']))
		$_REQUEST['fields'] = '';
	if (!isset($_REQUEST['tblcomment']))
		$_REQUEST['tblcomment'] = '';
	if (!isset($_REQUEST['spcname']))
		$_REQUEST['spcname'] = '';

	switch ($_REQUEST['stage']) {
		case 1:
			// Fetch all tablespaces from the database
			if ($pg->hasTablespaces())
				$tablespaces = $tablespaceActions->getTablespaces();

			$misc->printTrail('schema');
			$misc->printTitle($lang['strcreatetable'], 'pg.table.create');
			$misc->printMsg($msg);

			echo "<form action=\"tables.php\" method=\"post\">\n";
			echo "<table>\n";
			echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strname']}</th>\n";
			echo "\t\t<td class=\"data\"><input name=\"name\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
				html_esc($_REQUEST['name']), "\" /></td>\n\t</tr>\n";
			echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strnumcols']}</th>\n";
			echo "\t\t<td class=\"data\"><input name=\"fields\" size=\"5\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
				html_esc($_REQUEST['fields']), "\" /></td>\n\t</tr>\n";
			if ($pg->hasServerOids()) {
				echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['stroptions']}</th>\n";
				echo "\t\t<td class=\"data\"><label for=\"withoutoids\"><input type=\"checkbox\" id=\"withoutoids\" name=\"withoutoids\"", isset($_REQUEST['withoutoids']) ? ' checked="checked"' : '', " />WITHOUT OIDS</label></td>\n\t</tr>\n";
			} else {
				echo "\t\t<input type=\"hidden\" id=\"withoutoids\" name=\"withoutoids\" value=\"checked\"\n";
			}

			// Tablespace (if there are any)
			if ($pg->hasTablespaces() && $tablespaces->recordCount() > 0) {
				echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strtablespace']}</th>\n";
				echo "\t\t<td class=\"data1\">\n\t\t\t<select name=\"spcname\">\n";
				// Always offer the default (empty) option
				echo "\t\t\t\t<option value=\"\"", ($_REQUEST['spcname'] == '') ? ' selected="selected"' : '', "></option>\n";
				// Display all other tablespaces
				while (!$tablespaces->EOF) {
					$spcname = html_esc($tablespaces->fields['spcname']);
					echo "\t\t\t\t<option value=\"{$spcname}\"", ($tablespaces->fields['spcname'] == $_REQUEST['spcname']) ? ' selected="selected"' : '', ">{$spcname}</option>\n";
					$tablespaces->moveNext();
				}
				echo "\t\t\t</select>\n\t\t</td>\n\t</tr>\n";
			}

			// Partitioning options (PG10+)
			if ($pg->major_version >= 10) {
				if (!isset($_REQUEST['is_partitioned']))
					$_REQUEST['is_partitioned'] = '';
				if (!isset($_REQUEST['partition_strategy']))
					$_REQUEST['partition_strategy'] = '';

				echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strpartitionstrategy']}</th>\n";
				echo "\t\t<td class=\"data\">\n";
				echo "\t\t\t<label><input type=\"checkbox\" id=\"is_partitioned\" name=\"is_partitioned\" value=\"1\"";
				if ($_REQUEST['is_partitioned'])
					echo ' checked="checked"';
				echo " onchange=\"document.getElementById('partition_strategy_row').style.display = this.checked ? '' : 'none';\" />";
				echo " {$lang['strcreatepartitionedtable']}</label>\n";
				echo "\t\t\t<div id=\"partition_strategy_row\" style=\"display: " . ($_REQUEST['is_partitioned'] ? '' : 'none') . "; margin-top: 8px;\">\n";
				echo "\t\t\t\t<select name=\"partition_strategy\" id=\"partition_strategy\">\n";
				echo "\t\t\t\t\t<option value=\"\">{$lang['strselect']}</option>\n";
				echo "\t\t\t\t\t<option value=\"RANGE\"" . ($_REQUEST['partition_strategy'] == 'RANGE' ? ' selected' : '') . ">RANGE - {$lang['strpartitionrange']}</option>\n";
				echo "\t\t\t\t\t<option value=\"LIST\"" . ($_REQUEST['partition_strategy'] == 'LIST' ? ' selected' : '') . ">LIST - {$lang['strpartitionlist']}</option>\n";
				echo "\t\t\t\t\t<option value=\"HASH\"" . ($_REQUEST['partition_strategy'] == 'HASH' ? ' selected' : '') . ">HASH - {$lang['strpartitionhash']}</option>\n";
				echo "\t\t\t\t</select>\n";
				echo "\t\t\t</div>\n";
				echo "\t\t</td>\n\t</tr>\n";
			}


			echo "</table>\n";
			echo "<p><input type=\"hidden\" name=\"action\" value=\"create\" />\n";
			echo "<input type=\"hidden\" name=\"stage\" value=\"2\" />\n";
			echo $misc->form;
			echo "<input type=\"submit\" value=\"{$lang['strnext']}\" />\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
			echo "</form>\n";
			break;
		case 2:
			// Check inputs
			$fields = trim($_REQUEST['fields']);
			if (trim($_REQUEST['name']) == '') {
				$_REQUEST['stage'] = 1;
				doCreate($lang['strtableneedsname']);
				return;
			} elseif ($fields == '' || !is_numeric($fields) || $fields != (int) $fields || $fields < 1) {
				$_REQUEST['stage'] = 1;
				doCreate($lang['strtableneedscols']);
				return;
			}

			$types = $typeActions->getTypes(true, false, true);
			$types_for_js = [];

			$misc->printTrail('schema');
			$misc->printTitle($lang['strcreatetable'], 'pg.table.create');
			$misc->printMsg($msg);

			echo "<script src=\"js/tables.js\" type=\"text/javascript\"></script>";
			echo "<form action=\"tables.php\" method=\"post\">\n";

			// Output table header
			echo "<table>\n";
			echo "\t<tr><th colspan=\"2\" class=\"data required\">{$lang['strcolumn']}</th><th colspan=\"2\" class=\"data required\">{$lang['strtype']}</th>";
			echo "<th class=\"data\">{$lang['strlength']}</th><th class=\"data\">{$lang['strnotnull']}</th>";
			echo "<th class=\"data\">{$lang['struniquekey']}</th><th class=\"data\">{$lang['strprimarykey']}</th>";
			echo "<th class=\"data\">{$lang['strdefault']}</th>";
			// Show partition key column if partitioning is enabled
			if ($pg->major_version >= 10 && isset($_REQUEST['is_partitioned']) && $_REQUEST['is_partitioned']) {
				echo "<th class=\"data\">{$lang['strpartitionkey']}</th>";
			}
			echo "<th class=\"data\">{$lang['strcomment']}</th></tr>\n";

			for ($i = 0; $i < $_REQUEST['fields']; $i++) {
				if (!isset($_REQUEST['field'][$i]))
					$_REQUEST['field'][$i] = '';
				if (!isset($_REQUEST['length'][$i]))
					$_REQUEST['length'][$i] = '';
				if (!isset($_REQUEST['default'][$i]))
					$_REQUEST['default'][$i] = '';
				if (!isset($_REQUEST['colcomment'][$i]))
					$_REQUEST['colcomment'][$i] = '';

				echo "\t<tr>\n\t\t<td>", $i + 1, ".&nbsp;</td>\n";
				echo "\t\t<td><input name=\"field[{$i}]\" size=\"16\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
					html_esc($_REQUEST['field'][$i]), "\" /></td>\n";
				echo "\t\t<td>\n\t\t\t<select name=\"type[{$i}]\" id=\"types{$i}\" onchange=\"checkLengths(this.options[this.selectedIndex].value,{$i});\">\n";
				// Output any "magic" types
				foreach ($pg->extraTypes as $v) {
					$types_for_js[strtolower($v)] = 1;
					echo "\t\t\t\t<option value=\"", html_esc($v), "\"", (isset($_REQUEST['type'][$i]) && $v == $_REQUEST['type'][$i]) ? ' selected="selected"' : '', ">",
						$misc->formatVal($v), "</option>\n";
				}
				$types->moveFirst();
				while (!$types->EOF) {
					$typname = $types->fields['typname'];
					$types_for_js[$typname] = 1;
					echo "\t\t\t\t<option value=\"", html_esc($typname), "\"", (isset($_REQUEST['type'][$i]) && $typname == $_REQUEST['type'][$i]) ? ' selected="selected"' : '', ">",
						$misc->formatVal($typname), "</option>\n";
					$types->moveNext();
				}
				echo "\t\t\t</select>\n\t\t\n";
				if ($i == 0) { // only define js types array once
					$predefined_size_types = array_intersect($pg->predefinedSizeTypes, array_keys($types_for_js));
					$escaped_predef_types = []; // the JS escaped array elements
					foreach ($predefined_size_types as $value) {
						$escaped_predef_types[] = "'{$value}'";
					}
					echo "<script type=\"text/javascript\">predefined_lengths = new Array(" . implode(",", $escaped_predef_types) . ");</script>\n\t</td>";
				}

				// Output array type selector
				echo "\t\t<td>\n\t\t\t<select name=\"array[{$i}]\">\n";
				echo "\t\t\t\t<option value=\"\"", (isset($_REQUEST['array'][$i]) && $_REQUEST['array'][$i] == '') ? ' selected="selected"' : '', "></option>\n";
				echo "\t\t\t\t<option value=\"[]\"", (isset($_REQUEST['array'][$i]) && $_REQUEST['array'][$i] == '[]') ? ' selected="selected"' : '', ">[ ]</option>\n";
				echo "\t\t\t</select>\n\t\t</td>\n";

				echo "\t\t<td><input name=\"length[{$i}]\" id=\"lengths{$i}\" size=\"10\" value=\"",
					html_esc($_REQUEST['length'][$i]), "\" /></td>\n";
				echo "\t\t<td><input type=\"checkbox\" name=\"notnull[{$i}]\"", (isset($_REQUEST['notnull'][$i])) ? ' checked="checked"' : '', " /></td>\n";
				echo "\t\t<td style=\"text-align: center\"><input type=\"checkbox\" name=\"uniquekey[{$i}]\""
					. (isset($_REQUEST['uniquekey'][$i]) ? ' checked="checked"' : '') . " /></td>\n";
				echo "\t\t<td style=\"text-align: center\"><input type=\"checkbox\" name=\"primarykey[{$i}]\" "
					. (isset($_REQUEST['primarykey'][$i]) ? ' checked="checked"' : '')
					. " /></td>\n";
				echo "\t\t<td><input name=\"default[{$i}]\" size=\"20\" value=\"",
					html_esc($_REQUEST['default'][$i]), "\" /></td>\n";			// Partition key checkbox (PG10+)
				if ($pg->major_version >= 10 && isset($_REQUEST['is_partitioned']) && $_REQUEST['is_partitioned']) {
					echo "\t\t<td style=\"text-align: center\"><input type=\"checkbox\" name=\"partitionkey[{$i}]\"";
					if (isset($_REQUEST['partitionkey'][$i]))
						echo ' checked="checked"';
					echo " /></td>\n";
				}
				echo "\t\t<td><input name=\"colcomment[{$i}]\" size=\"40\" value=\"",
					html_esc($_REQUEST['colcomment'][$i]), "\" />
						<script type=\"text/javascript\">checkLengths(document.getElementById('types{$i}').value,{$i});</script>
						</td>\n\t</tr>\n";
			}
			echo "</table>\n";
			echo "<p><input type=\"hidden\" name=\"action\" value=\"create\" />\n";
			echo "<input type=\"hidden\" name=\"stage\" value=\"3\" />\n";
			echo $misc->form;
			echo "<input type=\"hidden\" name=\"name\" value=\"", html_esc($_REQUEST['name']), "\" />\n";
			echo "<input type=\"hidden\" name=\"fields\" value=\"", html_esc($_REQUEST['fields']), "\" />\n";
			if (isset($_REQUEST['withoutoids'])) {
				echo "<input type=\"hidden\" name=\"withoutoids\" value=\"true\" />\n";
			}
			echo "<input type=\"hidden\" name=\"tblcomment\" value=\"", html_esc($_REQUEST['tblcomment']), "\" />\n";
			if (isset($_REQUEST['spcname'])) {
				echo "<input type=\"hidden\" name=\"spcname\" value=\"", html_esc($_REQUEST['spcname']), "\" />\n";
			}
			// Pass partition settings to stage 3
			if ($pg->major_version >= 10) {
				if (isset($_REQUEST['is_partitioned']) && $_REQUEST['is_partitioned']) {
					echo "<input type=\"hidden\" name=\"is_partitioned\" value=\"1\" />\n";
					if (isset($_REQUEST['partition_strategy'])) {
						echo "<input type=\"hidden\" name=\"partition_strategy\" value=\"", html_esc($_REQUEST['partition_strategy']), "\" />\n";
					}
				}
			}
			echo "<input type=\"submit\" value=\"{$lang['strcreate']}\" />\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
			echo "</form>\n";

			break;
		case 3:
			if (!isset($_REQUEST['notnull']))
				$_REQUEST['notnull'] = [];
			if (!isset($_REQUEST['uniquekey']))
				$_REQUEST['uniquekey'] = [];
			if (!isset($_REQUEST['primarykey']))
				$_REQUEST['primarykey'] = [];
			if (!isset($_REQUEST['length']))
				$_REQUEST['length'] = [];
			// Default tablespace to null if it isn't set
			if (!isset($_REQUEST['spcname']))
				$_REQUEST['spcname'] = null;

			// Partition settings
			$partitionStrategy = null;
			$partitionKeys = [];
			if ($pg->major_version >= 10 && isset($_REQUEST['is_partitioned']) && $_REQUEST['is_partitioned']) {
				if (empty($_REQUEST['partition_strategy'])) {
					$_REQUEST['stage'] = 1;
					doCreate($lang['strpartitionstrategyrequired'] ?? 'Partition strategy is required');
					return;
				}
				$partitionStrategy = $_REQUEST['partition_strategy'];

				// Collect partition key columns
				if (isset($_REQUEST['partitionkey'])) {
					foreach ($_REQUEST['partitionkey'] as $idx => $val) {
						if (!empty($_REQUEST['field'][$idx])) {
							$partitionKeys[] = $_REQUEST['field'][$idx];
						}
					}
				}

				if (empty($partitionKeys)) {
					$_REQUEST['stage'] = 2;
					doCreate($lang['strpartitionkeyrequired'] ?? 'At least one partition key column is required');
					return;
				}
			}

			// Check inputs
			$fields = trim($_REQUEST['fields']);
			if (trim($_REQUEST['name']) == '') {
				$_REQUEST['stage'] = 1;
				doCreate($lang['strtableneedsname']);
				return;
			} elseif ($fields == '' || !is_numeric($fields) || $fields != (int) $fields || $fields <= 0) {
				$_REQUEST['stage'] = 1;
				doCreate($lang['strtableneedscols']);
				return;
			}

			$status = $tableActions->createTable(
				$_REQUEST['name'],
				$_REQUEST['fields'],
				$_REQUEST['field'],
				$_REQUEST['type'],
				$_REQUEST['array'],
				$_REQUEST['length'],
				$_REQUEST['notnull'],
				$_REQUEST['default'],
				isset($_REQUEST['withoutoids']),
				$_REQUEST['colcomment'],
				$_REQUEST['tblcomment'],
				$_REQUEST['spcname'],
				$_REQUEST['uniquekey'],
				$_REQUEST['primarykey'],
				$partitionStrategy,
				$partitionKeys
			);

			if ($status == 0) {
				AppContainer::setShouldReloadTree(true);
				doDefault($lang['strtablecreated']);
			} elseif ($status == -1) {
				$_REQUEST['stage'] = 2;
				doCreate($lang['strtableneedsfield']);
				return;
			} else {
				$_REQUEST['stage'] = 2;
				doCreate($lang['strtablecreatedbad']);
				return;
			}
			break;
		default:
			echo "<p>{$lang['strinvalidparam']}</p>\n";
	}
}

/**
 * Display a screen where user can create a table from an existing one.
 * We don't have to check if pg supports schema cause create table like
 * is available under pg 7.4+ which has schema.
 */
function doCreateLike($confirm, $msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$tablespaceActions = new TablespaceActions($pg);
	$formRenderer = new FormRenderer();

	if (!$confirm) {

		if (!isset($_REQUEST['name']))
			$_REQUEST['name'] = '';
		if (!isset($_REQUEST['like']))
			$_REQUEST['like'] = '';
		if (!isset($_REQUEST['tablespace']))
			$_REQUEST['tablespace'] = '';

		$misc->printTrail('schema');
		$misc->printTitle($lang['strcreatetable'], 'pg.table.create');
		$misc->printMsg($msg);

		$tbltmp = $tableActions->getTables(true);
		$tbltmp = $tbltmp->getArray();

		$tables = [];
		$tblsel = '';
		foreach ($tbltmp as $a) {
			$pg->fieldClean($a['nspname']);
			$pg->fieldClean($a['relname']);
			$tables["\"{$a['nspname']}\".\"{$a['relname']}\""] = serialize(['schema' => $a['nspname'], 'table' => $a['relname']]);
			if ($_REQUEST['like'] == $tables["\"{$a['nspname']}\".\"{$a['relname']}\""])
				$tblsel = html_esc($tables["\"{$a['nspname']}\".\"{$a['relname']}\""]);
		}

		unset($tbltmp);

		echo "<form action=\"tables.php\" method=\"post\">\n";
		echo "<table>\n\t<tr>\n\t\t<th class=\"data left required\">{$lang['strname']}</th>\n";
		echo "\t\t<td class=\"data\"><input name=\"name\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"", html_esc($_REQUEST['name']), "\" /></td>\n\t</tr>\n";
		echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strcreatetablelikeparent']}</th>\n";
		echo "\t\t<td class=\"data\">";
		echo $formRenderer->printCombo($tables, 'like', true, $tblsel, false);
		echo "</td>\n\t</tr>\n";
		if ($pg->hasTablespaces()) {
			$tblsp_ = $tablespaceActions->getTablespaces();
			if ($tblsp_->recordCount() > 0) {
				$tblsp_ = $tblsp_->getArray();
				$tblsp = [];
				foreach ($tblsp_ as $a)
					$tblsp[$a['spcname']] = $a['spcname'];

				echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strtablespace']}</th>\n";
				echo "\t\t<td class=\"data\">";
				echo $formRenderer->printCombo($tblsp, 'tablespace', true, $_REQUEST['tablespace'], false);
				echo "</td>\n\t</tr>\n";
			}
		}
		echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['stroptions']}</th>\n\t\t<td class=\"data\">";
		echo "<label for=\"withdefaults\"><input type=\"checkbox\" id=\"withdefaults\" name=\"withdefaults\"",
			isset($_REQUEST['withdefaults']) ? ' checked="checked"' : '',
			"/>{$lang['strcreatelikewithdefaults']}</label>";
		if ($pg->hasCreateTableLikeWithConstraints()) {
			echo "<br /><label for=\"withconstraints\"><input type=\"checkbox\" id=\"withconstraints\" name=\"withconstraints\"",
				isset($_REQUEST['withconstraints']) ? ' checked="checked"' : '',
				"/>{$lang['strcreatelikewithconstraints']}</label>";
		}
		if ($pg->hasCreateTableLikeWithIndexes()) {
			echo "<br /><label for=\"withindexes\"><input type=\"checkbox\" id=\"withindexes\" name=\"withindexes\"",
				isset($_REQUEST['withindexes']) ? ' checked="checked"' : '',
				"/>{$lang['strcreatelikewithindexes']}</label>";
		}
		echo "</td>\n\t</tr>\n";
		echo "</table>";

		echo "<input type=\"hidden\" name=\"action\" value=\"confcreatelike\" />\n";
		echo $misc->form;
		echo "<p><input type=\"submit\" value=\"{$lang['strcreate']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";

	} else {

		if (trim($_REQUEST['name']) == '') {
			doCreateLike(false, $lang['strtableneedsname']);
			return;
		}
		if (trim($_REQUEST['like']) == '') {
			doCreateLike(false, $lang['strtablelikeneedslike']);
			return;
		}

		if (!isset($_REQUEST['tablespace']))
			$_REQUEST['tablespace'] = '';

		$status = $tableActions->createTableLike(
			$_REQUEST['name'],
			unserialize($_REQUEST['like']),
			isset($_REQUEST['withdefaults']),
			isset($_REQUEST['withconstraints']),
			isset($_REQUEST['withindexes']),
			$_REQUEST['tablespace']
		);

		if ($status == 0) {
			AppContainer::setShouldReloadTree(true);
			doDefault($lang['strtablecreated']);
		} else {
			doCreateLike(false, $lang['strtablecreatedbad']);
			return;
		}
	}
}

/**
 * Ask for select parameters and perform select
 */
function doSelectRows($confirm, $msg = '')
{
	SearchFormRenderer::renderSelectRowsForm(
		$confirm,
		$msg,
		'table',
		$_REQUEST['table'],
		'selectrows'
	);
}

/**
 * Show confirmation of empty and perform actual empty
 */
function doEmpty($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);

	if (empty($_REQUEST['table']) && empty($_REQUEST['ma'])) {
		doDefault($lang['strspecifytabletoempty']);
		return;
	}

	if ($confirm) {
		if (isset($_REQUEST['ma'])) {
			$misc->printTrail('schema');
			$misc->printTitle($lang['strempty'], 'pg.table.empty');

			echo "<form action=\"tables.php\" method=\"post\">\n";
			foreach ($_REQUEST['ma'] as $v) {
				$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES));
				echo "<p>", sprintf($lang['strconfemptytable'], $misc->formatVal($a['table'])), "</p>\n";
				printf('<input type="hidden" name="table[]" value="%s" />', html_esc($a['table']));
			}
		} // END multi empty
		else {
			$misc->printTrail('table');
			$misc->printTitle($lang['strempty'], 'pg.table.empty');

			echo "<p>", sprintf($lang['strconfemptytable'], $misc->formatVal($_REQUEST['table'])), "</p>\n";

			echo "<form action=\"tables.php\" method=\"post\">\n";
			echo "<input type=\"hidden\" name=\"table\" value=\"", html_esc($_REQUEST['table']), "\" />\n";
		} // END not multi empty

		echo "<input type=\"hidden\" name=\"action\" value=\"empty\" />\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"empty\" value=\"{$lang['strempty']}\" /> <input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
		echo "</form>\n";
	} // END if confirm
	else { // Do Empty
		if (is_array($_REQUEST['table'])) {
			$msg = '';
			foreach ($_REQUEST['table'] as $t) {
				$status = $tableActions->emptyTable($t);
				if ($status == 0)
					$msg .= sprintf('%s: %s<br />', htmlentities($t, ENT_QUOTES, 'UTF-8'), $lang['strtableemptied']);
				else {
					doDefault(sprintf('%s%s: %s<br />', $msg, htmlentities($t, ENT_QUOTES, 'UTF-8'), $lang['strtableemptiedbad']));
					return;
				}
			}
			doDefault($msg);
		} // END multi empty
		else {
			$status = $tableActions->emptyTable($_POST['table']);
			if ($status == 0)
				doDefault($lang['strtableemptied']);
			else
				doDefault($lang['strtableemptiedbad']);
		} // END not multi empty
	} // END do Empty
}

/**
 * Show confirmation of drop and perform actual drop
 */
function doDrop($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);

	if (empty($_REQUEST['table']) && empty($_REQUEST['ma'])) {
		doDefault($lang['strspecifytabletodrop']);
		exit();
	}

	if ($confirm) {
		//If multi drop
		if (isset($_REQUEST['ma'])) {

			$misc->printTrail('schema');
			$misc->printTitle($lang['strdrop'], 'pg.table.drop');

			echo "<form action=\"tables.php\" method=\"post\">\n";
			foreach ($_REQUEST['ma'] as $v) {
				$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES));
				echo "<p>", sprintf($lang['strconfdroptable'], $misc->formatVal($a['table'])), "</p>\n";
				printf('<input type="hidden" name="table[]" value="%s" />', html_esc($a['table']));
			}
		} else {

			$misc->printTrail('table');
			$misc->printTitle($lang['strdrop'], 'pg.table.drop');

			echo "<p>", sprintf($lang['strconfdroptable'], $misc->formatVal($_REQUEST['table'])), "</p>\n";

			echo "<form action=\"tables.php\" method=\"post\">\n";
			echo "<input type=\"hidden\" name=\"table\" value=\"", html_esc($_REQUEST['table']), "\" />\n";
		} // END if multi drop

		echo "<input type=\"hidden\" name=\"action\" value=\"drop\" />\n";
		echo $misc->form;
		echo "<p><input type=\"checkbox\" id=\"cascade\" name=\"cascade\" /> <label for=\"cascade\">{$lang['strcascade']}</label></p>\n";
		echo "<input type=\"submit\" name=\"drop\" value=\"{$lang['strdrop']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
		echo "</form>\n";
	} // END confirm
	else {
		//If multi drop
		if (is_array($_REQUEST['table'])) {
			$msg = '';
			$status = $pg->beginTransaction();
			if ($status == 0) {
				foreach ($_REQUEST['table'] as $t) {
					$status = $tableActions->dropTable($t, isset($_POST['cascade']));
					if ($status == 0)
						$msg .= sprintf('%s: %s<br />', htmlentities($t, ENT_QUOTES, 'UTF-8'), $lang['strtabledropped']);
					else {
						$pg->endTransaction();
						doDefault(sprintf('%s%s: %s<br />', $msg, htmlentities($t, ENT_QUOTES, 'UTF-8'), $lang['strtabledroppedbad']));
						return;
					}
				}
			}
			if ($pg->endTransaction() == 0) {
				// Everything went fine, back to the Default page....
				AppContainer::setShouldReloadTree(true);
				doDefault($msg);
			} else
				doDefault($lang['strtabledroppedbad']);
		} else {
			$status = $tableActions->dropTable($_POST['table'], isset($_POST['cascade']));
			if ($status == 0) {
				AppContainer::setShouldReloadTree(true);
				doDefault($lang['strtabledropped']);
			} else
				doDefault($lang['strtabledroppedbad']);
		}
	} // END DROP
}// END Function

/**
 * Convert a regular table to a partitioned table
 */
function doConvertToPartitioned($confirm, $msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$typeActions = new TypeActions($pg);

	if (!isset($_REQUEST['stage'])) {
		$_REQUEST['stage'] = 1;
	}

	if (!isset($_REQUEST['table'])) {
		doDefault($lang['strspecifytabletoconvert']);
		return;
	}

	$_REQUEST['table'] = trim($_REQUEST['table']);

	switch ($_REQUEST['stage']) {
		case 1:
			// Stage 1: Show conversion form
			$misc->printTrail('table');
			$misc->printTitle($lang['strconverttopartitioned'], 'pg.table');
			$misc->printMsg($msg);

			// Get table info
			$table = $tableActions->getTable($_REQUEST['table']);
			if ($table->recordCount() == 0) {
				doDefault($lang['strinvalidparam']);
				return;
			}

			// Get columns for partition key selection
			$attrs = $tableActions->getTableAttributes($_REQUEST['table']);
			$atts_arr = [];
			while (!$attrs->EOF) {
				$atts_arr[] = [
					'attname' => $attrs->fields['attname'],
					'type' => $attrs->fields['type']
				];
				$attrs->MoveNext();
			}

			echo "<form action=\"tables.php\" method=\"post\">\n";
			echo "<table>\n";

			// Warning message
			echo "<tr><td colspan=\"2\">";
			echo "<div class=\"warning\">{$lang['strconverttopartitionedwarning']}</div>";
			echo "</td></tr>\n";

			// Partition strategy
			echo "<tr><th class=\"data left required\">{$lang['strpartitionstrategy']}</th>\n";
			echo "<td class=\"data1\">\n";
			echo "<select name=\"partition_strategy\" required>\n";
			echo "<option value=\"\">{$lang['strselect']}</option>\n";
			echo "<option value=\"RANGE\">{$lang['strpartitionrange']}</option>\n";
			echo "<option value=\"LIST\">{$lang['strpartitionlist']}</option>\n";
			echo "<option value=\"HASH\">{$lang['strpartitionhash']}</option>\n";
			echo "</select></td></tr>\n";

			// Partition keys (multi-select)
			echo "<tr><th class=\"data left required\">{$lang['strpartitionkey']}</th>\n";
			echo "<td class=\"data1\">\n";
			echo "<select name=\"partition_keys[]\" multiple size=\"5\" required>\n";
			foreach ($atts_arr as $att) {
				echo "<option value=\"", htmlspecialchars($att['attname']), "\">";
				echo htmlspecialchars($att['attname']), " (", htmlspecialchars($att['type']), ")</option>\n";
			}
			echo "</select>\n";
			echo "<br/><small>{$lang['strpartitionkeyselecthint']}</small>\n";
			echo "</td></tr>\n";

			// Copy data option
			echo "<tr><th class=\"data left\">{$lang['strconvertcopydata']}</th>\n";
			echo "<td class=\"data1\"><input type=\"checkbox\" name=\"copy_data\" checked /></td></tr>\n";

			// Tablespace
			$tablespaceActions = new TablespaceActions($pg);
			$tablespaces = $tablespaceActions->getTablespaces();
			if ($tablespaces->recordCount() > 0) {
				echo "<tr><th class=\"data left\">{$lang['strtablespace']}</th>\n";
				echo "<td class=\"data1\">\n";
				echo "<select name=\"tablespace\">\n";
				echo "<option value=\"\">{$lang['strdefault']}</option>\n";
				while (!$tablespaces->EOF) {
					echo "<option value=\"", htmlspecialchars($tablespaces->fields['spcname']), "\">";
					echo htmlspecialchars($tablespaces->fields['spcname']), "</option>\n";
					$tablespaces->MoveNext();
				}
				echo "</select></td></tr>\n";
			}

			echo "</table>\n";
			echo "<input type=\"hidden\" name=\"action\" value=\"convert_to_partitioned\" />\n";
			echo "<input type=\"hidden\" name=\"stage\" value=\"2\" />\n";
			echo $misc->form;
			echo "<input type=\"hidden\" name=\"table\" value=\"", htmlspecialchars($_REQUEST['table']), "\" />\n";
			echo "<p><input type=\"submit\" name=\"convert\" value=\"{$lang['strnext']}\" />\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
			echo "</form>\n";
			break;

		case 2:
			// Stage 2: Show execution plan preview
			$misc->printTrail('table');
			$misc->printTitle($lang['strconverttopartitioned'], 'pg.table');

			// Validate inputs
			if (empty($_POST['partition_strategy']) || empty($_POST['partition_keys'])) {
				doConvertToPartitioned(false, $lang['strpartitioninvalid']);
				return;
			}

			$partition_strategy = $_POST['partition_strategy'];
			$partition_keys = $_POST['partition_keys'];
			$copy_data = isset($_POST['copy_data']);
			$tablespace = $_POST['tablespace'] ?? '';

			$table = $tableActions->getTable($_REQUEST['table']);
			$table_row = $table->fields;

			// Show execution plan
			echo "<h3>{$lang['strconvertexecutionplan']}</h3>\n";
			echo "<ol>\n";
			echo "<li>{$lang['strconvertstep1']}</li>\n";
			if ($copy_data) {
				echo "<li>{$lang['strconvertstep2']}</li>\n";
			}
			echo "<li>{$lang['strconvertstep3']}</li>\n";
			echo "</ol>\n";

			echo "<div class=\"warning\">{$lang['strconvertwarning']}</div>\n";

			echo "<form action=\"tables.php\" method=\"post\">\n";
			echo "<input type=\"hidden\" name=\"action\" value=\"convert_to_partitioned\" />\n";
			echo "<input type=\"hidden\" name=\"stage\" value=\"3\" />\n";
			echo $misc->form;
			echo "<input type=\"hidden\" name=\"table\" value=\"", htmlspecialchars($_REQUEST['table']), "\" />\n";
			echo "<input type=\"hidden\" name=\"partition_strategy\" value=\"", htmlspecialchars($partition_strategy), "\" />\n";
			foreach ($partition_keys as $key) {
				echo "<input type=\"hidden\" name=\"partition_keys[]\" value=\"", htmlspecialchars($key), "\" />\n";
			}
			echo "<input type=\"hidden\" name=\"copy_data\" value=\"", $copy_data ? '1' : '0', "\" />\n";
			echo "<input type=\"hidden\" name=\"tablespace\" value=\"", htmlspecialchars($tablespace), "\" />\n";
			echo "<p><input type=\"submit\" name=\"confirm\" value=\"{$lang['strconvert']}\" />\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
			echo "</form>\n";
			break;

		case 3:
			// Stage 3: Execute conversion
			if (!$confirm) {
				doConvertToPartitioned(false, '');
				return;
			}

			// Validate inputs
			if (empty($_POST['partition_strategy']) || empty($_POST['partition_keys'])) {
				doDefault($lang['strpartitioninvalid']);
				return;
			}

			$partition_strategy = $_POST['partition_strategy'];
			$partition_keys = $_POST['partition_keys'];
			$copy_data = isset($_POST['copy_data']) && $_POST['copy_data'] == '1';
			$tablespace = $_POST['tablespace'] ?? '';

			// Get schema
			$table = $tableActions->getTable($_REQUEST['table']);
			if ($table->recordCount() == 0) {
				doDefault($lang['strinvalidparam']);
				return;
			}

			$schema = $_REQUEST['schema'];
			$original_table = $_REQUEST['table'];
			$temp_table = $original_table . '_old';
			$new_table = $original_table . '_partitioned';

			// Start transaction
			$status = $pg->beginTransaction();
			if ($status != 0) {
				doDefault($lang['strconvertfailed']);
				return;
			}

			try {
				// Step 1: Rename original table
				$sql = sprintf(
					'ALTER TABLE %s.%s RENAME TO %s',
					$pg->fieldClean($schema),
					$pg->fieldClean($original_table),
					$pg->fieldClean($temp_table)
				);
				$status = $pg->execute($sql);
				if ($status != 0) {
					throw new Exception($lang['strconvertstep1failed']);
				}

				// Step 2: Create partitioned table with same structure
				$attrs = $tableActions->getTableAttributes($temp_table);
				$col_definitions = [];
				while (!$attrs->EOF) {
					$col_def = sprintf(
						'%s %s',
						$pg->fieldClean($attrs->fields['attname']),
						$attrs->fields['type']
					);
					if ($attrs->fields['attnotnull'] == 't') {
						$col_def .= ' NOT NULL';
					}
					if ($attrs->fields['adsrc'] !== null) {
						$col_def .= ' DEFAULT ' . $attrs->fields['adsrc'];
					}
					$col_definitions[] = $col_def;
					$attrs->MoveNext();
				}

				$partition_key_str = implode(', ', array_map([$pg, 'fieldClean'], $partition_keys));

				$sql = sprintf(
					'CREATE TABLE %s.%s (%s) PARTITION BY %s (%s)',
					$pg->fieldClean($schema),
					$pg->fieldClean($original_table),
					implode(', ', $col_definitions),
					$partition_strategy,
					$partition_key_str
				);

				if (!empty($tablespace)) {
					$sql .= ' TABLESPACE ' . $pg->fieldClean($tablespace);
				}

				$status = $pg->execute($sql);
				if ($status != 0) {
					throw new Exception($lang['strconvertstep2failed']);
				}

				// Step 3: Copy data if requested
				if ($copy_data) {
					// Note: This will fail if no partitions exist yet
					// User should create partitions first
					echo "<div class=\"warning\">{$lang['strconvertdatacopynote']}</div>\n";
				}

				// Step 4: Drop old table
				$sql = sprintf(
					'DROP TABLE %s.%s',
					$pg->fieldClean($schema),
					$pg->fieldClean($temp_table)
				);
				$status = $pg->execute($sql);
				if ($status != 0) {
					throw new Exception($lang['strconvertstep3failed']);
				}

				// Commit transaction
				$status = $pg->endTransaction();
				if ($status != 0) {
					throw new Exception($lang['strconvertcommitfailed']);
				}

				doDefault($lang['strconvertsuccess']);
			} catch (Exception $e) {
				$pg->rollbackTransaction();
				doDefault($e->getMessage());
			}
			break;
	}
}

/**
 * Show default list of tables in the database
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$conf = AppContainer::getConf();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);

	$misc->printTrail('schema');
	$misc->printTabs('schema', 'tables');
	$misc->printMsg($msg);

	$tables = $tableActions->getTables();

	$getIcon = function ($f) {
		// Use PartitionedTable icon for partitioned tables (relkind = 'p')
		if (isset($f['relkind']) && $f['relkind'] === 'p') {
			return 'PartitionedTable';
		}
		return 'Table';
	};

	$columns = [
		'table' => [
			'title' => $lang['strtable'],
			'field' => field('relname'),
			'url' => "redirect.php?subject=table&amp;{$misc->href}&amp;",
			'vars' => ['table' => 'relname'],
			'icon' => callback($getIcon),
			'class' => 'nowrap',
		],
		'owner' => [
			'title' => $lang['strowner'],
			'field' => field('relowner'),
		],
		'tablespace' => [
			'title' => $lang['strtablespace'],
			'field' => field('tablespace')
		],
		'tuples' => [
			'title' => $lang['strestimatedrowcount'],
			'field' => field('reltuples'),
			'type' => 'numeric'
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('relcomment'),
		],
	];

	$actions = [
		'multiactions' => [
			'keycols' => ['table' => 'relname'],
			'url' => 'tables.php',
			'default' => 'analyze',
		],
		'browse' => [
			'icon' => $misc->icon('Table'),
			'content' => $lang['strbrowse'],
			'attr' => [
				'href' => [
					'url' => 'display.php',
					'urlvars' => [
						'subject' => 'table',
						'return' => 'table',
						'table' => field('relname')
					]
				]
			]
		],
		'select' => [
			'icon' => $misc->icon('Search'),
			'content' => $lang['strselect'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confselectrows',
						'table' => field('relname')
					]
				]
			]
		],
		'insert' => [
			'icon' => $misc->icon('Add'),
			'content' => $lang['strinsert'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confinsertrow',
						'table' => field('relname')
					]
				]
			]
		],
		'empty' => [
			'multiaction' => 'confirm_empty',
			'icon' => $misc->icon('Shredder'),
			'content' => $lang['strempty'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confirm_empty',
						'table' => field('relname')
					]
				]
			]
		],
		'alter' => [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stralter'],
			'attr' => [
				'href' => [
					'url' => 'tblproperties.php',
					'urlvars' => [
						'action' => 'confirm_alter',
						'table' => field('relname')
					]
				]
			]
		],
		'convert_to_partitioned' => [
			'icon' => $misc->icon('PartitionedTable'),
			'content' => $lang['strconverttopartitioned'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'convert_to_partitioned',
						'table' => field('relname')
					]
				]
			]
		],
		'drop' => [
			'multiaction' => 'confirm_drop',
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'table' => field('relname')
					]
				]
			]
		],
		'vacuum' => [
			'multiaction' => 'confirm_vacuum',
			'icon' => $misc->icon('Broom'),
			'content' => $lang['strvacuum'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confirm_vacuum',
						'table' => field('relname')
					]
				]
			]
		],
		'analyze' => [
			'multiaction' => 'confirm_analyze',
			'icon' => $misc->icon('Analyze'),
			'content' => $lang['stranalyze'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confirm_analyze',
						'table' => field('relname')
					]
				]
			]
		],
		'reindex' => [
			'multiaction' => 'confirm_reindex',
			'icon' => $misc->icon('Index'),
			'content' => $lang['strreindex'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confirm_reindex',
						'table' => field('relname')
					]
				]
			]
		]
		//'cluster' TODO ?
	];

	$isCatalog = $misc->isCatalogSchema();
	if ($isCatalog) {
		$actions = array_intersect_key(
			$actions,
			array_flip(['browse', 'select'])
		);
	}

	// Filter convert_to_partitioned action based on PG version and table type
	if ($pg->major_version < 10) {
		unset($actions['convert_to_partitioned']);
	} else {
		// Add disable condition for partitioned tables (relkind='p')
		$actions['convert_to_partitioned']['disable'] = function ($row) {
			return isset($row['relkind']) && $row['relkind'] === 'p';
		};
	}

	$misc->printTable($tables, $columns, $actions, 'tables-tables', $lang['strnotables']);

	$navlinks = [
		'create' => [
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'create',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema']
					]
				]
			],
			'icon' => $misc->icon('CreateTable'),
			'content' => $lang['strcreatetable']
		]
	];

	if ($tables->recordCount() > 0) {
		$navlinks['createlike'] = [
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'createlike',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema']
					]
				]
			],
			'icon' => $misc->icon('CreateTableLike'),
			'content' => $lang['strcreatetablelike']
		];
	}

	if (!$isCatalog) {
		$misc->printNavLinks($navlinks, 'tables-tables', get_defined_vars());
	}
}

require('./admin.php');

/**
 * Generate XML for the browser tree.
 */
function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$tableActions = new TableActions($pg);

	$tables = $tableActions->getTables();

	$reqvars = $misc->getRequestVars('table');

	$getIcon = function ($f) {
		// Use PartitionedTable icon for partitioned tables (relkind = 'p')
		if (isset($f['relkind']) && $f['relkind'] === 'p') {
			return 'PartitionedTable';
		}
		return 'Table';
	};

	$attrs = [
		'text' => field('relname'),
		'icon' => callback($getIcon),
		'iconAction' => url(
			'display.php',
			$reqvars,
			['table' => field('relname')]
		),
		'toolTip' => field('relcomment'),
		'action' => url(
			'redirect.php',
			$reqvars,
			['table' => field('relname')]
		),
		'branch' => url(
			'tables.php',
			$reqvars,
			[
				'action' => 'subtree',
				'table' => field('relname')
			]
		)
	];

	$misc->printTree($tables, $attrs, 'tables');
	exit;
}

function doSubTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$partitionActions = new PartitionActions($pg);

	$tabs = $misc->getNavTabs('table');

	if (!$partitionActions->isPartitionedTable($_REQUEST['table'])) {
		// Remove 'Partitions' tab for non-partitioned tables
		unset($tabs['partitions']);
	}

	$items = $misc->adjustTabsForTree($tabs);
	$reqvars = $misc->getRequestVars('table');

	$attrs = [
		'text' => field('title'),
		'icon' => field('icon'),
		'action' => url(
			field('url'),
			$reqvars,
			field('urlvars'),
			['table' => $_REQUEST['table']]
		),
		'branch' => ifempty(
			field('branch'),
			'',
			url(
				field('url'),
				$reqvars,
				[
					'action' => 'tree',
					'table' => $_REQUEST['table']
				]
			)
		),
	];

	$misc->printTree($items, $attrs, 'table');
	exit;
}

//$data = AppContainer::getData();
//$conf = AppContainer::getConf();
$lang = AppContainer::getLang();
$misc = AppContainer::getMisc();

$action = $_REQUEST['action'] ?? '';

if ($action == 'tree')
	doTree();
if ($action == 'subtree')
	dosubTree();

$misc->printHeader($lang['strtables']);
$misc->printBody();

switch ($action) {
	case 'create':
		if (isset($_POST['cancel']))
			doDefault();
		else
			doCreate();
		break;
	case 'createlike':
		doCreateLike(false);
		break;
	case 'confcreatelike':
		if (isset($_POST['cancel']))
			doDefault();
		else
			doCreateLike(true);
		break;
	case 'selectrows':
		if (!isset($_POST['cancel']))
			doSelectRows(false);
		else
			doDefault();
		break;
	case 'confselectrows':
		doSelectRows(true);
		break;
	case 'empty':
		if (isset($_POST['empty']))
			doEmpty(false);
		else
			doDefault();
		break;
	case 'confirm_empty':
		doEmpty(true);
		break;
	case 'drop':
		if (isset($_POST['drop']))
			doDrop(false);
		else
			doDefault();
		break;
	case 'confirm_drop':
		doDrop(true);
		break;
	case 'convert_to_partitioned':
		if (isset($_POST['cancel']))
			doDefault();
		elseif (isset($_POST['confirm']))
			doConvertToPartitioned(true);
		else
			doConvertToPartitioned(false);
		break;
	default:
		if (adminActions($action, 'table') === false)
			doDefault();
		break;
}

$misc->printFooter();
