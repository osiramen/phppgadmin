<?php

use PhpPgAdmin\Core\AppContainer;

/**
 * Transforms raw binary data into PostgreSQL COPY-compatible octal escapes.
 *
 * Example:
 *   "\xDE\xAD\xBE\xEF" → "\\336\\255\\276\\357"
 *
 * COPY expects exactly this format.
 */
function bytea_to_octal(string $data): string
{
	if ($data === '') {
		return '';
	}

	static $map = null;
	if ($map === null) {
		$map = [];
		for ($i = 0; $i < 256; $i++) {
			if ($i >= 32 && $i <= 126) {
				if ($i === 92) {
					// backslash
					$map["\\"] = '\\\\';
				} else {
					// printable except backslash
					$map[chr($i)] = chr($i);
				}
			} else {
				// non-printable
				$map[chr($i)] = sprintf("\\%03o", $i);
			}
		}
	}

	return strtr($data, $map);
}

function pg_unquote_identifier(string $ident): string
{
	// remove surrounding quotes
	$len = strlen($ident);
	if ($len >= 2 && $ident[0] === '"' && $ident[$len - 1] === '"') {
		$ident = substr($ident, 1, $len - 2);
		// replace double quotes with single quotes
		$ident = str_replace('""', '"', $ident);
	}
	return $ident;
}

/**
 * Escape a string for use as a PostgreSQL identifier (e.g., table or column name)
 * @param string $id
 * @return string
 */
function pg_escape_id($id = ''): string
{
	$pg = AppContainer::getPostgres();
	return pg_escape_identifier($pg->conn->_connectionID, $id);
}

/**
 * HTML-escape a string
 * @param string|null $string
 * @param int $flags
 * @param string $encoding
 * @param bool $double_encode
 * @return string
 */
function html_esc(
	$string,
	$flags = ENT_QUOTES | ENT_SUBSTITUTE,
	$encoding = 'UTF-8',
	$double_encode = true
): string {
	if ($string === null) {
		return '';
	}
	return htmlspecialchars($string, $flags, $encoding, $double_encode);
}

/**
 * Format a string according to a template and values from an array or object
 * Field names in the template are enclosed in {}, i.e., {name} reads $data['name'] or $data->{'name'}
 * To the right of the field name, a sprintf-like format string can be defined, starting with :
 * Example: {amount:06.2f} formats $data['amount'] as a decimal number in the format 0000.00
 * ? can be used to specify an optional default value, which can also be empty if the field is not set
 * Example: Hello {person}, you have {currency?$} {amount:0.2f} credit
 * Format: '{' name [':' fmt] ['?' [default]] '}'
 * @param string $template
 * @param array|object $data
 * @return string
 */
function format_string($template, $data)
{
	$isObject = is_object($data);
	$pattern = '/(?<left>[^{]*)\{(?<name>\w+)(:(?<pad>\'.|0| )?(?<justify>-)?(?<minlen>\d+)?(\.(?<prec>\d+))?(?<type>[a-zA-Z]))?(?<optional>\?.*)?\}(?<right>.*)/';
	while (preg_match($pattern, $template, $match)) {
		$fieldName = $match['name'];
		$fieldExists = $isObject ? isset($data->{$fieldName}) : isset($data[$fieldName]);
		if (!$fieldExists) {
			if (isset($match['optional'])) {
				$template = $match['left'] . substr($match['optional'], 1) . $match['right'];
				continue;
			} else {
				$template = $match['left'] . '[?' . $match['name'] . ']' . $match['right'];
				continue;
			}
		} else {
			$param = $isObject ? $data->{$fieldName} : $data[$fieldName];
		}
		if (strlen($padding = $match['pad'])) {
			if ($padding[0] == '\'') {
				$padding = $padding[1];
			}
		} else {
			$padding = ' ';
		}
		$precision = $match['prec'] ? intval($match['prec']) : null;
		switch ($match['type']) {
			case 'b':
				$subst = base_convert($param, 10, 2);
				break;
			case 'c':
				$subst = chr($param);
				break;
			case 'd':
				$subst = (string) (int) $param;
				break;
			case 'f':
			case 'F':
				if ($precision !== null) {
					$subst = number_format((float) $param, $precision);
				} else {
					$subst = (string) (float) $param;
				}
				break;
			case 'o':
				$subst = base_convert($param, 10, 8);
				break;
			case 'p':
				$subst = (string) (round((float) $param, $precision) * 100);
				break;
			case 's':
			default:
				$subst = (string) $param;
				break;
			case 'u':
				$subst = (string) abs((int) $param);
				break;
			case 'x':
				$subst = strtolower(base_convert($param, 10, 16));
				break;
			case 'X':
				$subst = base_convert($param, 10, 16);
				break;
		}
		$minLength = (int) $match['minlen'];
		if ($match['justify'] != '-') {
			// justify right
			if (strlen($subst) < $minLength) {
				$subst = str_repeat($padding, $minLength - strlen($subst)) . $subst;
			}
		} else {
			// justify left
			if (strlen($subst) < $minLength) {
				$subst .= str_repeat($padding, $minLength - strlen($subst));
			}
		}
		$template = $match['left'] . $subst . $match['right'];
	}
	return $template;
}

/**
 * SQL query extractor with multibyte string support
 * @param string $sql
 * @return string[]
 */
function extractSqlQueries($sql)
{
	$queries = [];
	$current = "";
	$inString = false;
	$stringChar = null;
	$inLineComment = false;
	$inBlockComment = false;

	$len = mb_strlen($sql);

	for ($i = 0; $i < $len; $i++) {
		$c = mb_substr($sql, $i, 1);
		$n = ($i + 1 < $len) ? mb_substr($sql, $i + 1, 1) : null;

		// Line comment --
		if (!$inString && !$inBlockComment && $c === "-" && $n === "-") {
			$inLineComment = true;
		}

		// End line comment
		if ($inLineComment && $c === "\n") {
			$inLineComment = false;
		}

		// Block comment /*
		if (!$inString && !$inLineComment && $c === "/" && $n === "*") {
			$inBlockComment = true;
		}

		// End block comment */
		if ($inBlockComment && $c === "*" && $n === "/") {
			$inBlockComment = false;
			$i++;
			continue;
		}

		// Strings '...' or "..."
		if (!$inLineComment && !$inBlockComment) {
			if (!$inString && ($c === "'" || $c === '"')) {
				$inString = true;
				$stringChar = $c;
			} elseif ($inString && $c === $stringChar) {
				$inString = false;
			}
		}

		// Semicolon ends query
		if (!$inString && !$inLineComment && !$inBlockComment && $c === ";") {
			$trimmed = trim($current);
			if ($trimmed !== "") {
				$queries[] = $trimmed;
			}
			$current = "";
			continue;
		}

		$current .= $c;
	}

	$trimmed = trim($current);
	if ($trimmed !== "") {
		$queries[] = $trimmed;
	}

	return $queries;
}

/**
 * Check if SQL contains only read-only queries
 * @param string $sql
 * @return bool
 */
function isSqlReadQuery($sql)
{
	$statements = extractSqlQueries($sql);
	if (count($statements) === 0) {
		return false;
	}

	foreach ($statements as $stmt) {
		$upper = strtoupper($stmt);

		if (strlen($upper) < 7) {
			return false;
		}

		$isRead = str_starts_with($upper, "SELECT") ||
			str_starts_with($upper, "WITH") ||
			str_starts_with($upper, "SET") ||
			str_starts_with($upper, "SHOW");

		if ($isRead) {
			continue;
		}

		$isRead = str_starts_with($upper, "EXPLAIN");
		if ($isRead) {
			$rest = trim(substr($upper, 7));
			$isRead = str_starts_with($rest, "SELECT") ||
				str_starts_with($rest, "WITH");
			if ($isRead) {
				continue;
			}
		}

		return false;
	}

	return true;
}

// ------------------------------------------------------------
// str_starts_with
// ------------------------------------------------------------
if (!function_exists('str_starts_with')) {
	function str_starts_with($haystack, $needle)
	{
		if ($needle === '') {
			return false;
		}
		return substr_compare($haystack, $needle, 0, strlen($needle)) === 0;
	}
}

// ------------------------------------------------------------
// str_ends_with
// ------------------------------------------------------------
if (!function_exists('str_ends_with')) {
	function str_ends_with($haystack, $needle)
	{
		if ($needle === '') {
			return false;
		}
		return substr_compare($haystack, $needle, -strlen($needle), strlen($needle)) === 0;
	}
}

// ------------------------------------------------------------
// str_contains
// ------------------------------------------------------------
if (!function_exists('str_contains')) {
	function str_contains($haystack, $needle)
	{
		if ($needle === '') {
			return false;
		}
		return strpos($haystack, $needle) !== false;
	}
}

// ------------------------------------------------------------
// fdiv — PHP 8 floating‑point division with INF/NaN behavior
// ------------------------------------------------------------
if (!function_exists('fdiv')) {
	function fdiv($dividend, $divisor)
	{
		// Match PHP 8 behavior exactly
		if ($divisor == 0) {
			if ($dividend == 0) {
				return NAN;
			}
			return ($dividend > 0 ? INF : -INF);
		}
		return $dividend / $divisor;
	}
}

// ------------------------------------------------------------
// get_debug_type — PHP 8 type inspection
// ------------------------------------------------------------
if (!function_exists('get_debug_type')) {
	function get_debug_type($value)
	{
		switch (true) {
			case is_null($value):
				return 'null';
			case is_bool($value):
				return 'bool';
			case is_int($value):
				return 'int';
			case is_float($value):
				return 'float';
			case is_string($value):
				return 'string';
			case is_array($value):
				return 'array';
			case is_object($value):
				return get_class($value);
			case is_resource($value):
				$type = get_resource_type($value);
				return $type === 'unknown type' ? 'resource' : "resource ($type)";
			default:
				return 'unknown';
		}
	}
}
