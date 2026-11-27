# Architecture

## Runtime layout

`APPROOT` = directory of `exec.php` (the repository root). Every include is built from `APPROOT`.

```
exec.php                    entry point (CLI only)
core/                       framework classes (see "Map of core/" below)
conf/params.distrib.xml     framework defaults; do not edit for local needs
conf/params.local.xml       local overrides and credentials (git-ignored)
collectors/                 the concrete collector to run (git-ignored, NOT part of this repo)
  main.php                  mandatory: registers the collectors
  module.<name>.php         optional: project name and version ($version$)
  params.distrib.xml        collector defaults
  src/  json/               modern layout: classes / SDS definitions
  extensions/{src,json,params.distrib.xml}   customer overrides, looked up first
data/                       generated CSV files (`data_path`, supports %APPROOT%)
toolkit/                    dump_tasks.php (export an SDS as JSON), testconnection.php (REST check)
tests/                      same layout as iTop's tests/ folder
  ci_description.ini        how the CI factory tests this repository
  php-code-style/           PHP-CS-Fixer (own composer.json)
  php-unit-tests/           PHPUnit configurations, tests, fixtures and test tooling (see testing.md)
```

Lookup order:
- Collector class `<Class>.class.inc.php` (collection plans only): `collectors/extensions/src/`, then
  `collectors/src/`, then `collectors/`.
- SDS (Synchro Data Source) definition `<Class>.json`: `collectors/extensions/json/`, then `collectors/json/`, then `collectors/`.
- Configuration files: see [configuration.md](configuration.md).

## Execution flow (`exec.php`)

1. Parse CLI options: `--configure_only`, `--collect_only`, `--synchro_only`, `--dump_config_only` (print the merged
   configuration and exit), `--console_log_level=`, `--eventissue_log_level=`, `--max_chunk_size=`, `--config_file=`,
   `--help`. An unknown option prints the usage and exits with 1.
2. Require `collectors/main.php`, which registers collectors with either:
   - `Orchestrator::AddCollector($rank, $class)` (legacy), or
   - `Orchestrator::UseCollectionPlan($planClass)` (since 1.3.0). The plan reads `collectors_launch_sequence`, keeps
     the `enable=yes` entries sorted by `rank`, requires their class files, instantiates and `Init()`s each one,
     and registers those whose `CheckToLaunch()` returns true.
3. `Orchestrator::CheckRequirements()` checks the PHP and extension minimum versions declared with `AddRequirement()`.
4. `ListCollectors()` instantiates the collectors in rank order and calls `Init()`. `Init()` reads the SDS JSON into
   `$aFields` (`attcode` → class, update, reconcile, columns) and the collector's configuration section.
5. **Configure** (`InitSynchroDataSources`, skipped by `--collect_only`): resolves `$contact_to_notify$` and
   `$synchro_user$` through REST, adds `json_placeholders`. Then each `InitSynchroDataSource()` validates its
   definition and creates, or compares (`DataSourcesAreEquivalent`) and updates, the SDS and its attributes in iTop.
6. **Collect** (skipped by `--configure_only` and `--synchro_only`): `Collect()` calls `Prepare()`, then `Fetch()`
   until it returns a falsy value. Rows go to `data/<Class>-<n>.csv`, or to `data/<Class>.raw-<n>.csv` when
   `MustProcessBeforeSynchro()` is true. A new file starts every `max_chunk_size` rows.
7. **Synchronize** (skipped by `--configure_only` and `--collect_only`): optional `DoProcessBeforeSynchro()` turns
   raw files into final CSV through `ProcessLineBeforeSynchro()`; throwing `IgnoredRowException` drops the row. Each
   CSV is then POSTed to `synchro_import.php` (`synchronize=0`), and `synchro_exec.php` runs the synchronization.
8. Configure and Collect stop at the first collector that returns false. Synchronize stops only if
   `stop_on_synchro_error=yes`. Exit code: 0 on success, 1 otherwise.

## Map of `core/`

| File | Responsibility |
|---|---|
| `collector.class.inc.php` | `Collector` (abstract): SDS JSON loading, CSV writing, SDS create/update/compare, upload and synchro, parsing of iTop's output. Also `IgnoredRowException`, `InvalidConfigException`, `NULL_VALUE` (`<NULL>`). |
| `sqlcollector.class.inc.php` | `SQLCollector` (PDO; `<class>_query`; `sql_*` settings incl. TLS) and `MySQLCollector` (adds `SET NAMES 'utf8'`). |
| `csvcollector.class.inc.php` | `CSVCollector`: CSV file source (`csv_file`, `separator`, `encoding`, `has_header`, `fields`, `defaults`, `ignored_columns`, `command`). |
| `jsoncollector.class.inc.php` | `JsonCollector`: JSON from `jsonfile`, or `jsonurl` + `jsonpost`; `path` (supports `*`); `fields` (paths with `/`, `*`, numeric indexes); `defaults`; `command`. |
| `orchestrator.class.inc.php` | Collector registry, requirements, runs the three phases. |
| `collectionplan.class.inc.php` | `CollectionPlan` (abstract, since 1.3.0): launch sequence, class file discovery, `GetPlan()` singleton. |
| `utils.class.inc.php` | `Utils` (static): configuration, CLI parameters, logging (console, syslog, iTop `EventIssue`), HTTP POST (curl or streams), `Exec`, curl options, iTop module detection. Also `UtilsLogger` (mock target). |
| `parameters.class.inc.php` | XML configuration parsing, merging and dumping. |
| `restclient.class.inc.php` | iTop REST client: `core/get`, `core/create`, `core/update`, `core/get_related`, `list_operations`, `core/check_credentials`. |
| `callitopservice.class.inc.php` | HTTP POST to iTop pages (`synchro_*.php`) with credentials. |
| `lookuptable.class.inc.php` | `LookupTable`: OQL query → map from key fields to an id (or another attribute); used in `ProcessLineBeforeSynchro()`. |
| `mappingtable.class.inc.php` | `MappingTable`: ordered `/regex/replacement` list read from configuration; first match wins; case-insensitive. |
| `dopostrequestservice.class.inc.php` | Test seam for every HTTP call (see [testing.md](testing.md)). |
| `ioexception.class.inc.php`, `polyfill.inc.php` | `IOException`; `array_is_list()` polyfill. |
