<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\ConstraintActions;
use PhpPgAdmin\Database\Actions\RowActions;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\ByteaQueryModifier;
use PhpPgAdmin\Database\QueryResultMetadataProbe;
use PhpPgAdmin\Gui\FormRenderer;
use PHPSQLParser\PHPSQLParser;

/**
 * Common relation browsing function that can be used for views,
 * tables, reports, arbitrary queries, etc. to avoid code duplication.
 * @param string $query The SQL SELECT string to execute
 * @param string $count The same SQL query, but only retrieves the count of the rows (AS total)
 * @param mixed $return The return section
 * @param int $page The current page
 *
 * $Id: display.php,v 1.68 2008/04/14 12:44:27 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

// Prevent timeouts on large exports (non-safe mode only)
if (!ini_get('safe_mode'))
	set_time_limit(0);


/**
 * Show confirmation of edit or insert and perform insert or update
 */
function doEditRow($confirm, $msg = '')
{

	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$conf = AppContainer::getConf();
	$lang = AppContainer::getLang();
	$rowActions = new RowActions($pg);
	$tableActions = new TableActions($pg);

	beginHtml();

	$insert = !isset($_REQUEST['key']);
	if (!$insert) {
		if (is_array($_REQUEST['key']))
			$keyFields = $_REQUEST['key'];
		else
			$keyFields = unserialize(urldecode($_REQUEST['key']));
		$rs = $rowActions->browseRow($_REQUEST['table'], $keyFields);
	} else {
		$rs = null;
	}

	$attrs = $tableActions->getTableAttributes($_REQUEST['table']);

	if (isset($_REQUEST['edit-inline'])) {
		// edit field inline
		if ($confirm) {
			// load data
		} else {
			// save data
		}
	}

	if ($confirm) {

		$formRenderer = new FormRenderer();

		//var_dump($keyFields);
		$initial = empty($_POST);

		$misc->printTrail($_REQUEST['subject']);
		$misc->printTitle($insert ? $lang['strinsertrow'] : $lang['streditrow']);
		$misc->printMsg($msg);

		if (($conf['autocomplete'] != 'disable')) {
			$fksprops = $misc->getAutocompleteFKProperties($_REQUEST['table'], 'insert');
			if ($fksprops !== false)
				echo $fksprops['code'];
		} else
			$fksprops = false;

		$function_def = <<<EOT
Date/Time
CURRENT_DATE, CURRENT_TIME, NOW (), DATE_TRUNC (value), AGE (value), TO_CHAR (value), TO_DATE (value), INTERVAL
Strings/Text
LENGTH (value), CHAR_LENGTH (value), LOWER (value), UPPER (value), TRIM (value), LTRIM (value), RTRIM (value), MD5 (value), ENCODE (value,'base64'), ENCODE (value,'escape'), ENCODE (value,'hex'), DECODE (value,'base64'), DECODE (value,'escape'), DECODE (value,'hex')
Math
ABS (value), CEIL (value), FLOOR (value), ROUND (value), EXP (value), LOG (value), LOG10 (value), POWER (value), SQRT (value), PI (value), SIN (value), COS (value), TAN (value)
UUID
gen_random_uuid (), uuid_generate_v4 ()
Network
inet, cidr, host (value), hostmask (value), network (value), masklen (value)
System/Info
current_user, session_user, version (), database ()
EOT;
		$functions_by_category = [];
		$all_functions = [];
		$category = null;
		foreach (explode("\n", $function_def) as $line) {
			if (!isset($category)) {
				$category = $line;
				continue;
			}
			$functions_subset = explode(', ', $line);
			$functions_by_category[$category] = $functions_subset;
			$all_functions = array_merge_recursive($all_functions, $functions_subset);
			$category = null;
		}
		// make function searchable by key
		$all_functions = array_combine($all_functions, $all_functions);

		echo "<form action=\"display.php\" method=\"post\" id=\"ac_form\" enctype=\"multipart/form-data\">\n";
		$error = true;
		if ($attrs->recordCount() > 0 && ($insert || $rs->recordCount() == 1)) {
			echo "<table>\n";

			// Output table header
			echo "<tr>\n";
			//echo "<th class=\"data\"></th>\n";
			echo "<th class=\"data\">{$lang['strcolumn']}</th>\n";
			echo "<th class=\"data\">{$lang['strtype']}</th>";
			echo "<th class=\"data\">{$lang['strfunction']}</th>\n";
			echo "<th class=\"data\">{$lang['strnull']}</th>\n";
			echo "<th class=\"data\">{$lang['strvalue']}</th>\n";
			echo "<th class=\"data\">{$lang['strexpr']}</th>\n";
			echo "</tr>";

			$i = 0;
			while (!$attrs->EOF) {

				$attrs->fields['attnotnull'] = $pg->phpBool($attrs->fields['attnotnull']);
				$id = (($i & 1) == 0 ? '1' : '2');

				// Initialise variables
				//if (!isset($_REQUEST['format'][$attrs->fields['attname']]))
				//	$_REQUEST['format'][$attrs->fields['attname']] = 'VALUE';

				if ($initial) {
					if ($insert) {
						$value = $attrs->fields['adsrc'];
						if (!empty($value)) {
							$search = str_replace("()", " ()", strtoupper($value));
							$function = $all_functions[$search] ?? null;
							if (!empty($function)) {
								// use function
								$_REQUEST['format'][$attrs->fields['attname']] = $function;
								$value = '';
							} else {
								// use expression
								$_REQUEST['expr'][$attrs->fields['attname']] = 1;
							}
							//$_REQUEST['expr'][$attrs->fields['attname']] = 1;
						}
					} else {
						$value = $rs->fields[$attrs->fields['attname']];
					}
				} else {
					$value = $_REQUEST["values"][$attrs->fields['attname']];
				}

				echo "<tr class=\"data{$id}\">\n";
				//echo "<td class=\"info\">#", $i+1, "</td>";
				echo "<th>", $misc->printVal($attrs->fields['attname']), "</th>";
				echo "<td>\n";
				echo $misc->printVal($pg->formatType($attrs->fields['type'], $attrs->fields['atttypmod']));
				//echo "<input type=\"hidden\" name=\"types[", htmlspecialchars($attrs->fields['attname']), "]\" value=\"", htmlspecialchars($attrs->fields['type']), "\" /></td>";
				echo "<td>\n";
				$sel_fnc_id = "sel_fnc_" . htmlspecialchars($attrs->fields['attname']);
				echo "<select id=\"$sel_fnc_id\" name=\"format[", htmlspecialchars($attrs->fields['attname']), "]\">\n";
				echo "<option></option>\n";
				$format = $_REQUEST['format'][$attrs->fields['attname']] ?? '';
				foreach ($functions_by_category as $category => $functions) {
					echo "<optgroup label=\"", htmlspecialchars($category), "\">\n";
					foreach ($functions as $function) {
						$selected = $format == $function ? " selected" : "";
						$function_html = htmlspecialchars($function);
						echo "<option value=\"$function_html\"{$selected}>$function_html</option>\n";
					}
					echo "</optgroup>\n";
				}
				/*
				echo "<option value=\"VALUE\"", ($_REQUEST['format'][$attrs->fields['attname']] == 'VALUE') ? ' selected="selected"' : '', ">{$lang['strvalue']}</option>\n";
				echo "<option value=\"EXPRESSION\"", ($_REQUEST['format'][$attrs->fields['attname']] == 'EXPRESSION') ? ' selected="selected"' : '', ">{$lang['strexpression']}</option>\n";
				*/
				echo "</select>\n</td>\n";
				echo "<td class=\"text-center\">";
				// Output null box if the column allows nulls (doesn't look at CHECKs or ASSERTIONS)
				if (!$attrs->fields['attnotnull']) {
					// Set initial null values
					if ($initial && ($insert || $rs->fields[$attrs->fields['attname']] === null)) {
						$_REQUEST['nulls'][$attrs->fields['attname']] = 'on';
					}
					$null_cb_id = "cb_null_" . htmlspecialchars($attrs->fields['attname']);
					echo "<label><span><input type=\"checkbox\" name=\"nulls[{$attrs->fields['attname']}]\" id=\"$null_cb_id\"",
						isset($_REQUEST['nulls'][$attrs->fields['attname']]) ? ' checked="checked"' : '', " /></span></label>\n";
				} else {
					echo "&nbsp;";
					$null_cb_id = "";
				}
				echo "</td>\n";

				echo "<td id=\"row_att_{$attrs->fields['attnum']}\">";

				$extras = [
					'data-field' => $attrs->fields['attname'],
				];

				//$extras['onChange'] = 'document.getElementById("' . $sel_fnc_id . '").value = "";';

				// If the column allows nulls, then we put a JavaScript action on
				// the data field to unset the NULL checkbox as soon as anything
				// is entered in the field.
				if (!$attrs->fields['attnotnull']) {
					$extras['onChange'] = 'document.getElementById("' . $null_cb_id . '").checked = false;';
				}

				if (($fksprops !== false) && isset($fksprops['byfield'][$attrs->fields['attnum']])) {
					$extras['id'] = "attr_{$attrs->fields['attnum']}";
					$extras['autocomplete'] = 'off';
					$extras['data-fk-context'] = 'insert';
					$extras['data-attnum'] = $attrs->fields['attnum'];
				}

				$formRenderer->printField(
					"values[{$attrs->fields['attname']}]",
					$value,
					$attrs->fields['type'],
					$extras
				);

				echo "</td>";
				echo "<td class=\"text-center\">\n";
				$expr_cb_id = "cb_expr_" . htmlspecialchars($attrs->fields['attname']);
				echo "<label><span><input type=\"checkbox\" id=\"$expr_cb_id\" name=\"expr[{$attrs->fields['attname']}]\"",
					!empty($_REQUEST['expr'][$attrs->fields['attname']]) ? ' checked="checked"' : '', " /></span></label>\n";
				echo "</td>";
				echo "</tr>\n";
				$i++;
				$attrs->moveNext();
			}
			echo "</table>\n";

			$error = false;
		} elseif ($rs->recordCount() != 1) {
			echo "<p>{$lang['strrownotunique']}</p>\n";
		} else {
			echo "<p>{$lang['strinvalidparam']}</p>\n";
		}

		echo "<input type=\"hidden\" name=\"action\" value=\"editrow\" />\n";
		echo $misc->form;
		if ($insert) {
			if (!isset($_SESSION['counter']))
				$_SESSION['counter'] = 0;
			echo "<input type=\"hidden\" name=\"protection_counter\" value=\"" . $_SESSION['counter'] . "\" />\n";
		} else {
			foreach ($keyFields as $field => $val) {
				echo "<input type=\"hidden\" name=\"key[", htmlspecialchars($field), "]\" value=\"", htmlspecialchars($val), "\" />\n";
			}
			//echo "<input type=\"hidden\" name=\"key\" value=\"", html_esc(urlencode(serialize($keyFields))), "\" />\n";
		}
		if (isset($_REQUEST['table']))
			echo "<input type=\"hidden\" name=\"table\" value=\"", htmlspecialchars($_REQUEST['table']), "\" />\n";
		if (isset($_REQUEST['subject']))
			echo "<input type=\"hidden\" name=\"subject\" value=\"", htmlspecialchars($_REQUEST['subject']), "\" />\n";
		if (isset($_REQUEST['query']))
			echo "<input type=\"hidden\" name=\"query\" value=\"", htmlspecialchars($_REQUEST['query']), "\" />\n";
		if (isset($_REQUEST['count']))
			echo "<input type=\"hidden\" name=\"count\" value=\"", htmlspecialchars($_REQUEST['count']), "\" />\n";
		if (isset($_REQUEST['return']))
			echo "<input type=\"hidden\" name=\"return\" value=\"", htmlspecialchars($_REQUEST['return']), "\" />\n";
		if (isset($_REQUEST['page']))
			echo "<input type=\"hidden\" name=\"page\" value=\"", htmlspecialchars($_REQUEST['page']), "\" />\n";
		if (isset($_REQUEST['orderby'])) {
			foreach ($_REQUEST['orderby'] as $field => $val) {
				echo "<input type=\"hidden\" name=\"orderby[", htmlspecialchars($field), "]\" value=\"", htmlspecialchars($val), "\" />\n";
			}
		}
		if (isset($_REQUEST['strings']))
			echo "<input type=\"hidden\" name=\"strings\" value=\"", htmlspecialchars($_REQUEST['strings']), "\" />\n";

		echo "<p>";
		if ($insert) {
			echo "<input type=\"submit\" name=\"insert\" value=\"{$lang['strinsert']}\" />\n";
			echo "<input type=\"submit\" name=\"insert_and_repeat\" accesskey=\"r\" value=\"{$lang['strinsertandrepeat']}\" />\n";
		} else {
			if (!$error)
				echo "<input type=\"submit\" name=\"save\" accesskey=\"r\" value=\"{$lang['strsave']}\" />\n";
		}
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";

		if ($fksprops !== false) {
			echo "&nbsp;&nbsp;&nbsp;";
			if ($conf['autocomplete'] != 'default off')
				echo "<input type=\"checkbox\" id=\"no_ac\" value=\"1\" checked=\"checked\" /> <label for=\"no_ac\"> {$lang['strac']}</label>\n";
			else
				echo "<input type=\"checkbox\" id=\"no_ac\" value=\"0\" /> <label for=\"no_ac\"> {$lang['strac']}</label>\n";
		}

		echo "</p>\n";
		echo "</form>\n";
	} else {

		if (!isset($_POST['values']))
			$_POST['values'] = [];
		if (!isset($_POST['nulls']))
			$_POST['nulls'] = [];
		if (!isset($_POST['expr']))
			$_POST['expr'] = [];

		$fields = [];
		$types = [];
		while (!$attrs->EOF) {
			$fields[$attrs->fields['attnum']] = $attrs->fields['attname'];
			$types[$attrs->fields['attname']] = $attrs->fields['type'];
			$attrs->moveNext();
		}

		if ($insert) {
			if ($_SESSION['counter']++ == $_POST['protection_counter']) {
				$status = $rowActions->insertRow(
					$_POST['table'],
					$fields,
					$_POST['values'],
					$_POST['nulls'],
					$_POST['format'],
					$_POST['expr'],
					$types
				);
				if ($status == 0) {
					if (isset($_POST['insert_and_repeat'])) {
						$_POST = [];
						unset($_REQUEST['values']);
						unset($_REQUEST['expr']);
						unset($_REQUEST['nulls']);
						unset($_REQUEST['format']);
						doEditRow(true, $lang['strrowinserted']);
					} else
						doBrowse($lang['strrowinserted']);
				} else
					doEditRow(true, $lang['strrowinsertedbad']);
			} else
				doEditRow(true, $lang['strrowduplicate']);
		} else {
			$status = $rowActions->editRow(
				$_POST['table'],
				$_POST['values'],
				$_POST['nulls'],
				$_POST['format'],
				$_POST['expr'],
				$types,
				$keyFields
			);
			if ($status == 0)
				doBrowse($lang['strrowupdated']);
			elseif ($status == -2)
				doEditRow(true, $lang['strrownotunique']);
			else
				doEditRow(true, $lang['strrowupdatedbad']);
		}
	}
}

/**
 * Download bytea field data
 */
function doDownloadBytea()
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$schemaActions = new SchemaActions($pg);

	// Validate required parameters
	if (empty($_REQUEST['table']) || empty($_REQUEST['column']) || empty($_REQUEST['schema'])) {
		header('HTTP/1.0 400 Bad Request');
		echo 'Missing required parameters';
		exit;
	}

	if (empty($_REQUEST['key']) || !is_array($_REQUEST['key'])) {
		header('HTTP/1.0 400 Bad Request');
		echo 'Missing key fields';
		exit;
	}

	$table = $_REQUEST['table'];
	$column = $_REQUEST['column'];
	$schema = $_REQUEST['schema'];
	$keyFields = $_REQUEST['key'];

	// Ensure schema context for attribute checks
	$schemaActions->setSchema($schema);

	// Verify column exists and is bytea type
	$attrs = $tableActions->getTableAttributes($table);
	$columnExists = false;
	$isBytea = false;

	if ($attrs && $attrs->recordCount() > 0) {
		while (!$attrs->EOF) {
			if ($attrs->fields['attname'] === $column) {
				$columnExists = true;
				$type = $attrs->fields['type'] ?? '';
				$isBytea = (strpos($type, 'bytea') === 0);
				break;
			}
			$attrs->moveNext();
		}
	}

	if (!$columnExists || !$isBytea) {
		header('HTTP/1.0 400 Bad Request');
		echo 'Invalid column or not bytea type';
		exit;
	}

	// Build WHERE clause from key fields
	$whereParts = [];
	foreach ($keyFields as $field => $value) {
		if ($value === null || (is_string($value) && strcasecmp($value, 'NULL') === 0)) {
			$whereParts[] = $pg->escapeIdentifier($field) . ' IS NULL';
		} else {
			$whereParts[] = $pg->escapeIdentifier($field) . ' = ' . $pg->clean($value);
		}
	}
	$whereClause = implode(' AND ', $whereParts);

	// Query to fetch the bytea data
	$sql = 'SELECT ' . $pg->escapeIdentifier($column) .
		' FROM ' . $pg->escapeIdentifier($schema) . '.' . $pg->escapeIdentifier($table) .
		' WHERE ' . $whereClause .
		' LIMIT 1';

	$result = $pg->selectSet($sql);

	if ($result && $result->recordCount() === 1) {
		$data = $result->fields[$column];

		if ($data === null) {
			header('HTTP/1.0 404 Not Found');
			echo 'Data is NULL';
			exit;
		}

		// Send binary data
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . $table . '_' . $column . '.bin"');
		header('Content-Length: ' . strlen($data));
		header('Cache-Control: must-revalidate');
		header('Pragma: public');

		echo $data;
		exit;
	} else {
		header('HTTP/1.0 404 Not Found');
		echo 'Data not found';
		exit;
	}
}

/**
 * Show confirmation of drop and perform actual drop
 */
function doDelRow($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$rowActions = new RowActions($pg);

	beginHtml();

	if ($confirm) {
		$misc->printTrail($_REQUEST['subject']);
		$misc->printTitle($lang['strdeleterow']);

		$rs = $rowActions->browseRow($_REQUEST['table'], $_REQUEST['key']);

		echo "<form action=\"display.php\" method=\"post\">\n";
		echo $misc->form;

		if ($rs->recordCount() == 1) {
			echo "<p>{$lang['strconfdeleterow']}</p>\n";

			$fkinfo = [];
			echo "<table><tr>";
			printTableHeaderCells($rs, false, true);
			echo "</tr>";
			echo "<tr class=\"data1\">\n";
			printTableRowCells($rs, $fkinfo, true);
			echo "</tr>\n";
			echo "</table>\n";
			echo "<br />\n";

			echo "<input type=\"hidden\" name=\"action\" value=\"delrow\" />\n";
			echo "<input type=\"submit\" name=\"yes\" value=\"{$lang['stryes']}\" />\n";
			echo "<input type=\"submit\" name=\"no\" value=\"{$lang['strno']}\" />\n";
		} elseif ($rs->recordCount() != 1) {
			echo "<p>{$lang['strrownotunique']}</p>\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
		} else {
			echo "<p>{$lang['strinvalidparam']}</p>\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
		}
		if (isset($_REQUEST['table']))
			echo "<input type=\"hidden\" name=\"table\" value=\"", html_esc($_REQUEST['table']), "\" />\n";
		if (isset($_REQUEST['subject']))
			echo "<input type=\"hidden\" name=\"subject\" value=\"", html_esc($_REQUEST['subject']), "\" />\n";
		if (isset($_REQUEST['query']))
			echo "<input type=\"hidden\" name=\"query\" value=\"", html_esc($_REQUEST['query']), "\" />\n";
		if (isset($_REQUEST['count']))
			echo "<input type=\"hidden\" name=\"count\" value=\"", html_esc($_REQUEST['count']), "\" />\n";
		if (isset($_REQUEST['return']))
			echo "<input type=\"hidden\" name=\"return\" value=\"", html_esc($_REQUEST['return']), "\" />\n";
		echo "<input type=\"hidden\" name=\"page\" value=\"", html_esc($_REQUEST['page']), "\" />\n";
		if (isset($_REQUEST['orderby'])) {
			foreach ($_REQUEST['orderby'] as $key => $val) {
				echo "<input type=\"hidden\" name=\"orderby[", htmlspecialchars($key), "]\" value=\"", htmlspecialchars($val), "\" />\n";
			}
		}
		echo "<input type=\"hidden\" name=\"strings\" value=\"", html_esc($_REQUEST['strings']), "\" />\n";
		echo "<input type=\"hidden\" name=\"key\" value=\"", html_esc(urlencode(serialize($_REQUEST['key']))), "\" />\n";
		echo "</form>\n";
	} else {
		$status = $rowActions->deleteRow($_POST['table'], unserialize(urldecode($_POST['key'])));
		if ($status == 0)
			doBrowse($lang['strrowdeleted']);
		elseif ($status == -2)
			doBrowse($lang['strrownotunique']);
		else
			doBrowse($lang['strrowdeletedbad']);
	}
}

/* build & return the FK information data structure
 * used when deciding if a field should have a FK link or not*/
function getFKInfo()
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$constraintActions = new ConstraintActions($pg);

	// Get the foreign key(s) information from the current table
	$fkey_information = ['byconstr' => [], 'byfield' => []];

	if (isset($_REQUEST['table'])) {
		$constraints = $constraintActions->getConstraintsWithFields($_REQUEST['table']);
		if ($constraints->recordCount() > 0) {

			$fkey_information['common_url'] = $misc->getHREF('schema') . '&amp;subject=table';

			/* build the FK constraints data structure */
			while (!$constraints->EOF) {
				$constr = $constraints->fields;
				if ($constr['contype'] == 'f') {

					if (!isset($fkey_information['byconstr'][$constr['conid']])) {
						$fkey_information['byconstr'][$constr['conid']] = [
							'url_data' => 'table=' . urlencode($constr['f_table']) . '&amp;schema=' . urlencode($constr['f_schema']),
							'fkeys' => [],
							'consrc' => $constr['consrc']
						];
					}

					$fkey_information['byconstr'][$constr['conid']]['fkeys'][$constr['p_field']] = $constr['f_field'];

					if (!isset($fkey_information['byfield'][$constr['p_field']]))
						$fkey_information['byfield'][$constr['p_field']] = [];

					$fkey_information['byfield'][$constr['p_field']][] = $constr['conid'];
				}
				$constraints->moveNext();
			}
		}
	}

	return $fkey_information;
}

/* Print table header cells
 * @param $args - associative array for sort link parameters
 * */
function printTableHeaderCells($rs, $args, $withOid)
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$conf = AppContainer::getConf();
	$j = 0;

	foreach ($rs->fields as $k => $v) {

		if (($k === $pg->id) && (!($withOid && $conf['show_oids']))) {
			$j++;
			continue;
		}
		$finfo = $rs->fetchField($j);

		if ($args === false) {
			echo "<th class=\"data\"><span>", htmlspecialchars($finfo->name), "</span></th>\n";
		} else {
			$args['page'] = $_REQUEST['page'];

			$sortLink = http_build_query($args);

			$keys = array_keys($_REQUEST['orderby']);

			echo "<th class=\"data\"><span><a class=\"orderby\" data-col=\"", htmlspecialchars($finfo->name), "\" data-type=\"", htmlspecialchars($finfo->type), "\" href=\"display.php?{$sortLink}\"><span>", htmlspecialchars($finfo->name), "</span>";
			if (isset($_REQUEST['orderby'][$finfo->name])) {
				if ($_REQUEST['orderby'][$finfo->name] === 'desc')
					echo '<img src="' . $misc->icon('LowerArgument') . '" alt="desc">';
				else
					echo '<img src="' . $misc->icon('RaiseArgument') . '" alt="asc">';
				echo "<span class='small'>", array_search($finfo->name, $keys) + 1, "</span>";
			}
			echo "</a></span></th>\n";
		}
		$j++;
	}

	reset($rs->fields);
}

/**
 * Print data-row cells
 * @param ADORecordSet $rs
 * @param array $fkey_information
 * @param bool $withOid
 * @param bool $editable
 */
function printTableRowCells($rs, $fkey_information, $withOid, $editable = false)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$conf = AppContainer::getConf();
	$lang = AppContainer::getLang();
	$j = 0;

	if (!isset($_REQUEST['strings']))
		$_REQUEST['strings'] = 'collapsed';

	$class = $editable ? "editable" : "";

	foreach ($rs->fields as $k => $v) {
		$finfo = $rs->fetchField($j++);

		if (($k === $pg->id) && (!($withOid && $conf['show_oids'])))
			continue;
		elseif ($v !== null && $v == '')
			echo "<td>&nbsp;</td>";
		else {

			echo "<td class=\"$class\" data-type=\"$finfo->type\" data-name=\"" . htmlspecialchars($finfo->name) . "\">";

			$valParams = [
				'null' => true,
				'clip' => ($_REQUEST['strings'] == 'collapsed')
			];
			if (($v !== null) && isset($fkey_information['byfield'][$k])) {
				foreach ($fkey_information['byfield'][$k] as $conid) {

					$query_params = $fkey_information['byconstr'][$conid]['url_data'];

					foreach ($fkey_information['byconstr'][$conid]['fkeys'] as $p_field => $f_field) {
						$query_params .= '&amp;' . urlencode("fkey[{$f_field}]") . '=' . urlencode($rs->fields[$p_field]);
					}

					/* $fkey_information['common_url'] is already urlencoded */
					$query_params .= '&amp;' . $fkey_information['common_url'];
					echo "<div style=\"display:inline-block;\">";
					echo "<a class=\"fk fk_" . htmlentities($conid, ENT_QUOTES, 'UTF-8') . "\" href=\"#\" data-href=\"display.php?{$query_params}\">";
					echo "<img src=\"" . $misc->icon('ForeignKey') . "\" style=\"vertical-align:middle;\" alt=\"[fk]\" title=\""
						. htmlentities($fkey_information['byconstr'][$conid]['consrc'], ENT_QUOTES, 'UTF-8')
						. "\" />";
					echo "</a>";
					echo "</div>";
				}
				$valParams['class'] = 'fk_value';
			}
			// If this is a modified bytea column, show size + download link
			$queryHash = $_SESSION['bytea_query_hash'] ?? null;
			$byteaCols = ($queryHash && isset($_SESSION['bytea_columns'][$queryHash])) ? $_SESSION['bytea_columns'][$queryHash] : [];

			if (!empty($byteaCols) && isset($byteaCols[$finfo->name]) && is_array($byteaCols[$finfo->name])) {
				$meta = $byteaCols[$finfo->name];
				$schema = $meta['schema'] ?? ($_REQUEST['schema'] ?? $pg->_schema);
				$table = $meta['table'] ?? ($_REQUEST['table'] ?? '');
				$column = $meta['column'] ?? $finfo->name;
				$keyFields = $meta['key_fields'] ?? [];

				$canLink = !empty($schema) && !empty($table) && !empty($column) && !empty($keyFields);
				$keyValues = [];
				if ($canLink) {
					foreach ($keyFields as $keyField) {
						if (!array_key_exists($keyField, $rs->fields)) {
							$canLink = false;
							break;
						}
						$keyValues[$keyField] = $rs->fields[$keyField];
					}
				}

				$sizeText = $misc->printVal($v, $finfo->type, $valParams);
				echo $sizeText;
				if ($canLink && $v !== null) {
					$params = [
						'action' => 'downloadbytea',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $schema,
						'table' => $table,
						'column' => $column,
						'key' => $keyValues,
					];
					$url = 'display.php?' . http_build_query($params);
					echo ' <a href="' . $url . '">' . htmlspecialchars($lang['strdownload']) . '</a>';
				}
			} else {
				echo $misc->printVal($v, $finfo->type, $valParams);
			}
			echo "</td>";
		}
	}
}

/* Print the FK row, used in ajax requests */
function doBrowseFK()
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$rowActions = new RowActions($pg);

	$ops = [];
	foreach ($_REQUEST['fkey'] as $x => $y) {
		$ops[$x] = '=';
	}
	$query = $pg->getSelectSQL($_REQUEST['table'], [], $_REQUEST['fkey'], $ops);
	$_REQUEST['query'] = $query;

	$fkinfo = getFKInfo();

	$max_pages = 1;
	// Retrieve page from query.  $max_pages is returned by reference.
	$rs = $rowActions->browseQuery(
		'SELECT',
		$_REQUEST['table'],
		$_REQUEST['query'],
		null,
		1,
		1,
		$max_pages
	);

	echo "<a href=\"#\" style=\"display:table-cell;\" class=\"fk_close\"><img alt=\"[close]\" src=\"" . $misc->icon('Close') . "\" /></a>\n";
	echo "<div style=\"display:table-cell;\">";

	if (is_object($rs) && $rs->recordCount() > 0) {
		/* we are browsing a referenced table here
		 * we should show OID if show_oids is true
		 * so we give true to withOid in functions below
		 * as 3rd parameter */

		echo "<table><tr>";
		printTableHeaderCells($rs, false, true);
		echo "</tr>";
		echo "<tr class=\"data1\">\n";
		printTableRowCells($rs, $fkinfo, true);
		echo "</tr>\n";
		echo "</table>\n";
	} else
		echo $lang['strnodata'];

	echo "</div>";

	exit;
}

/**
 * Displays requested data
 */
function doBrowse($msg = '')
{

	$pg = AppContainer::getPostgres();
	$conf = AppContainer::getConf();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$rowActions = new RowActions($pg);
	$schemaActions = new SchemaActions($pg);
	$plugin_manager = AppContainer::getPluginManager();

	$save_history = !isset($_REQUEST['nohistory']);

	if (!isset($_REQUEST['schema']))
		$_REQUEST['schema'] = $pg->_schema;

	// This code is used when browsing FK in pure-xHTML (without js)
	if (isset($_REQUEST['fkey'])) {
		$ops = [];
		foreach ($_REQUEST['fkey'] as $x => $y) {
			$ops[$x] = '=';
		}
		$query = $pg->getSelectSQL($_REQUEST['table'], [], $_REQUEST['fkey'], $ops);
		$_REQUEST['query'] = $query;
	}

	// Set the schema search path
	if (isset($_REQUEST['search_path'])) {
		if (
			$schemaActions->setSearchPath(
				array_map('trim', explode(',', $_REQUEST['search_path']))
			) != 0
		) {
			return;
		}
	}

	// read table/view name from url parameters
	$subject = $_REQUEST['subject'] ?? '';
	$table_name = $_REQUEST['table'] ?? $_REQUEST['view'] ?? null;

	if (isset($table_name)) {
		if (isset($_REQUEST['query'])) {
			//$misc->printTitle($lang['strselect']);
			$type = 'SELECT';
		} else {
			$type = 'TABLE';
		}
	} else {
		if (!isset($_REQUEST['query'])) {
			// if we come from sql.php or the query is too large to be passed
			// via GET parameters, retrieve it from the session
			$_REQUEST['query'] = $_SESSION['sqlquery'] ?? '';
		}
		//$misc->printTitle($lang['strqueryresults']);
		$type = 'QUERY';
	}

	// Get or build SQL query
	if (!empty($_REQUEST['query'])) {
		$query = $_REQUEST['query'];
		$parse_table = true;
	} else {
		$parse_table = false;
		$query = "SELECT * FROM " . $pg->escapeIdentifier($_REQUEST['schema']);
		if ($_REQUEST['subject'] == 'view') {
			$query .= "." . $pg->escapeIdentifier($_REQUEST['view']) . ";";
		} else {
			$query .= "." . $pg->escapeIdentifier($_REQUEST['table']) . ";";
		}
	}

	// Parse SQL query
	$parser = new PHPSQLParser();
	$parsed = $parser->parse($query);

	//$pg->conn->debug = true;
	//var_dump($parsed);

	// update table/view name in url parameters
	if ($parse_table) {
		if (!empty($parsed['SELECT']) && ($parsed['FROM'][0]['expr_type'] ?? '') == 'table') {
			$parts = $parsed['FROM'][0]['no_quotes']['parts'] ?? [];
			$changed = false;
			//var_dump($parts);
			if (count($parts) === 2) {
				[$schema, $table] = $parts;
				$changed = $_REQUEST['schema'] != $schema || $table_name != $table;
				//var_dump($_REQUEST['schema'], $table_name);
			} else {
				[$table] = $parts;
				$schema = $_REQUEST['schema'] ?? $pg->_schema;
				if (empty($schema)) {
					$schema = $tableActions->findTableSchema($table) ?? '';
					if (!empty($schema)) {
						$misc->setCurrentSchema($schema);
					}
					//var_dump($schema);
				}
				$changed = $table_name != $table && !empty($schema);
			}
			if ($changed) {
				//var_dump($schema, $table);
				$misc->setCurrentSchema($schema);
				$table_name = $table;
				unset($_REQUEST[$subject]);
				$subject = $tableActions->getTableType($schema, $table) ?? '';
				//var_dump($subject);
				if (!empty($subject)) {
					$_REQUEST['subject'] = $subject;
					$_REQUEST[$subject] = $table;
				}
			}
		}
	}

	// Fetch unique row identifier early (needed for bytea optimization)
	$key_fields_early = [];
	if (isset($table_name)) {
		$key_fields_early = $rowActions->getRowIdentifier($table_name);
	}

	// Change type to handle primary key information
	// Disable numeric fields and duplicate field names for now
	if ($type == 'QUERY' && !empty($table) && !empty($schema)) {
		$type = 'SELECT';
	}

	beginHtml();

	$misc->printTrail($subject ?? 'database');
	$misc->printTabs($subject, 'browse');

	$misc->printMsg($msg);

	// If current page is not set, default to first page
	if (!isset($_REQUEST['page']))
		$_REQUEST['page'] = 1;

	// If 'orderby' is not set, default to []
	if (!isset($_REQUEST['orderby']))
		$_REQUEST['orderby'] = [];

	// If 'strings' is not set, default to collapsed
	if (!isset($_REQUEST['strings']))
		$_REQUEST['strings'] = 'collapsed';

	// Default max_rows to $conf['max_rows'] if not set
	if (!isset($_REQUEST['max_rows']))
		$_REQUEST['max_rows'] = $conf['max_rows'];

	// Use key fields from early fetch (for bytea optimization) or fetch now
	if (!empty($key_fields_early)) {
		$key_fields = $key_fields_early;
	} elseif (isset($table_name)) {
		$key_fields = $rowActions->getRowIdentifier($table_name);
	} else {
		$key_fields = [];
	}

	$orderBySet = false;
	if (!empty($_POST['query'])) {
		// sql query has been posted, update orderby fields
		if (!empty($parsed['ORDER'])) {
			foreach ($parsed['ORDER'] as $orderExpr) {
				$field = trim($orderExpr['base_expr'], " \t\n\r\0\x0B;");
				if (preg_match('/^"(?:[^"]|"")*"$/', $field)) {
					$field = str_replace('""', '"', substr($field, 1, -1));
				} elseif (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
					// skip unknown expressions
					continue;
				}
				$dir = strtolower($orderExpr['direction'] ?? '');
				if ($dir !== 'desc') {
					$dir = 'asc';
				}
				$_REQUEST['orderby'][$field] = $dir;
			}
			$orderBySet = true;
		}
	} elseif (!empty($_REQUEST['orderby'])) {
		// update orderby in sql query
		if (!empty($parsed['SELECT'])) {

			if (!empty($_REQUEST['orderby'])) {
				$newOrderBy = ' ORDER BY ';
				$sep = "";
				foreach ($_REQUEST['orderby'] as $field => $dir) {
					$dir = strcasecmp($dir, 'desc') === 0 ? 'DESC' : 'ASC';
					$newOrderBy .= $sep . pg_escape_id($field) . ' ' . $dir;
					$sep = ", ";
				}
			} else {
				$newOrderBy = "";
			}

			if (!empty($parsed['ORDER'])) {
				$pattern = '/ORDER\s+BY[\s\S]*?(?=\sLIMIT|\sOFFSET|\sFETCH|\sFOR|\sUNION|\sINTERSECT|\sEXCEPT|\)|--|\/\*|;|\s*$)/i';
				preg_match_all($pattern, $query, $matches);

				if (!empty($matches[0])) {
					$lastOrderBy = end($matches[0]);
					$query = str_replace($lastOrderBy, $newOrderBy, $query);
					$orderBySet = true;
				}
			} elseif (!empty($newOrderBy)) {
				$query = rtrim($query, " \t\n\r\0\x0B;");

				$pattern = '/\s*(?:'
					. '(?:LIMIT|OFFSET|FETCH|FOR|UNION|INTERSECT|EXCEPT)\b[^;]*'
					. '|'
					. '\)'
					. '|'
					. '--[^\r\n]*'
					. '|'
					. '\/\*.*?\*\/'
					. ')\s*$/is';

				if (preg_match($pattern, $query, $matches, PREG_OFFSET_CAPTURE)) {
					$endPos = $matches[0][1];
					$query = substr($query, 0, $endPos) . $newOrderBy . substr($query, $endPos);
				} else {
					$query .= $newOrderBy;
				}

				$query .= ';';
				$orderBySet = true;
			}
		}
	}

	// Preserve the user-visible query (ORDER BY changes apply here).
	$displayQuery = $query;
	$execQuery = $displayQuery;

	// Bytea avoidance: try AST-based rewrite first (supports multi-table joins when keys are available).
	$execParsed = $parser->parse($execQuery);
	if (!empty($execParsed['SELECT']) && !empty($execParsed['FROM']) && is_array($execParsed['FROM'])) {
		$keyFieldsByAlias = [];
		$schemaActionsForKeys = new SchemaActions($pg);
		foreach ($execParsed['FROM'] as $from) {
			if (($from['expr_type'] ?? '') !== 'table') {
				$keyFieldsByAlias = [];
				break;
			}
			$parts = $from['no_quotes']['parts'] ?? [];
			if (empty($parts)) {
				continue;
			}
			if (count($parts) === 2) {
				$schemaName = $parts[0];
				$tableName = $parts[1];
			} elseif (count($parts) === 1) {
				$schemaName = $_REQUEST['schema'] ?? $pg->_schema;
				$tableName = $parts[0];
			} else {
				continue;
			}
			$alias = $from['alias']['name'] ?? $tableName;
			if (empty($alias) || empty($tableName)) {
				continue;
			}

			$schemaActionsForKeys->setSchema($schemaName);
			$keys = $rowActions->getRowIdentifier($tableName);
			if (is_array($keys) && !empty($keys)) {
				$keyFieldsByAlias[$alias] = $keys;
			}
		}

		if (!empty($keyFieldsByAlias)) {
			$byteaModifier = new ByteaQueryModifier();
			$modifierResult = $byteaModifier->modifyQuery($execParsed, $execQuery, $keyFieldsByAlias);
			$execQuery = $modifierResult['query'];

			if (!empty($modifierResult['bytea_columns'])) {
				$execHash = md5($execQuery);
				if (!isset($_SESSION['bytea_columns'])) {
					$_SESSION['bytea_columns'] = [];
				}
				$_SESSION['bytea_columns'][$execHash] = $modifierResult['bytea_columns'];
				$_SESSION['bytea_query_hash'] = $execHash;
			}
		}
	}

	// Fallback: probe result metadata (0 rows) to detect bytea output columns.
	// This sends a second statement to PostgreSQL, but should not execute table scans.
	$normalizedForProbe = preg_replace('/^(\s*--.*\n|\s*\/\*.*?\*\/)*/s', '', $execQuery);
	$normalizedForProbe = ltrim($normalizedForProbe);
	$isSelectOrWith = preg_match('/^(SELECT|WITH)\b/i', $normalizedForProbe);
	if ($isSelectOrWith) {
		$currentHash = md5($execQuery);
		$alreadyHasMeta = !empty($_SESSION['bytea_columns'][$currentHash] ?? null);
		if (!$alreadyHasMeta) {
			$probe = new QueryResultMetadataProbe();
			$probeResult = $probe->probeResultFields($execQuery);
			if (!empty($probeResult['fields']) && empty($probeResult['has_duplicate_names'])) {
				$hasBytea = false;
				foreach ($probeResult['fields'] as $f) {
					if (!empty($f['is_bytea'])) {
						$hasBytea = true;
						break;
					}
				}
				if ($hasBytea) {
					$execQuery = $probe->rewriteQueryReplaceByteaWithLength($execQuery, $probeResult['fields']);
					$probeMeta = [];
					foreach ($probeResult['fields'] as $f) {
						if (!empty($f['is_bytea'])) {
							$probeMeta[$f['name']] = [
								'schema' => null,
								'table' => null,
								'column' => $f['name'],
								'key_fields' => [],
							];
						}
					}
					if (!empty($probeMeta)) {
						if (!isset($_SESSION['bytea_columns'])) {
							$_SESSION['bytea_columns'] = [];
						}
						$newHash = md5($execQuery);
						$_SESSION['bytea_columns'][$newHash] = $probeMeta;
						$_SESSION['bytea_query_hash'] = $newHash;
					}
				}
			}
		}
	}

	// Use the original (user-visible) query for display/history/navigation.
	$query = $displayQuery;
	$_REQUEST['query'] = $displayQuery;
	// save the sql query in session for further use
	$_SESSION['sqlquery'] = $displayQuery;

	// Retrieve page from query.  $max_pages is returned by reference.
	$rs = $rowActions->browseQuery(
		$type,
		$table_name ?? null,
		$execQuery,
		$orderBySet ? [] : $_REQUEST['orderby'],
		$_REQUEST['page'],
		$_REQUEST['max_rows'],
		$max_pages
	);

	$pg->conn->setFetchMode(ADODB_FETCH_ASSOC);

	// Generate status line
	$status_line = format_string($lang['strbrowsestatistics'], [
		'count' => is_object($rs) ? $rs->rowCount() : 0,
		'first' => is_object($rs) && $rs->rowCount() > 0 ? $rowActions->lastQueryOffset + 1 : 0,
		'last' => min($rowActions->totalRowsFound, $rowActions->lastQueryOffset + $rowActions->lastQueryLimit),
		'total' => $rowActions->totalRowsFound,
		'duration' => round($pg->lastQueryTime, 5),
	]);

	// Get foreign key information for the current table
	$fkey_information = getFKInfo();

	// Build strings for GETs in array
	$_gets = [
		'server' => $_REQUEST['server'],
		'database' => $_REQUEST['database']
	];

	if (isset($_REQUEST['schema']))
		$_gets['schema'] = $_REQUEST['schema'];
	if (isset($table_name))
		$_gets[$subject] = $table_name;
	if (isset($subject))
		$_gets['subject'] = $subject;
	if (isset($_REQUEST['query']) && mb_strlen($_REQUEST['query']) <= $conf['max_get_query_length'])
		$_gets['query'] = $_REQUEST['query'];
	if (isset($_REQUEST['count']))
		$_gets['count'] = $_REQUEST['count'];
	if (isset($_REQUEST['return']))
		$_gets['return'] = $_REQUEST['return'];
	if (isset($_REQUEST['search_path']))
		$_gets['search_path'] = $_REQUEST['search_path'];
	if (isset($_REQUEST['table']))
		$_gets['table'] = $_REQUEST['table'];
	if (isset($_REQUEST['orderby']))
		$_gets['orderby'] = $_REQUEST['orderby'];
	if (isset($_REQUEST['nohistory']))
		$_gets['nohistory'] = $_REQUEST['nohistory'];
	$_gets['strings'] = $_REQUEST['strings'];
	$_gets['max_rows'] = $_REQUEST['max_rows'];

	// Save query to history if required
	if ($save_history) {
		$misc->saveSqlHistory($query, true);
	}

	$_sub_params = $_gets;
	unset($_sub_params['query']);
	// We adjust the form method via javascript to avoid length limits on GET requests
	?>
	<form method="get" onsubmit="adjustQueryFormMethod(this)" action="display.php?<?= http_build_query($_sub_params) ?>">
		<div>
			<textarea name="query" class="sql-editor frame resizable auto-expand" width="90%" rows="5" cols="100"
				resizable="true"><?= html_esc($query) ?></textarea>
		</div>
		<div><input type="submit" value="<?= $lang['strquerysubmit'] ?>" /></div>
	</form>
	<?php

	echo '<div class="query-result-line">', htmlspecialchars($status_line), '</div>', "\n";

	if (is_object($rs) && $rs->recordCount() > 0) {
		// Show page navigation
		$misc->printPageNavigation($_REQUEST['page'], $max_pages, $_gets, 'display.php');

		// Check that the key is actually in the result set.  This can occur for select
		// operations where the key fields aren't part of the select.  XXX:  We should
		// be able to support this, somehow.
		foreach ($key_fields as $v) {
			// If a key column is not found in the record set, then we
			// can't use the key.
			if (!in_array($v, array_keys($rs->fields))) {
				$key_fields = [];
				break;
			}
		}

		$buttons = [
			'edit' => [
				'icon' => $misc->icon('Edit'),
				'content' => $lang['stredit'],
				'attr' => [
					'href' => [
						'url' => 'display.php',
						'urlvars' => array_merge([
							'action' => 'confeditrow',
							'strings' => $_REQUEST['strings'],
							'page' => $_REQUEST['page'],
						], $_gets)
					]
				]
			],
			'delete' => [
				'icon' => $misc->icon('Delete'),
				'content' => $lang['strdelete'],
				'attr' => [
					'href' => [
						'url' => 'display.php',
						'urlvars' => array_merge([
							'action' => 'confdelrow',
							'strings' => $_REQUEST['strings'],
							'page' => $_REQUEST['page'],
						], $_gets)
					]
				]
			],
		];
		$actions = [
			'actionbuttons' => &$buttons,
			'place' => 'display-browse'
		];
		$plugin_manager->do_hook('actionbuttons', $actions);

		foreach (array_keys($actions['actionbuttons']) as $action) {
			$actions['actionbuttons'][$action]['attr']['href']['urlvars'] = array_merge(
				$actions['actionbuttons'][$action]['attr']['href']['urlvars'],
				$_gets
			);
		}

		$edit_params = $actions['actionbuttons']['edit'] ?? [];
		$delete_params = $actions['actionbuttons']['delete'] ?? [];

		$table_data = "";
		$edit_url_vars = $actions['actionbuttons']['edit']['attr']['href']['urlvars'] ?? null;
		if (!empty($key_fields) && !empty($edit_url_vars)) {
			$table_data .= " data-edit=\"" . htmlspecialchars(http_build_query($edit_url_vars)) . "\"";
		}

		//echo "<div class=\"scroll-container\">\n";
		echo "<table id=\"data\"{$table_data}>\n";
		echo "<tr data-orderby-desc=\"", htmlspecialchars($lang['strorderbyhelp']), "\">\n";

		// Display edit and delete actions if we have a key
		$colspan = min(1, count($buttons));
		//var_dump($key_fields);
		if ($colspan > 0 and count($key_fields) > 0) {
			$collapsed = $_REQUEST['strings'] === 'collapsed';
			echo "<th colspan=\"{$colspan}\" class=\"data\">";
			//echo $lang['stractions'];
			$link = [
				'attr' => [
					'href' => [
						'url' => 'display.php',
						'urlvars' => array_merge(
							$_gets,
							[
								'strings' => $collapsed ? 'expanded' : 'collapsed',
								'page' => $_REQUEST['page']
							]
						)
					]
				],
				'icon' => $misc->icon($collapsed ? 'TextExpand' : 'TextShrink'),
				'content' => $collapsed ? $lang['strexpand'] : $lang['strcollapse'],
			];
			$misc->printLink($link);
			echo "</th>\n";
		}

		/* we show OIDs only if we are in TABLE or SELECT type browsing */
		printTableHeaderCells($rs, $_gets, isset($table_name));

		echo "</tr>\n";

		$i = 0;
		reset($rs->fields);
		while (!$rs->EOF) {
			$id = (($i % 2) == 0 ? '1' : '2');
			// Display edit and delete links if we have a key
			$editable = $colspan > 0 && !empty($key_fields);
			if ($editable) {
				$keys_array = [];
				$keys_complete = true;
				foreach ($key_fields as $v) {
					if ($rs->fields[$v] === null) {
						$keys_complete = false;
						$editable = false;
						break;
					}
					$keys_array["key[{$v}]"] = $rs->fields[$v];
				}

				$tr_data = "";

				if ($keys_complete) {

					if (isset($actions['actionbuttons']['edit'])) {
						$actions['actionbuttons']['edit'] = $edit_params;
						$actions['actionbuttons']['edit']['attr']['href']['urlvars'] = array_merge(
							$actions['actionbuttons']['edit']['attr']['href']['urlvars'],
							$keys_array
						);
					} else {
						$editable = false;
					}

					if (isset($actions['actionbuttons']['delete'])) {
						$actions['actionbuttons']['delete'] = $delete_params;
						$actions['actionbuttons']['delete']['attr']['href']['urlvars'] = array_merge(
							$actions['actionbuttons']['delete']['attr']['href']['urlvars'],
							$keys_array
						);
					}

					if ($editable) {
						$tr_data .= " data-keys=\"" . htmlspecialchars(http_build_query($keys_array)) . "\"";
					}
				}

				echo "<tr class=\"data{$id} data-row\"{$tr_data}>\n";

				if ($keys_complete) {
					echo "<td class=\"action-buttons\">";
					foreach ($actions['actionbuttons'] as $action) {
						echo "<span class=\"opbutton{$id} op-button\">";
						$misc->printLink($action);
						echo "</span>\n";
					}
					echo "</td>\n";
				} else {
					echo "<td colspan=\"{$colspan}\">&nbsp;</td>\n";
				}
			} else {
				echo "<tr class=\"data{$id} data-row\">\n";
			}

			printTableRowCells($rs, $fkey_information, isset($table_name), $editable);

			echo "</tr>\n";
			$rs->moveNext();
			$i++;
		}
		echo "</table>\n";
		//echo "</div>\n";

		//echo "<p>", $rs->recordCount(), " {$lang['strrows']}</p>\n";
		// Show page navigation
		$misc->printPageNavigation($_REQUEST['page'], $max_pages, $_gets, 'display.php');
	} else {
		echo "<p class=\"nodata\">{$lang['strnodata']}</p>\n";
	}

	// Navigation links
	$navlinks = [];

	$fields = [
		'server' => $_REQUEST['server'],
		'database' => $_REQUEST['database'],
	];

	if (isset($_REQUEST['schema']))
		$fields['schema'] = $_REQUEST['schema'];

	// Return
	if (isset($_REQUEST['return'])) {
		$urlvars = $misc->getSubjectParams($_REQUEST['return']);

		$navlinks['back'] = [
			'attr' => [
				'href' => [
					'url' => $urlvars['url'],
					'urlvars' => $urlvars['params']
				]
			],
			'icon' => $misc->icon('Return'),
			'content' => $lang['strback']
		];
	}

	// Edit SQL link
	if ($type == 'QUERY')
		$navlinks['edit'] = [
			'attr' => [
				'href' => [
					'url' => 'database.php',
					'urlvars' => array_merge($fields, [
						'action' => 'sql',
						'paginate' => 'on',
					])
				]
			],
			'icon' => $misc->icon('Edit'),
			'content' => $lang['streditsql']
		];

	// Expand/Collapse
	if ($_REQUEST['strings'] == 'expanded')
		$navlinks['collapse'] = [
			'attr' => [
				'href' => [
					'url' => 'display.php',
					'urlvars' => array_merge(
						$_gets,
						[
							'strings' => 'collapsed',
							'page' => $_REQUEST['page']
						]
					)
				]
			],
			'icon' => $misc->icon('TextShrink'),
			'content' => $lang['strcollapse']
		];
	else
		$navlinks['collapse'] = [
			'attr' => [
				'href' => [
					'url' => 'display.php',
					'urlvars' => array_merge(
						$_gets,
						[
							'strings' => 'expanded',
							'page' => $_REQUEST['page']
						]
					)
				]
			],
			'icon' => $misc->icon('TextExpand'),
			'content' => $lang['strexpand']
		];

	// Create view and download
	if (isset($_REQUEST['query']) && isset($rs) && is_object($rs) && $rs->recordCount() > 0) {


		// Report views don't set a schema, so we need to disable create view in that case
		if (isset($_REQUEST['schema'])) {

			$navlinks['createview'] = [
				'attr' => [
					'href' => [
						'url' => 'views.php',
						'urlvars' => array_merge($fields, [
							'action' => 'create',
							'formDefinition' => $_REQUEST['query']
						])
					]
				],
				'icon' => $misc->icon('CreateView'),
				'content' => $lang['strcreateview']
			];
		}

		$urlvars = [];
		if (isset($_REQUEST['search_path']))
			$urlvars['search_path'] = $_REQUEST['search_path'];

		$navlinks['download'] = [
			'attr' => [
				'href' => [
					'url' => 'dataexport.php',
					'urlvars' => array_merge($fields, $urlvars, ['query' => $_REQUEST['query']])
				]
			],
			'icon' => $misc->icon('Download'),
			'content' => $lang['strdownload']
		];
	}

	// Insert
	if (isset($table_name) && (isset($subject) && $subject == 'table'))
		$navlinks['insert'] = [
			'attr' => [
				'href' => [
					'url' => 'display.php',
					'urlvars' => array_merge($_gets, [
						'action' => 'confinsertrow',
					])
				]
			],
			'icon' => $misc->icon('Add'),
			'content' => $lang['strinsert']
		];

	// Refresh
	$navlinks['refresh'] = [
		'attr' => [
			'href' => [
				'url' => 'display.php',
				'urlvars' => array_merge(
					$_gets,
					[
						'strings' => $_REQUEST['strings'],
						'page' => $_REQUEST['page']
					]
				)
			]
		],
		'icon' => $misc->icon('Refresh'),
		'content' => $lang['strrefresh']
	];

	$misc->printNavLinks($navlinks, 'display-browse', get_defined_vars());
}


// Put HTML header in a function to later adjust title if a custom query was send
function beginHtml()
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$conf = AppContainer::getConf();

	// Set the title based on the subject of the request
	$subject_type = $_REQUEST['subject'] ?? '';
	$subject_name = $_REQUEST[$subject_type] ?? '';
	if (!empty($subject_name)) {
		switch ($subject_type) {
			case 'table':
				$title = $lang['strtables'] . ': ' . $subject_name;
				break;
			case 'view':
				$title = $lang['strviews'] . ': ' . $subject_name;
				break;
			case 'column':
				$title = $lang['strcolumn'] . ': ' . $subject_name;
				break;
		}
	} else {
		$title = $lang['strqueryresults'];
	}

	$scripts = "<script src=\"js/display.js\" defer type=\"text/javascript\"></script>";
	$scripts .= "<script type=\"text/javascript\">\n";
	$scripts .= "var Display = {\n";
	$scripts .= "errmsg: '" . str_replace("'", "\'", $lang['strconnectionfail']) . "'\n";
	$scripts .= "};\n";
	$scripts .= "</script>\n";
	$scripts .= <<<"EOT"
<script type="text/javascript">
	// Adjust form method based on whether the query is read-only and its length
	// is small enough for a GET request.
	function adjustQueryFormMethod(form) {
		const isValidReadQuery =
			form.query.value.length <= {$conf['max_get_query_length']} &&
			isSqlReadQuery(form.query.value);
		if (isValidReadQuery) {
			form.method = 'get';
		} else {
			form.method = 'post';
		}
	}
</script>
EOT;

	$misc->printHeader($title ?? '', $scripts);
	$misc->printBody();
}

// Main program

//$pg = AppContainer::getPostgres();
//$conf = AppContainer::getConf();
//$lang = AppContainer::getLang();
$misc = AppContainer::getMisc();

$action = $_REQUEST['action'] ?? '';

/* shortcuts: this function exit the script for ajax purpose */
if ($action == 'dobrowsefk') {
	doBrowseFK();
}

if ($action == 'downloadbytea') {
	doDownloadBytea();
}

switch ($action) {
	case 'editrow':
	case 'insertrow':
		if (isset($_POST['cancel']))
			doBrowse();
		else
			doEditRow(false);
		break;
	case 'confeditrow':
	case 'confinsertrow':
		doEditRow(true);
		break;
	case 'delrow':
		if (isset($_POST['yes']))
			doDelRow(false);
		else
			doBrowse();
		break;
	case 'confdelrow':
		doDelRow(true);
		break;
	default:
		doBrowse();
		break;
}

$misc->printFooter();
