<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\PartitionActions;
use PhpPgAdmin\Database\Actions\TableActions;

/**
 * Manage partitions of a partitioned table
 *
 * Part of phpPgAdmin partition support (PostgreSQL 10+)
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Show form to create a new partition
 */
function doCreate($msg = '')
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $partitionActions = new PartitionActions($pg);

    $misc->printTrail('table');
    $misc->printTitle($lang['strcreatepartition'], 'CreatePartition');
    $misc->printMsg($msg);

    // Get partition info
    $partInfo = $partitionActions->getPartitionInfo($_REQUEST['table']);

    if (!is_object($partInfo) || $partInfo->recordCount() == 0) {
        echo "<p>{$lang['strinvalidparam']}</p>\n";
        return;
    }

    $strategy = $partInfo->fields['partstrat'];
    $partition_keys = $partInfo->fields['partition_keys'];

    // Convert PostgreSQL array format to PHP array
    $partition_keys = trim($partition_keys, '{}');
    $key_columns = explode(',', $partition_keys);

    // Map strategy code to name
    $strategy_name = PartitionActions::PARTITION_STRATEGY_MAP[$strategy] ?? $strategy;

    ?>
    <form action="partitions.php" method="post">
        <table>
            <tr>
                <th class="data left"><?= $lang['strpartitionedby']; ?></th>
                <td class="data">
                    <strong><?= htmlspecialchars($strategy_name); ?></strong>
                    (<?= htmlspecialchars(implode(', ', $key_columns)); ?>)
                </td>
            </tr>
            <tr>
                <th class="data left required"><?= $lang['strname']; ?></th>
                <td class="data"><input name="partition_name" size="32" maxlength="<?= $pg->_maxNameLen; ?>" /></td>
            </tr>
            <?php if ($strategy == 'r'): /* RANGE */ ?>
                <tr>
                    <th class="data left required"><?= $lang['strpartitionfrom']; ?></th>
                    <td class="data"><input name="from_value" size="32" placeholder="'2024-01-01'" /></td>
                </tr>
                <tr>
                    <th class="data left required"><?= $lang['strpartitionto']; ?></th>
                    <td class="data"><input name="to_value" size="32" placeholder="'2024-02-01'" /></td>
                </tr>
                <tr>
                    <td colspan="2" class="data">
                        <small><?= $lang['strexample']; ?>: FROM ('2024-01-01') TO ('2024-02-01')</small>
                    </td>
                </tr>
            <?php elseif ($strategy == 'l'): /* LIST */ ?>
                <tr>
                    <th class="data left required"><?= $lang['strpartitionvalues']; ?></th>
                    <td class="data"><textarea name="list_values" rows="3" cols="32"
                            placeholder="'USA','Canada','Mexico'"></textarea></td>
                </tr>
                <tr>
                    <td colspan="2" class="data">
                        <small><?= $lang['example']; ?>: IN ('USA', 'Canada', 'Mexico')</small>
                    </td>
                </tr>
            <?php elseif ($strategy == 'h'): /* HASH */ ?>
                <tr>
                    <th class="data left required"><?= $lang['strpartitionmodulus']; ?></th>
                    <td class="data"><input name="modulus" type="number" min="1" size="10" /></td>
                </tr>
                <tr>
                    <th class="data left required"><?= $lang['strpartitionremainder']; ?></th>
                    <td class="data"><input name="remainder" type="number" min="0" size="10" /></td>
                </tr>
                <tr>
                    <td colspan="2" class="data">
                        <small><?= $lang['example']; ?>: MODULUS 4, REMAINDER 0</small>
                    </td>
                </tr>
            <?php endif ?>
            <?php

            // Default partition option (PG11+)
            if ($pg->major_version >= 11) {
                ?>
                <tr>
                    <th class="data left">&nbsp;</th>
                    <td class="data"><label><input type="checkbox" name="is_default" id="is_default" />
                            <?= $lang['strdefaultpartition']; ?></label></td>
                </tr>
                <?php
            }
            ?>
        </table>
        <input type="hidden" name="action" value="save_create" />
        <input type="hidden" name="strategy" value="<?= htmlspecialchars($strategy); ?>" />
        <?= $misc->form; ?>
        <input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']); ?>" />
        <p>
            <input type="submit" name="save" value="<?= $lang['strcreate']; ?>" />
            <input type="submit" name="cancel" value="<?= $lang['strcancel']; ?>" />
        </p>
    </form>
    <?php
}

function doSaveCreate()
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $partitionActions = new PartitionActions($pg);

    // Prepare partition values based on strategy
    $values = [];
    $isDefault = isset($_POST['is_default']) && $_POST['is_default'];

    if (!$isDefault) {
        switch ($_POST['strategy']) {
            case 'r': // RANGE
                $values = [
                    'from' => $_POST['from_value'],
                    'to' => $_POST['to_value']
                ];
                break;

            case 'l': // LIST
                $values = [
                    'values' => $_POST['list_values']
                ];
                break;

            case 'h': // HASH
                $values = [
                    'modulus' => $_POST['modulus'],
                    'remainder' => $_POST['remainder']
                ];
                break;
        }
    }

    // Create the partition
    $status = $partitionActions->createPartition(
        $_REQUEST['table'],
        $_POST['partition_name'],
        $_POST['strategy'],
        $values,
        $isDefault
    );

    if ($status == 0) {
        AppContainer::setShouldReloadTree(true);
        doDefault($lang['strpartitioncreated']);
    } else {
        doCreate($lang['strpartitioncreatedbad']);
    }
}

/**
 * Show confirmation and detach a partition
 */
function doDetach($confirm)
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();

    if (!$confirm) {
        $f_schema = $pg->_schema;
        $pg->fieldClean($f_schema);
        $pg->fieldClean($_REQUEST['table']);
        $pg->fieldClean($_POST['partition']);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$_REQUEST['table']}\" DETACH PARTITION \"{$f_schema}\".\"{$_POST['partition']}\"";

        if (isset($_POST['finalize']) && $_POST['finalize']) {
            $sql .= " FINALIZE";
        } elseif (isset($_POST['cascade']) && $_POST['cascade']) {
            $sql .= " CASCADE";
        }

        $status = $pg->execute($sql);

        if ($status == 0) {
            AppContainer::setShouldReloadTree(true);
            doDefault($lang['strpartitiondetached']);
        } else {
            doDefault($lang['strpartitiondetachedbad']);
        }
    }

    $misc->printTrail('table');
    $misc->printTitle($lang['strdetachpartition'], 'pg.partition.detach');

    ?>
    <p><?= sprintf($lang['strconfdetachpartition'], $_REQUEST['partition']); ?></p>

    <form action="partitions.php" method="post">
        <p><input type="checkbox" id="cascade" name="cascade" /> <label for="cascade"><?= $lang['strcascade']; ?></label>
        </p>

        <?php
        // DETACH ... FINALIZE option for PG14+
        if ($pg->major_version >= 14) {
            ?>
            <p>
                <input type="checkbox" id="finalize" name="finalize" /> <label
                    for="finalize"><?= $lang['strpartitionfinalize']; ?></label>
                <a href="#" onclick="return false;" title="<?= $lang['strpartitiondetachhelp']; ?>">ⓘ</a>
            </p>
            <?php
        }
        ?>

        <input type="hidden" name="action" value="detach" />
        <?= $misc->form; ?>
        <input type="hidden" name="table" value="<?= htmlspecialchars($_REQUEST['table']); ?>" />
        <input type="hidden" name="partition" value="<?= htmlspecialchars($_REQUEST['partition']); ?>" />
        <input type="submit" name="detach" value="<?= $lang['strdetachpartition']; ?>" />
        <input type="submit" name="cancel" value="<?= $lang['strcancel']; ?>" />
    </form>
    <?php
}

/**
 * Analyze all partitions
 */
function doAnalyzeAll()
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $partitionActions = new PartitionActions($pg);

    $status = $partitionActions->analyzeAllPartitions($_REQUEST['table']);

    if ($status == 0) {
        doDefault($lang['stranalyzed']);
    } else {
        doDefault($lang['stranalyzebad']);
    }
}

/**
 * Test query pruning - shows which partitions would be accessed by a query
 */
function doTestPruning($msg = '')
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $partitionActions = new PartitionActions($pg);

    $misc->printTrail('table');
    $misc->printTitle($lang['strtestquerypruning'], 'pg.partition');
    $misc->printMsg($msg);

    // Get partition count
    $partitions = $partitionActions->getPartitions($_REQUEST['table']);
    $partition_count = 0;
    if (is_object($partitions)) {
        while (!$partitions->EOF) {
            $partition_count++;
            $partitions->moveNext();
        }
    }

    if (!isset($_POST['query'])) {
        $_POST['query'] = "SELECT * FROM {$_REQUEST['table']} WHERE ";
    }

    ?>
    <p><?= $lang['strpartitionpruningtest']; ?></p>

    <form action="partitions.php" method="post">
        <table>
            <tr>
                <th class="data left required"><?= $lang['strsql']; ?></th>
                <td class="data">
                    <textarea name="query" rows="8" cols="80"
                        style="font-family: monospace;"><?= htmlspecialchars($_POST['query']); ?></textarea>
                </td>
            </tr>
        </table>

        <input type="hidden" name="action" value="test_pruning_execute" />
        <?= $misc->form; ?>
        <p>
            <input type="submit" name="execute" value="<?= $lang['strtest']; ?>" />
            <input type="submit" name="cancel" value="<?= $lang['strback']; ?>" />
        </p>
    </form>
    <?php

    /**
     * Parse EXPLAIN JSON output to extract partition names
     * @param array $plan The EXPLAIN plan (decoded JSON)
     * @param array &$partitions Array to collect partition names
     */
    function parseExplainForPartitions($plan, &$partitions)
    {
        if (!is_array($plan)) {
            return;
        }

        // Check for 'Relation Name' field (indicates table access)
        if (isset($plan['Relation Name'])) {
            $relation = $plan['Relation Name'];
            // Only add if it looks like a partition (not the parent table)
            if (!in_array($relation, $partitions)) {
                $partitions[] = $relation;
            }
        }

        // Recursively search in Plans array
        if (isset($plan['Plans']) && is_array($plan['Plans'])) {
            foreach ($plan['Plans'] as $subplan) {
                parseExplainForPartitions($subplan, $partitions);
            }
        }

        // Also check Plan key (some formats)
        if (isset($plan['Plan'])) {
            parseExplainForPartitions($plan['Plan'], $partitions);
        }

        // Handle array of plans at top level
        if (isset($plan[0]) && is_array($plan[0])) {
            foreach ($plan as $item) {
                parseExplainForPartitions($item, $partitions);
            }
        }
    }


    // Show results if query was submitted
    if (!isset($_POST['execute']) || empty($_POST['query'])) {
        return;
    }
    
    $query = $_POST['query'];

    // Run EXPLAIN to see partition pruning
    $explain_sql = "EXPLAIN (FORMAT JSON) " . $query;
    $result = $pg->selectSet($explain_sql);

    if (is_object($result) && !$result->EOF) {
        $json = $result->fields['QUERY PLAN'];
        $plan = json_decode($json, true);

        // Parse plan to find accessed partitions
        $accessed_partitions = [];
        parseExplainForPartitions($plan, $accessed_partitions);

        $accessed_count = count($accessed_partitions);

        echo "<h3>{$lang['strresults']}</h3>\n";
        echo "<div class=\"partition-pruning-result\">\n";

        // Show pruning effectiveness
        if ($accessed_count == 0) {
            echo "<p class=\"info\">{$lang['strpartitionpruningnone']}</p>\n";
        } else {
            $ratio = $accessed_count / max($partition_count, 1);

            if ($ratio <= 0.2) {
                $badge = 'success';
                $message = sprintf($lang['strpartitionpruningexcellent'], $accessed_count, $partition_count);
            } elseif ($ratio <= 0.5) {
                $badge = 'info';
                $message = sprintf($lang['strpartitionpruninggood'], $accessed_count, $partition_count);
            } elseif ($ratio <= 0.8) {
                $badge = 'warning';
                $message = sprintf($lang['strpartitionpruningpoor'], $accessed_count, $partition_count);
            } else {
                $badge = 'danger';
                $message = sprintf($lang['strpartitionpruningminimal'], $accessed_count, $partition_count);
            }

            echo "<p class=\"badge badge-{$badge}\">{$message}</p>\n";

            if ($accessed_count > 0) {
                echo "<h4>{$lang['strpartitionsaccessed']}:</h4>\n";
                echo "<ul>\n";
                foreach ($accessed_partitions as $partition) {
                    echo "<li>", htmlspecialchars($partition), "</li>\n";
                }
                echo "</ul>\n";
            }
        }

        // Show full EXPLAIN output
        echo "<details>\n";
        echo "<summary>{$lang['strfullexplain']}</summary>\n";
        echo "<pre>", htmlspecialchars(json_encode($plan, JSON_PRETTY_PRINT)), "</pre>\n";
        echo "</details>\n";

        echo "</div>\n";
    } else {
        echo "<p class=\"error\">{$lang['strqueryfailed']}</p>\n";
    }
}

/**
 * Bulk partition creation wizard
 */
function doBulkCreate($msg = '')
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $partitionActions = new PartitionActions($pg);

    $misc->printTrail('table');
    $misc->printTitle($lang['strpartitiontemplate'], 'CreatePartition');
    $misc->printMsg($msg);

    // Get partition info
    $partInfo = $partitionActions->getPartitionInfo($_REQUEST['table']);

    if (!is_object($partInfo) || $partInfo->recordCount() == 0) {
        echo "<p>{$lang['strinvalidparam']}</p>\n";
        return;
    }

    $strategy = $partInfo->fields['partstrat'];

    // Only support RANGE strategy for now
    if ($strategy !== 'r') {
        echo "<p>{$lang['strpartitiontemplateonlyrange']}</p>\n";
        echo "<p><a href=\"partitions.php?{$misc->href}&amp;table=", urlencode($_REQUEST['table']), "\">{$lang['strback']}</a></p>\n";
        return;
    }

    ?>
    <p><?= $lang['strpartitiontemplatedesc']; ?></p>

    <form action="partitions.php" method="post">
        <table>
            <tr>
                <th class="data left required"><?= $lang['strpartitiontemplatetype']; ?></th>
                <td class="data">
                    <select name="template_type" id="template_type" onchange="updateTemplateForm()">
                        <option value="monthly"><?= $lang['strpartitiontemplatemonthly']; ?></option>
                        <option value="daily"><?= $lang['strpartitiontemplatedaily']; ?></option>
                        <option value="yearly"><?= $lang['strpartitiontemplateyearly']; ?></option>
                        <option value="custom"><?= $lang['strpartitiontemplatecustom']; ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th class="data left required"><?= $lang['strpartitionnamepattern']; ?></th>
                <td class="data">
                    <input name="name_pattern" size="40" value="<?= $_REQUEST['table']; ?>_{{year}}_{{month}}"
                        placeholder="<?= $_REQUEST['table']; ?>_{{year}}_{{month}}" />
                    <br /><small><?= $lang['strpartitionnamepatternhint']; ?></small>
                </td>
            </tr>
            <tr>
                <th class="data left required"><?= $lang['strstartdate']; ?></th>
                <td class="data"><input name="start_date" type="date" value="<?= date('Y-m-01'); ?>" /></td>
            </tr>
            <tr>
                <th class="data left required"><?= $lang['strenddate']; ?></th>
                <td class="data"><input name="end_date" type="date"
                        value="<?= date('Y-m-t', strtotime('+11 months')); ?>" /></td>
            </tr>
            <tr>
                <td colspan="2" class="data">
                    <button type="button" onclick="previewPartitions()"><?= $lang['strpreview']; ?></button>
                    <div id="preview_area" style="margin-top: 10px; max-height: 300px; overflow-y: auto; display: none;">
                    </div>
                </td>
            </tr>
        </table>

        <input type="hidden" name="action" value="save_bulk_create" />
        <?= $misc->form; ?>
        <p>
            <input type="submit" name="save" value="<?= $lang['strcreateall']; ?>" />
            <input type="submit" name="cancel" value="<?= $lang['strcancel']; ?>" />
        </p>
    </form>

    <script>
        function previewPartitions() {
            var type = document.getElementById('template_type').value;
            var pattern = document.querySelector('input[name="name_pattern"]').value;
            var start = new Date(document.querySelector('input[name="start_date"]').value);
            var end = new Date(document.querySelector('input[name="end_date"]').value);
            var preview = document.getElementById('preview_area');
            var html = '<strong><?= $lang['strpartitionswillbecreated'] ?>:</strong><ul>';
            var current = new Date(start);
            var count = 0;
            var maxPreview = 50;
            while (current <= end && count < maxPreview) {
                var name = pattern.replace('{{year}}', current.getFullYear())
                    .replace('{{month}}', String(current.getMonth() + 1).padStart(2, '0'))
                    .replace('{{day}}', String(current.getDate()).padStart(2, '0'));
                var from = current.toISOString().split('T')[0];
                var next = new Date(current);
                if (type === 'monthly') next.setMonth(next.getMonth() + 1);
                else if (type === 'daily') next.setDate(next.getDate() + 1);
                else if (type === 'yearly') next.setFullYear(next.getFullYear() + 1);
                var to = next.toISOString().split('T')[0];
                html += '<li>' + name + ' <small>FROM (' + from + ') TO (' + to + ')</small></li>';
                current = next;
                count++;
            }
            if (current <= end) html += '<li><em>... and ' + Math.ceil((end - current) / (1000 * 60 * 60 * 24 * 30)) + ' more</em></li>';
            html += '</ul><p><strong><?= $lang['strtotal'] ?>:</strong> ' + count + ' <?= $lang['strpartitions'] ?></p>';
            preview.innerHTML = html;
            preview.style.display = 'block';
        }
    </script>
    <?php
}

function doSaveBulkCreate()
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $partitionActions = new PartitionActions($pg);

    // Execute bulk creation
    if (!isset($_POST['template_type']) || !isset($_POST['start_date']) || !isset($_POST['end_date'])) {
        doBulkCreate($lang['strinvalidparam']);
        return;
    }

    $template_type = $_POST['template_type'];
    $name_pattern = $_POST['name_pattern'];
    $start_date = new DateTime($_POST['start_date']);
    $end_date = new DateTime($_POST['end_date']);

    $f_schema = $pg->_schema;
    $pg->fieldClean($f_schema);
    $pg->fieldClean($_REQUEST['table']);

    $created = 0;
    $failed = 0;
    $errors = [];

    $current = clone $start_date;
    while ($current <= $end_date) {
        // Generate partition name
        $name = str_replace(
            ['{{year}}', '{{month}}', '{{day}}'],
            [$current->format('Y'), $current->format('m'), $current->format('d')],
            $name_pattern
        );

        $pg->fieldClean($name);

        // Calculate bounds
        $from = $current->format('Y-m-d');
        $next = clone $current;

        switch ($template_type) {
            case 'daily':
                $next->modify('+1 day');
                break;
            case 'monthly':
                $next->modify('+1 month');
                break;
            case 'yearly':
                $next->modify('+1 year');
                break;
        }

        $to = $next->format('Y-m-d');

        // Create partition
        $status = $partitionActions->createPartition(
            $_REQUEST['table'],
            $name,
            'r',
            ['from' => "'{$from}'", 'to' => "'{$to}'"],
            false
        );

        if ($status == 0) {
            $created++;
        } else {
            $failed++;
            $errors[] = "{$name}: {$pg->getLastError()}";
        }

        $current = $next;
    }

    if ($created > 0)
        AppContainer::setShouldReloadTree(true);

    if ($failed == 0) {
        doDefault(sprintf($lang['strpartitionbulkcreated'], $created));
    } else {
        $error_msg = sprintf($lang['strpartitionbulkpartial'], $created, $failed) . "<br/>" . implode("<br/>", array_slice($errors, 0, 5));
        if (count($errors) > 5) {
            $error_msg .= "<br/><em>... " . (count($errors) - 5) . " more errors</em>";
        }
        doDefault($error_msg);
    }
}

/**
 * Display list of partitions for a partitioned table
 */
function doDefault($msg = '')
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $partitionActions = new PartitionActions($pg);

    $misc->printTrail('table');
    $misc->printTabs('table', 'partitions');
    $misc->printTitle($lang['strpartitions'], 'pg.partition');
    $misc->printMsg($msg);

    if ($pg->major_version < 10) {
        echo "<p>{$lang['strpartitionnotsupported']}</p>\n";
        return;
    }

    // Get partition pruning status
    $pruning = $partitionActions->getPartitionPruningEnabled();
    $pruning_badge = $pruning['enabled']
        ? "<span class=\"badge badge-success\">{$lang['strpartitionpruningenabled']}</span>"
        : "<span class=\"badge badge-warning\">{$lang['strpartitionpruningdisabled']}</span>";

    // Get total rows
    $total_rows = $partitionActions->getTotalPartitionRows($_REQUEST['table']);

    ?>
    <div class="partition-summary">
        <p><strong><?= $lang['strpartitionpruning']; ?>:</strong> <?= $pruning_badge; ?>
            (<?= $pruning['setting']; ?>)</p>
        <p><strong><?= $lang['strtotalpartitionrows']; ?>:</strong> <?= number_format($total_rows); ?></p>
        <p>
            <a href="partitions.php?action=analyze_all&amp;<?= $misc->href; ?>&amp;table=<?= urlencode($_REQUEST['table']); ?>"
                class="btn"><?= $lang['stranalyzeallpartitions']; ?></a>
            <a href="partitions.php?action=test_pruning&amp;<?= $misc->href; ?>&amp;table=<?= urlencode($_REQUEST['table']); ?>"
                class="btn"><?= $lang['strtestquerypruning']; ?></a>
            <a href="partitions.php?action=bulk_create&amp;<?= $misc->href; ?>&amp;table=<?= urlencode($_REQUEST['table']); ?>"
                class="btn"><?= $lang['strpartitiontemplate']; ?></a>
        </p>
    </div>
    <?php

    // Get partitions
    $partitions = $partitionActions->getPartitions($_REQUEST['table']);

    // Calculate average size for warning detection
    $total_size = 0;
    $count = 0;

    if (is_object($partitions) && !$partitions->EOF) {
        while (!$partitions->EOF) {
            if ($partitions->fields['partition_bound'] !== null) {
                $total_size += $partitions->fields['size'];
                $count++;
            }
            $partitions->moveNext();
        }
    }
    $avg_size = $count > 0 ? $total_size / $count : 0;

    // Reset recordset pointer
    $partitions->moveFirst();

    // Pre-processing callback to add computed fields
    $partPre = function (&$rowdata) use ($misc, $lang, $avg_size) {
        $is_default = ($rowdata->fields['partition_bound'] === null || $rowdata->fields['partition_bound'] === '');
        $is_partitioned = ($rowdata->fields['relkind'] === 'p');

        // Set icon
        if ($is_default) {
            $rowdata->fields['icon'] = $misc->icon('DefaultPartition');
        } elseif ($is_partitioned) {
            $rowdata->fields['icon'] = $misc->icon('PartitionedTable');
        } else {
            $rowdata->fields['icon'] = $misc->icon('Partition');
        }

        // Format bounds field with badge for default partition
        if ($is_default) {
            $rowdata->fields['+bounds'] = "<span class=\"badge badge-default\">{$lang['strdefaultpartition']}</span>";
        } else {
            $rowdata->fields['+bounds'] = $rowdata->fields['partition_bound'];
        }

        // Format size with warning if oversized
        $size_formatted = $misc->formatVal($rowdata->fields['size'], 'prettysize');
        if (!$is_default && $avg_size > 0 && $rowdata->fields['size'] > ($avg_size * 2)) {
            $size_formatted .= " <span class=\"warning\" title=\"{$lang['strpartitionsizewarn']}\">⚠</span>";
        }
        $rowdata->fields['+size'] = $size_formatted;

        // Store flags for conditional actions
        $rowdata->fields['+is_partitioned'] = $is_partitioned;
    };

    // Define columns
    $columns = [
        'partition' => [
            'title' => $lang['strname'],
            'field' => field('relname'),
            'icon' => field('icon'),
            'url' => "display.php?subject=table&amp;{$misc->href}&amp;",
            'vars' => ['table' => 'relname'],
        ],
        'bounds' => [
            'title' => $lang['strpartitionbounds'],
            'field' => field('+bounds'),
            'type' => 'sql',
            'params' => [
                'tag' => 'div',
            ],
        ],
        'rows' => [
            'title' => $lang['strrows'],
            'field' => field('reltuples'),
            'type' => 'numeric',
        ],
        'size' => [
            'title' => $lang['strsize'],
            'field' => field('+size'),
            'type' => 'html',
        ],
        'actions' => [
            'title' => $lang['stractions'],
        ],
        'comment' => [
            'title' => $lang['strcomment'],
            'field' => field('comment'),
        ],
    ];

    // Define actions
    $actions = [
        'browse' => [
            'icon' => $misc->icon('Table'),
            'content' => $lang['strbrowse'],
            'attr' => [
                'href' => [
                    'url' => 'display.php',
                    'urlvars' => [
                        'subject' => 'table',
                        'table' => field('relname'),
                    ],
                ],
            ],
        ],
        'manage_partitions' => [
            'icon' => $misc->icon('Partitions'),
            'content' => $lang['strpartitions'],
            'attr' => [
                'href' => [
                    'url' => 'partitions.php',
                    'urlvars' => [
                        'table' => field('relname'),
                    ],
                ],
            ],
        ],
        'detach' => [
            'icon' => $misc->icon('DetachPartition'),
            'content' => $lang['strdetach'],
            'attr' => [
                'href' => [
                    'url' => 'partitions.php',
                    'urlvars' => [
                        'action' => 'confirm_detach',
                        'table' => $_REQUEST['table'],
                        'partition' => field('relname'),
                    ],
                ],
            ],
        ],
        'drop' => [
            'icon' => $misc->icon('Delete'),
            'content' => $lang['strdrop'],
            'attr' => [
                'href' => [
                    'url' => 'tables.php',
                    'urlvars' => [
                        'action' => 'confirm_drop',
                        'table' => field('relname'),
                    ],
                ],
            ],
        ],
    ];

    // Hide "Manage Partitions" action for non-partitioned partitions
    $actions['manage_partitions']['disable'] = function ($row) {
        return !($row['+is_partitioned'] ?? false);
    };

    $misc->printTable(
        $partitions,
        $columns,
        $actions,
        'partitions-partitions',
        $lang['strnopartitions'],
        $partPre
    );

    // Navigation links
    $navlinks = [
        'createpartition' => [
            'attr' => [
                'href' => [
                    'url' => 'partitions.php',
                    'urlvars' => [
                        'action' => 'create',
                        'server' => $_REQUEST['server'],
                        'database' => $_REQUEST['database'],
                        'schema' => $_REQUEST['schema'],
                        'table' => $_REQUEST['table']
                    ]
                ]
            ],
            'icon' => $misc->icon('CreatePartition'),
            'content' => $lang['strcreatepartition']
        ],
        'attachpartition' => [
            'attr' => [
                'href' => [
                    'url' => 'partitions.php',
                    'urlvars' => [
                        'action' => 'attach',
                        'server' => $_REQUEST['server'],
                        'database' => $_REQUEST['database'],
                        'schema' => $_REQUEST['schema'],
                        'table' => $_REQUEST['table']
                    ]
                ]
            ],
            'icon' => $misc->icon('AttachPartition'),
            'content' => $lang['strattachpartition']
        ]
    ];

    $misc->printNavLinks($navlinks, 'partitions-partitions', get_defined_vars());
}

/**
 * Generate XML for the browser tree
 */
function doTree()
{
    $misc = AppContainer::getMisc();
    $pg = AppContainer::getPostgres();
    $partitionActions = new PartitionActions($pg);

    $partitions = $partitionActions->getPartitions($_REQUEST['table']);

    $reqvars = $misc->getRequestVars('table');

    $getIcon = function ($f) use ($pg) {
        // Check if default partition
        if ($f['partition_bound'] === null || $f['partition_bound'] === '') {
            return 'DefaultPartition';
        }
        // Check if sub-partitioned
        if ($f['relkind'] === 'p') {
            return 'PartitionedTable';
        }
        return 'Partition';
    };

    $attrs = [
        'text' => field('relname'),
        'icon' => callback($getIcon),
        'toolTip' => field('comment'),
        'action' => url(
            'redirect.php',
            $reqvars,
            ['subject' => 'table', 'table' => field('relname')]
        )
    ];

    // Add branch for sub-partitioned partitions
    if ($pg->major_version >= 10) {
        $attrs['branch'] = ifempty(
            field('relkind'),
            '',
            url(
                'partitions.php',
                $reqvars,
                [
                    'action' => 'subtree',
                    'table' => field('relname')
                ]
            )
        );
    }

    $misc->printTree($partitions, $attrs, 'partitions');
    exit;
}

function doSubTree()
{
    $misc = AppContainer::getMisc();
    $pg = AppContainer::getPostgres();

    $tabs = $misc->getNavTabs('table');
    $partitionActions = new PartitionActions($pg);
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

$lang = AppContainer::getLang();
$misc = AppContainer::getMisc();

$action = $_REQUEST['action'] ?? '';

if ($action == 'tree') {
    doTree();
}
if ($action == 'subtree') {
    doSubTree();
}

$misc->printHeader($lang['strpartitions']);
$misc->printBody();

switch ($action) {
    case 'create':
        doCreate();
        break;
    case 'save_create':
        if (isset($_POST['cancel'])) {
            doDefault();
        } else {
            doSaveCreate();
        }
        break;
    case 'confirm_detach':
        doDetach(true);
        break;
    case 'detach':
        if (isset($_POST['cancel'])) {
            doDefault();
        } else {
            doDetach(false);
        }
        break;
    case 'analyze_all':
        doAnalyzeAll();
        break;
    case 'test_pruning':
        doTestPruning();
        break;
    case 'test_pruning_execute':
        if (isset($_POST['cancel'])) {
            doDefault();
        } else {
            doTestPruning();
        }
        break;
    case 'bulk_create':
        doBulkCreate();
        break;
    case 'save_bulk_create':
        if (isset($_POST['cancel'])) {
            doDefault();
        } else {
            doSaveBulkCreate();
        }
        break;
    default:
        doDefault();
        break;
}

$misc->printFooter();
