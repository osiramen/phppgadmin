<?php

/**
 * Intro screen
 *
 * $Id: intro.php,v 1.19 2007/07/12 19:26:22 xzilla Exp $
 */

// Include application functions (no db conn)
use PhpPgAdmin\Core\AppContainer;

$_ENV["SKIP_DB_CONNECTION"] = '1';
require_once './libraries/bootstrap.php';

[$appLangFiles, $availableLanguages] = require __DIR__ . '/lang/translations.php';
$appThemes = require __DIR__ . '/themes/themes.php';

$appName = AppContainer::getAppName();
$appVersion = AppContainer::getAppVersion();
$lang = AppContainer::getLang();
$misc = AppContainer::getMisc();
$conf = AppContainer::getConf();

$misc->printHeader();
$misc->printBody();

$misc->printTrail('root');
$misc->printTabs('root', 'intro');

?>

<h1><?php echo "$appName $appVersion (PHP " . phpversion() . ')' ?></h1>

<form method="get" action="intro.php">
	<table>
		<tr class="data1">
			<th class="data"><?php echo $lang['strlanguage'] ?></th>
			<td>
				<select name="language" onchange="this.form.submit()">
					<?php
					$language = $_SESSION['webdbLanguage'] ?? 'english';
					foreach ($appLangFiles as $k => $v) {
						echo "\t<option value=\"{$k}\"", ($k == $language) ? ' selected="selected"' : '',
							">{$v}</option>\n";
					}
					?>
				</select>
			</td>
		</tr>
		<tr class="data2">
			<th class="data"><?php echo $lang['strtheme'] ?></th>
			<td>
				<select name="theme" onchange="this.form.submit()">
					<?php
					foreach ($appThemes as $k => $v) {
						echo "\t<option value=\"{$k}\"", ($k == $conf['theme']) ? ' selected="selected"' : '',
							">{$v}</option>\n";
					}
					?>
				</select>
			</td>
		</tr>
	</table>
	<noscript>
		<p><input type="submit" value="<?php echo $lang['stralter'] ?>" /></p>
	</noscript>
</form>

<p><?php echo $lang['strintro'] ?></p>

<ul class="intro">
	<li><a href="https://github.com/phppgadmin/phppgadmin" target="_blank"><?php echo $lang['strppahome'] ?></a></li>
	<li><a href="<?php echo $lang['strpgsqlhome_url'] ?>"><?php echo $lang['strpgsqlhome'] ?></a></li>
	<li><a href="https://github.com/phppgadmin/phppgadmin/issues"
			target="_blank"><?php echo $lang['strreportbug'] ?></a></li>
	<li><a href="<?php echo $lang['strviewfaq_url'] ?>"><?php echo $lang['strviewfaq'] ?></a></li>
</ul>

<?php
if (isset($_GET['language']))
	AppContainer::setShouldReloadPage(true);
$misc->printFooter();
