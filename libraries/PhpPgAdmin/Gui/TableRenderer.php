<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContext;

/**
 * Table rendering: data grid with actions and multi-select
 * Extracts printTable() from legacy Misc class
 */
class TableRenderer extends AppContext
{
    /**
     * Display a data table with optional actions and multi-select support.
     * 
     * @param $tabledata A recordset to display in table format.
     * @param $columns An associative array that describes the structure of the table.
     *        Keys are the field names, values are associative arrays with the following keys:
     *        'title' - Title of the column header
     *        'class' - Optional CSS class for td/th
     *        'field' - Name of the field, defaults to key
     *        'type' - Type of the field (see printVal)
     *        'params' - Optional parameters for printVal
     *        'url' - URL to link the field to, optional
     *        'vars' - Optional associate array of variables to pass to the URL
     *        'help' - Optional help identifier for printHelp
     * @param $actions An associative array with action buttons.
     *        Keys are the action names, values are arrays with keys:
     *        'content' - Link text
     *        'icon' - Link icon
     *        'title' - Link title
     *        'url' - Link URL
     *        'urlvars' - Optional URL vars
     *        'multiaction' - If present, action is a multi-action option
     *        'disable' - Set to true to disable action for certain rows (used in pre_fn)
     *        Special 'multiactions' key: array with 'url', 'vars', 'keycols', 'default'
     * @param $place - A string describing the location (used for plugin hooks)
     * @param $nodata - A message to be shown if there are no rows to display
     * @param $pre_fn - A callback function ($tabledata, $actions) that returns alternate actions
     *        or null to use default actions. Useful for disabling actions on certain rows.
     * @return bool true if rows were displayed, false otherwise
     */
    public function printTable($tabledata, $columns, $actions, $place, $nodata = null, $pre_fn = null): bool
    {
        $conf = $this->conf();
        $lang = $this->lang();
        $pluginManager = $this->pluginManager();

        // Action buttons hook's place
        $plugin_functions_parameters = [
            'actionbuttons' => &$actions,
            'place' => $place
        ];
        if ($pluginManager) {
            $pluginManager->do_hook('actionbuttons', $plugin_functions_parameters);
        }

        if ($has_ma = isset($actions['multiactions']))
            $ma = $actions['multiactions'];
        unset($actions['multiactions']);

        if ($tabledata->recordCount() > 0) {

            // Remove the 'comment' column if they have been disabled
            if (!$conf['show_comments']) {
                unset($columns['comment']);
            }

            if (isset($columns['comment'])) {
                // Uncomment this for clipped comments.
                // TODO: This should be a user option.
                //$columns['comment']['params']['clip'] = true;
            }

            if ($has_ma) {
                echo "<form id=\"multi_form\" action=\"{$ma['url']}\" method=\"post\" enctype=\"multipart/form-data\">\n";
                if (isset($ma['vars']))
                    foreach ($ma['vars'] as $k => $v)
                        echo "<input type=\"hidden\" name=\"$k\" value=\"$v\" />";
            }

            echo "<table class=\"data\">\n";
            echo "<thead class=\"sticky-thead\">\n";
            echo "<tr>\n";

            // Display column headings
            if ($has_ma)
                echo "<th></th>";
            foreach ($columns as $column_id => $column) {

                $class = $column['class'] ?? '';

                echo "<th class=\"data {$class}\">";
                if (isset($column['help']))
                    $this->misc()->printHelp($column['title'], $column['help']);
                else
                    echo $column['title'];
                echo "</th>\n";
            }
            echo "</tr>\n";
            echo "</thead>\n";
            echo "<tbody>\n";

            // Display table rows
            $i = 0;
            while (!$tabledata->EOF) {
                $id = ($i & 1) + 1;

                unset($alt_actions);
                if (!is_null($pre_fn))
                    $alt_actions = $pre_fn($tabledata, $actions);
                if (!isset($alt_actions))
                    $alt_actions = $actions;

                echo "<tr class=\"data{$id}\">\n";
                if ($has_ma) {
                    foreach ($ma['keycols'] as $k => $v)
                        $a[$k] = $tabledata->fields[$v];
                    echo "<td>";
                    echo "<input type=\"checkbox\" name=\"ma[]\" value=\"" . htmlentities(serialize($a), ENT_COMPAT, 'UTF-8') . "\" />";
                    echo "</td>\n";
                }

                foreach ($columns as $column_id => $column) {

                    $class = $column['class'] ?? '';
                    $classAttr = empty($class) ? '' : " class='$class'";

                    // Apply default values for missing parameters
                    if (isset($column['url']) && !isset($column['vars']))
                        $column['vars'] = [];

                    switch ($column_id) {
                        case 'actions':
                            echo "<td class=\"action-buttons {$class}\">";
                            foreach ($alt_actions as $action) {
                                if (value($action['disable'] ?? false, $tabledata->fields)) {
                                    continue;
                                }
                                echo "<span class=\"opbutton{$id} op-button\">";
                                $action['fields'] = $tabledata->fields;
                                $this->misc()->printLink($action);
                                echo "</span>\n";
                            }
                            echo "</td>\n";
                            break;
                        case 'comment':
                            echo "<td class='comment_cell'>";
                            $val = value($column['field'], $tabledata->fields);
                            if ($val !== null) {
                                echo htmlentities($val);
                            }
                            echo "</td>";
                            break;
                        default:
                            echo "<td$classAttr>";
                            $val = value($column['field'], $tabledata->fields);
                            if ($val !== null) {
                                if (isset($column['url'])) {
                                    echo "<a href=\"{$column['url']}";
                                    $this->misc()->printUrlVars($column['vars'], $tabledata->fields);
                                    echo "\">";
                                }
                                // Render icon if specified in column config
                                if (isset($column['icon'])) {
                                    $icon = value($column['icon'], $tabledata->fields);
                                    $icon = $this->misc()->icon($icon) ?: $icon;
                                    echo '<img src="' . htmlspecialchars($icon) . '" class="icon" alt="" />';
                                }
                                $type = $column['type'] ?? null;
                                $params = $column['params'] ?? [];
                                echo $this->misc()->formatVal($val, $type, $params);
                                if (isset($column['url'])) {
                                    echo "</a>";
                                }
                            }

                            echo "</td>\n";
                            break;
                    }
                }
                echo "</tr>\n";

                $tabledata->moveNext();
                $i++;
            }
            echo "</tbody>\n";
            echo "</table>\n";

            // Multi action table footer w/ options & [un]check'em all
            if ($has_ma) {
                // if default is not set or doesn't exist, set it to null
                if (!isset($ma['default']) || !isset($actions[$ma['default']])) {
                    $ma['default'] = null;
                }
                ?>
                <br />
                <table>
                    <tr>
                        <th class="data" style="text-align: left" colspan="4">
                            <?= $lang['stractionsonmultiplelines'] ?>
                        </th>
                    </tr>
                    <tr class="row1">
                        <td>
                            <input type="checkbox" onchange="toggleAllMf(this.checked);" />
                            <a href="#" onclick="this.previousElementSibling.click(); return false;">
                                <?= $lang['strselectall'] ?>
                            </a>
                        </td>
                        <td>&nbsp;<span class="psm">⮞</span>&nbsp;</td>
                        <td>
                            <select name="action">
                                <?php if ($ma['default'] == null): ?>
                                    <option value="">--</option>
                                <?php endif; ?>

                                <?php foreach ($actions as $k => $a): ?>
                                    <?php if (isset($a['multiaction'])): ?>
                                        <option value="<?= $a['multiaction'] ?>" <?= ($ma['default'] == $k ? ' selected="selected"' : '') ?>>
                                            <?= $a['content'] ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="submit" value="<?= $lang['strexecute'] ?>" />
                            <?= $this->misc()->form ?>
                        </td>
                    </tr>
                </table>
                </form>
                <?php
            }

            return true;
        } else {
            if (!empty($nodata)) {
                echo "<p class=\"nodata\">{$nodata}</p>\n";
            }
            return false;
        }
    }
}
