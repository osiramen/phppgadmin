# copilot-instructions for phppgadmin

Concise guidance for AI coding assistants working on phpPgAdmin. Keep changes small and match existing patterns.

**Big picture**

-   Procedural PHP app with light OOP helpers; each subject page (tables, views, roles, etc.) dispatches on `$_REQUEST['action']` and renders via `Misc` helpers.
-   Bootstrap: `libraries/lib.inc.php` loads config, language, sessions, theme selection, and (unless `$_no_db_connection` is set) connects via `$misc->getDatabaseAccessor()`.
-   Globals drive everything: `$conf` (config), `$lang` (i18n), `$misc` (UI/session/db helper), `$data` (db accessor), `$plugin_manager` (hooks).

**Config & auth**

-   Edit `conf/config.inc.php` only above the "Don't modify anything below this line" marker; defines servers, themes, plugins, debug flags, long session lifetime, and login security.
-   Set `$_no_db_connection = true` on pre-auth pages (`index.php`, `servers.php`, `intro.php`, `login.php`) to skip DB connect + auth redirect.
-   Login state lives in session via `$misc->setServerInfo()`; extra login security rejects blank passwords and system usernames when enabled.

**Database layer**

-   Connection wrapper and database adapters live under `libraries/PhpPgAdmin/Database/` (see `Connector.php`, `AbstractConnection.php`, `Postgres.php`) and use ADODB where appropriate; the code auto-selects versioned drivers/adapters and falls back to a compatible adapter for unknown newer server versions.
-   Driver implementations (for example `libraries/PhpPgAdmin/Database/Postgres.php`) provide the `$data` accessor methods used across the app; use those methods instead of raw `pg_*` calls. Escape using existing helpers such as `clean()`, `fieldClean()`, and `arrayClean()`.
-   Switch schemas with `$data->setSchema()` when `schema` is provided; `$misc->getSubjectParams()` builds consistent param sets.

**Plugins**

-   Activate via `$conf['plugins']`; each plugin lives in `plugins/<Name>/plugin.php` with class `<Name>` implementing `get_name()`, `get_hooks()`, `get_actions()`.
-   Supported hooks: `head`, `toplinks`, `tabs`, `trail`, `navlinks`, `actionbuttons`, `tree`, `logout`. Core invocations live in `libraries/PhpPgAdmin/PluginManager.php` and `libraries/PhpPgAdmin/Misc.php` (tabs/navlinks/trail/actionbuttons/tree).
-   Hook methods mutate args by reference; actions must be declared in `get_actions()` or they are rejected.

**UI rendering**

-   Use `Misc` helpers: `printHeader/Body/Footer`, `printTabs()`, `printTrail()`, `printTable()`, `printMsg()`, `printTitle()`. Typical page: define small `doX()` functions, then switch on `$action`.
-   Theme resolution priority: request `theme` → session → cookie → server/db/user theme in `$conf` → default. Assets under `themes/<theme>/global.css`.
-   Language: always loads `lang/english.php`, then selected lang from `$conf['default_lang']` or browser (`auto`).

**Development / run / debug**

-   Requirements: PHP >= 7.2, `ext-pgsql`, `ext-mbstring`.
-   Install deps: `composer install` (vendor autoload pulled in by `libraries/lib.inc.php`).
-   Dev server: `php -S localhost:8080 -t .` from repo root; production should use a real web server.
-   Debug toggles live in `conf/config.inc.php` (display_errors, xdebug limits, session lifetime).

**Testing**

-   `tests/` exists but no runner in `composer.json`; add small procedural tests for helpers in `libraries/` if needed.

**Safety notes**

-   Respect `$conf['extra_login_security']`; avoid weakening without matching `pg_hba.conf`.
-   Prefer existing escaping (`Postgres::clean/fieldClean/arrayClean` or helpers in `libraries/helper.inc.php`) over ad-hoc quoting.

Need more examples (e.g., hook invocation spots or a minimal plugin skeleton)? Ask and we’ll add them.
