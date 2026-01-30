<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\TypeActions;
use PhpPgAdmin\Database\Actions\ColumnActions;

/**
 * Reusable renderer for column add/edit forms
 * Handles both single and multi-row column forms with array-based inputs
 */
class ColumnFormRenderer
{
    private $pg;
    private $misc;
    private $lang;

    public function __construct()
    {
        $this->pg = AppContainer::getPostgres();
        $this->misc = AppContainer::getMisc();
        $this->lang = AppContainer::getLang();
    }

    /**
     * Get standard column default presets
     * @return array Associative array of preset values and labels
     */
    public function getColumnDefaults()
    {
        return [
            '' => '',
            'NULL' => 'NULL',
            'CURRENT_TIMESTAMP' => 'CURRENT_TIMESTAMP',
            'gen_random_uuid()' => 'gen_random_uuid()',
            "'{}'::jsonb" => "'{}'::jsonb",
            'custom' => $this->lang['strcustom'] ?? 'Custom:',
        ];
    }

    /**
     * Get all available column types
     * @return array Array of type names excluding types that cannot be used for columns
     */
    public function getAllTypes()
    {
        static $allTypes = null;
        if ($allTypes === null) {
            $typeActions = new TypeActions($this->pg);
            $types = $typeActions->getTypes(true, false, true);
            $allTypes = $this->pg->extraTypes;
            while (!$types->EOF) {
                $allTypes[] = $types->fields['typname'];
                $types->moveNext();
            }
            $allTypes = array_diff($allTypes, ColumnActions::EXCLUDE_TYPES);
        }
        return $allTypes;
    }

    /**
     * Render the column form table
     * @param array $columns Array of column data. Each element should be an associative array with keys:
     *                       'attname', 'base_type', 'length', 'attnotnull', 'adsrc', 'comment', 'default_preset'
     *                       For new columns, use empty strings/nulls
     * @param array $postData Optional POST data to repopulate form (used after validation errors)
     */
    public function renderTable($columns, $postData = null)
    {
        ?>
        <table id="columnsTable">
            <tr>
                <th class="data required"><?= $this->lang['strname'] ?></th>
                <th class="data required" colspan="2"><?= $this->lang['strtype'] ?></th>
                <th class="data"><?= $this->lang['strlength'] ?></th>
                <th class="data text-center"><?= $this->lang['strnotnull'] ?></th>
                <th class="data"><?= $this->lang['strdefault'] ?></th>
                <th class="data"><?= $this->lang['strcomment'] ?></th>
            </tr>
            <?php
            $this->renderRows($columns, $postData);
            ?>
        </table>
        <?php
    }

    /**
     * Render table rows for column form
     * @param array $columns Array of column data. Each element should be an associative array with keys:
     *                       'attname', 'base_type', 'length', 'attnotnull', 'adsrc', 'comment', 'default_preset'
     *                       For new columns, use empty strings/nulls
     * @param array $postData Optional POST data to repopulate form (used after validation errors)
     */
    public function renderRows($columns, $postData = null)
    {
        $allTypes = $this->getAllTypes();
        $columnDefaults = $this->getColumnDefaults();
        $numColumns = count($columns);

        // Prepare predefined size types for potential JS use (kept for parity)
        //$predefined_size_types = array_intersect($this->pg->predefinedSizeTypes, $allTypes);

        for ($i = 0; $i < $numColumns; $i++) {
            $col = $columns[$i];

            // Initialize form values from POST data or column data
            if ($postData !== null && isset($postData['field'][$i])) {
                $field = $postData['field'][$i];
                $type = $postData['type'][$i] ?? '';
                $array = $postData['array'][$i] ?? '';
                $length = $postData['length'][$i] ?? '';
                $notnull = isset($postData['notnull'][$i]);
                $default_preset = $postData['default_preset'][$i] ?? '';
                $default = $postData['default'][$i] ?? '';
                $comment = $postData['comment'][$i] ?? '';
            } else {
                $field = $col['attname'] ?? '';
                $type = $col['base_type'] ?? '';

                // Check if it is an array type
                $array = strstr($type, '[]') ?: '';
                if (!empty($array)) {
                    $type = substr($type, 0, -2);
                }

                $length = $col['length'] ?? '';
                $notnull = isset($col['attnotnull']) && $col['attnotnull'];
                $default = $col['adsrc'] ?? '';
                $comment = $col['comment'] ?? '';

                // Determine default preset
                if (isset($col['default_preset'])) {
                    $default_preset = $col['default_preset'];
                } else {
                    $existingDefault = trim((string) $default);
                    if ($existingDefault === '') {
                        $default_preset = '';
                    } elseif (array_key_exists($existingDefault, $columnDefaults) && $existingDefault !== '' && $existingDefault !== 'custom') {
                        $default_preset = $existingDefault;
                    } else {
                        $default_preset = 'custom';
                    }
                }
            }

            $showCustom = ($default_preset == 'custom' || ($default_preset == '' && $default != ''));

            ?>
            <tr class="data<?= (($i & 1) == 0 ? '1' : '2') ?>" data-row-index="<?= $i ?>">
                <td>
                    <input name="field[<?= $i ?>]" size="16" maxlength="<?= (int) $this->pg->_maxNameLen ?>"
                        value="<?= html_esc($field) ?>" />
                </td>

                <td>
                    <select name="type[<?= $i ?>]" id="types<?= $i ?>" onchange="checkLengths(this.value, <?= $i ?>);">
                        <?php foreach ($allTypes as $t): ?>
                            <option value="<?= html_esc($t) ?>" <?= ($t == $type) ? ' selected="selected"' : '' ?>>
                                <?= $this->misc->formatVal($t) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>

                <td>
                    <select name="array[<?= $i ?>]">
                        <option value="" <?= ($array == '') ? ' selected="selected"' : '' ?>></option>
                        <option value="[]" <?= ($array == '[]') ? ' selected="selected"' : '' ?>>[ ]</option>
                    </select>
                </td>

                <td>
                    <input name="length[<?= $i ?>]" id="lengths<?= $i ?>" size="8" value="<?= html_esc($length) ?>" />
                </td>

                <td class="text-center">
                    <input type="checkbox" name="notnull[<?= $i ?>]" id="notnull<?= $i ?>" <?php if ($notnull)
                            echo ' checked="checked"'; ?> />
                </td>

                <td>
                    <select name="default_preset[<?= $i ?>]" id="default_preset<?= $i ?>"
                        onchange="handleDefaultPresetChange(<?= $i ?>);" style="margin-bottom: 2px;">
                        <?php foreach ($columnDefaults as $value => $label): ?>
                            <option value="<?= html_esc($value) ?>" <?= ($default_preset == $value) ? ' selected="selected"' : '' ?>>
                                <?= html_esc($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input name="default[<?= $i ?>]" id="default<?= $i ?>" size="7" value="<?= html_esc($default) ?>"
                        style="display: <?= $showCustom ? 'inline' : 'none' ?>;" />
                </td>

                <td>
                    <input name="comment[<?= $i ?>]" size="40" value="<?= html_esc($comment) ?>" />
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Generate JavaScript initialization code for column form
     * @param int $numColumns Number of columns in the form
     */
    public function renderJavaScriptInit($numColumns)
    {
        $allTypes = $this->getAllTypes();
        $predefined_size_types = array_intersect($this->pg->predefinedSizeTypes, $allTypes);
        ?>
        <script src="js/tables.js" type="text/javascript"></script>
        <script type="text/javascript">
            var predefined_lengths = <?= json_encode(array_values($predefined_size_types)) ?>;
            var maxNameLen = <?= (int) $this->pg->_maxNameLen ?>;
            var allTypes = <?= json_encode(array_values($allTypes)) ?>;

            // Initialize all rows
            for (var i = 0; i < <?= (int) $numColumns ?>; i++) {
                var typeEl = document.getElementById('types' + i);
                if (typeEl) {
                    checkLengths(typeEl.value, i);
                }
                handleDefaultPresetChange(i, false);
            }
        </script>
        <?php
    }
}
