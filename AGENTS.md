# AGENTS.md — iTop Data Collector Base

Guidance for AI coding agents (and humans) working on this repository.
Read it entirely before changing code, and update it when you change a behaviour it describes.

## 1. What this repository is

`itop-data-collector-base` is a small PHP CLI framework for building **data collectors** for
[iTop](https://github.com/Combodo/iTop). A collector extracts data from a third-party system, writes it as CSV and
loads it into iTop through iTop's **Synchro Data Source** (SDS) mechanism (`synchro/synchro_import.php` +
`synchro/synchro_exec.php`), using the iTop REST API (`webservices/rest.php`) to create/update the SDS definitions.

This repository contains **only the framework**. Concrete collectors (LDAP, vSphere, Proxmox, MS Graph, OCS-NG…)
live in separate repositories and are dropped into `collectors/` at runtime (see §3).

- Plain PHP, no runtime Composer dependency: `vendor/` holds dev tools only (PHPUnit 9, PHPStan 2).
- Supported PHP: `>=8.1 <8.4` (`composer.json`; CI runs PHP 8.1). Extensions: `simplexml` and `dom` (checked at
  startup), `curl` strongly recommended, `pdo_*` for SQL collectors.

## 2. Golden rule: do not break downstream collectors

About 25 Combodo collectors, plus customer extensions, subclass these classes. **None of them is tested by this
repository's CI.** Every `public` **and** `protected` member of the `core/` classes is de facto public API.

Measured in Combodo's collectors:
- Overridden methods (number of collectors): `Fetch` 19, `Prepare` 18, `Init` 9, `Synchronize` 9,
  `AttributeIsOptional` 8, `MustProcessBeforeSynchro` / `InitProcessBeforeSynchro` / `ProcessLineBeforeSynchro` 7,
  `CheckToLaunch` 6, `AddHeader` 5, `Collect` 4, `AddRow` / `GetCharset` / `GetSynchroDataSourceDefinition` /
  `Connect` 1.
- Protected properties accessed directly: `$aFields`, `$aCSVHeaders`, `$sSeparator`, `$aCSVFile`, `$iFileIndex`,
  `$aSkippedAttributes`, `$aNullifiedAttributes`, `$aCollectorConfig`, `$idx` / `$iIdx`, `$aJson`, `$aJsonKey`,
  `$oDB`, `$oStatement`, `$aCsvFieldsPerLine`.
- Static API: `Utils::Log` (~360 calls), `Utils::GetConfigurationValue` (~265), `Orchestrator::AddCollector` (~85),
  `Utils::GetDataFilePath`, `Utils::CheckModuleInstallation`, `Utils::GetModuleVersion`, `Utils::ReadParameter`,
  `Utils::ReadBooleanParameter`, `Utils::CheckParameters`, `Utils::DoPostRequest`, `Utils::GetConfigFiles`,
  `Orchestrator::UseCollectionPlan`, `Orchestrator::AddRequirement`, `<Plan>::GetPlan()`.
- Helpers instantiated directly: `new LookupTable` (~45), `new MappingTable` (~35), `new RestClient` (~30, `Get` and
  `Update`).

Therefore:
- Never rename or remove an existing public/protected method or property, and never change its signature,
  visibility, return type or meaning. New parameters go at the end, are optional, and default to the old behaviour.
- Never change configuration key names (including the exact-case-then-lowercase lookup), file lookup paths and
  their order, or the CSV format sent to iTop.
- To replace something, deprecate it: keep the old member and delegate to the new one, log a notice, and add
  `@deprecated x.y.z N°ticket`. See `Collector::ParseSynchroOutput()`.
- Tag new public/protected API with `@since x.y.z N°ticket`.
- When behaviour must change, make it opt-in through configuration, with the current behaviour as the default.
- Odd-looking behaviour is often relied upon (§10). Do not "clean it up" as a side effect of an unrelated change.

## 3. Runtime layout

`APPROOT` = directory of `exec.php` (the repository root). Every include is built from `APPROOT`.

```
exec.php                    entry point (CLI only)
core/                       framework classes (§5)
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
test/                       PHPUnit tests and fixtures
```

Lookup order:
- Collector class `<Class>.class.inc.php` (collection plans only): `collectors/extensions/src/`, then
  `collectors/src/`, then `collectors/`.
- SDS definition `<Class>.json`: `collectors/extensions/json/`, then `collectors/json/`, then `collectors/`.
- Configuration, later files override earlier ones (recursive merge): `conf/params.distrib.xml`, then
  `collectors/params.distrib.xml`, then `collectors/extensions/params.distrib.xml`, then either
  `--config_file=<path>` **or else** `conf/params.local.xml`.

## 4. Execution flow (`exec.php`)

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

## 5. Map of `core/`

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
| `dopostrequestservice.class.inc.php` | Test seam for every HTTP call (see §8). |
| `ioexception.class.inc.php`, `polyfill.inc.php` | `IOException`; `array_is_list()` polyfill. |

## 6. Configuration semantics

- XML to PHP: a leaf is a string. An element with children is a hash (tag → value; duplicate tags are rejected).
  `type="array"` makes a list (child tag names are ignored but must all be the same). `type="int"` or
  `type="integer"` makes an int.
- `$name$` inside any configuration value is replaced by the value of key `name`, recursively. An unknown key gives
  `#ERROR_UNDEFINED_PLACEHOLDER_name#`.
- Per-collector settings live in a section named after the class, looked up with the exact case first, then
  lowercase (`<MyCollector>` or `<mycollector>`). Legacy flat keys also exist: `<class>_query`,
  `<class>_ignored_attributes`, `<class>_nullified_attributes`.
- SDS JSON placeholders are replaced as plain strings before `json_decode`: `$version$`, `$synchro_user$` (user id),
  `$contact_to_notify$` (contact id), and every key of `json_placeholders` (e.g. `$prefix$`, `$full_load_interval$`).
- Credentials: `itop_token` gives `auth_token` and login mode `token`. Otherwise `itop_login` / `itop_password` give
  `auth_user` / `auth_pwd` and login mode `form`. `itop_login_mode` overrides the login mode.
- The configuration is a lazily loaded static singleton (`Utils::$oConfig`), read once per process.

## 7. Data contract with iTop

- CSV sent to iTop: separator `;`, qualifier `"`, escape `\`, header line first. A `primary_key` column is mandatory;
  the other columns are SDS attcodes, or their `import_columns` for multi-column attributes (since 1.5.0). Unknown
  columns are dropped, with a warning unless the attribute is optional.
- Empty string resets the field in iTop. `<NULL>` leaves it untouched; it is written when a value is `null` and the
  attribute is nullified (`nullified_attributes`).
- `synchro_import.php` receives `separator`, `data_source_id`, `synchronize=0`, `no_stop_on_import_error=1`, `output`
  (`details` only when the console log level is exactly 7, otherwise `retcode`), `csvdata`, `charset`
  (`GetCharset()`), `date_format`.
- `synchro_exec.php` receives `data_sources` and, optionally, `max_chunk_size`. Errors are detected by parsing the
  returned HTML: `Objects ... errors: N`, `<p>ERROR: ...`, or a login form (bad credentials).
- REST: POST to `<itop_url>/webservices/rest.php?login_mode=…&version=…` with a `json_data` field. `RestClient` uses
  version 1.0 by default. `code != 0` means error. Old iTop versions return no `key`, so the id is parsed from the
  `Class::id` object key: keep that fallback.

## 8. Development workflow

### Setup

```bash
composer install                                  # dev tools only
(cd test/php-code-style && composer install)      # PHP-CS-Fixer
```

### Quality gates: all three must pass before a change is done

```bash
php vendor/bin/phpunit --configuration test/phpunit.xml
php vendor/bin/phpstan analyse
test/php-code-style/vendor/bin/php-cs-fixer check --config test/php-code-style/.php-cs-fixer.dist.php
```

- PHPUnit: the unit suite (about 240 tests, 2 seconds, no network). Add or update tests for every behaviour change
  or bug fix.
- PHPStan: level 1 with `phpstan-baseline.neon`. Introduce no new error; never grow the baseline to hide one.
- PHP-CS-Fixer: `fix` instead of `check` reformats. The Jenkinsfile runs the same three steps on PHP 8.1.

### Two levels of tests

| | Level 1: unit suite | Level 2: integration suite |
|---|---|---|
| Configuration | `test/phpunit.xml` | `test/phpunit-ci-with-itop.xml` (`--testsuite integration` for level 2 only) |
| iTop | `FakeItop`, in memory, or served over HTTP by `php -S` (`FakeItopServer`) | a real iTop, from environment variables |
| Runs | at every change, in Jenkins at every commit | in the nightly CI (iTop 3.2 at `http://localhost`), or on demand |
| Files | `test/*Test.php` | `test/integration/*Test.php` |

The scenarios that involve iTop are written once and run at both levels:
- `test/Support/ItopContractTestCase.php`: the dialog with iTop through the framework's classes (REST, data source
  creation and update, upload, synchronization, `<NULL>`). Level 1: `FakeItopContractTest`. Level 2:
  `RealItopContractTest`.
- `test/Support/ExecTestCase.php`: `exec.php` run in its own process with a CSV collector, with every CLI option.
  Level 1: `FakeItopExecTest`. Level 2: `RealItopExecTest`.

When a scenario passes on the real iTop and fails on the fake, the fake is wrong: fix `FakeItop`. Never weaken a
scenario to suit the fake. When iTop's behaviour matters for a change, add a scenario to these classes rather than a
fake-only test.

Running level 2 needs an iTop that tests may write into (never a production one). They create data sources and
organizations whose name holds a unique run id, and delete them afterwards:

```bash
ITOP_TEST_URL=http://localhost/itop ITOP_TEST_LOGIN=admin ITOP_TEST_PASSWORD=admin \
  php vendor/bin/phpunit --configuration test/phpunit-ci-with-itop.xml --testsuite integration
```

The account needs the "Administrator" and "REST Services User" profiles. `ITOP_TEST_TOKEN` can replace the login and
password, and `ITOP_TEST_SSL_VERIFY=0` accepts a self-signed certificate. Without `ITOP_TEST_URL` and credentials, the
level 2 tests are skipped and send nothing. `test/ci_description.ini` describes the repository for the CI factory.

### The tests protect your local files

The tests copy fixtures into `collectors/`, wipe it, and must not read `conf/params.local.xml`. So
`test/Support/bootstrap.php` (the PHPUnit bootstrap) moves `conf/params.local.xml` and the content of `collectors/`
to `test/.stash/` before the first test, and puts them back when PHPUnit ends (`WorkingCopyGuard`).

- A `conf/params.local.xml` written by a test (`ConfigurationTest` does) is deleted at the end of the run, even after
  a fatal error: exec.php never reads a configuration left by the tests.
- If a run is killed (`kill -9`, or Ctrl-C without the pcntl extension), the next run restores the files first. If
  `collectors/` or `conf/params.local.xml` still hold that run's leftovers, it stops and asks you to delete them
  yourself: it never deletes files it did not create.
- Two runs in the same working copy are refused: the second one stops immediately.
- Files the tests write into `data/` are not protected: they are generated files.

### Writing tests

- New tests extend `UnitTestFiles\Test\Support\BaseTestCase` (`require_once __DIR__.'/Support/BaseTestCase.php';`).
  Around each test it restores the global state (every static property of the `core/` classes, and `$argv`),
  empties `collectors/`, records logs in `$this->oLogger` (`HasMessage()`, `GetMessages()`) instead of printing them,
  and loads the configuration with `data_path` in a private temporary directory. Helpers:
  - `LoadConfiguration(['key' => value])`: reload the configuration files, then override top-level values. Call it
    again after installing collector files.
  - `InstallCollectorFiles($sFixtureDir, $sSubDir)`, `WriteCollectorFile($sPath, $sContent)`: populate `collectors/`.
  - `SetCliArguments(['--collect_only'])`: what `Utils::ReadParameter()` and friends read.
  - `InvokeNonPublicMethod()`, `GetNonPublicProperty()`, `SetNonPublicProperty()`.
- **No network, no real iTop, no real third-party system** in the unit suite. To exercise the dialog with iTop, call
  `$this->StartFakeItop()`: it points the configuration to `FakeItop`, an in-memory iTop 3.2 that answers
  `rest.php` (get/create/update/delete, minimal OQL), `synchro_import.php` (replica table) and `synchro_exec.php`
  (reconciliation, create/update, `<NULL>` handling, error counters), with the formats and messages of the iTop 3.2
  sources. Then assert on the outcome: `$this->oFakeItop->GetObjects('Organization', ['name' => 'x'])`,
  `GetSynchroDataSource($sName)`, `GetReplicas($iSourceId)`, `GetRestOperations('core/update')`,
  `GetRequests('synchro_exec')`. Declare the scope class of a data source with `DefineClass()` or
  `DefineClassFromSynchroDataSource($sJsonFile)` (Organization and Person exist by default). A request the fake does
  not emulate fails the test: extend the fake, and the contract scenarios if it is a new iTop behaviour.
- Third-party HTTP APIs (e.g. `jsonurl`): `$this->GetFakeHttpServer()->Route($sUrlPrefix, function ($sUrl, $aData) {
  return $sBody; })`. Every request is recorded (`GetRequests()`); an unrouted URL throws like an unreachable host.
- `test/Support/ItopContractTestCase.php` holds scenarios of the dialog with iTop. They run against the fake
  (`FakeItopContractTest`) and against a real iTop (integration suite). A fake that passes them stays faithful.
- Lower-level seams, used by older tests:
  - all HTTP (REST, synchro pages, `jsonurl`): `Utils::MockDoPostRequestService($mock)` with a mock of
    `DoPostRequestService`; reset it with `null`;
  - synchro pages only: `Collector::SetCallItopService($mock)` with a mock of `CallItopService`;
  - REST in lookups: `LookupTable::SetRestClient($mock)`; module checks: pass a `RestClient` mock to
    `Utils::CheckModuleInstallation()` / `Utils::GetModuleVersion()`;
  - configuration: inject a `Parameters` mock into `Utils::$oConfig` by reflection (see `UtilsTest`), or copy a
    fixture `params.distrib.xml` into `collectors/`;
  - logs: `Utils::MockLog($this->createMock(UtilsLogger::class), LOG_LEVEL)`, then assert on `Log()` calls.
  - `Utils::Exec()` (the `command` option) has no seam: use harmless commands in tests.
- Collect scenarios use fixture folders (`test/single_csv/<case>/`, `test/single_json/<case>/`) holding a
  `params.distrib.xml`, the input file and `expected_generated.csv`, compared with `data/<Class>-1.csv`.
- `ScriptableCollector` (and its sub-classes `One`, `Two`, `Three`) is a collector whose behaviour a test scripts
  (rows, optional attributes, reprocessing, return values) and which records the calls it receives.
- Test classes live in `test/<Subject>Test.php`, namespace `UnitTestFiles\Test`; helpers in `test/Support/`.
- A test that changes or "fixes" a known quirk (§10) must say so: these tests assert today's behaviour on purpose.
- Older tests extend PHPUnit's `TestCase` directly and reset the state themselves; `CollectorSynchroTest` adds a
  `--config_file` to `$argv` that persists until a `BaseTestCase` test restores it. Prefer `BaseTestCase` for new tests.
- Files must use LF line endings: a CRLF working copy makes heredoc-based expectations fail.
- Without `pdo_sqlite` or `pdo_mysql`, some SQL tests are skipped: this is expected. In older tests, the mocked
  logger prints `string(...)` dumps: expected noise.

## 9. Conventions

- Code style: PSR-12 with **tabs**, short array syntax, trailing commas in multi-line arrays. Hungarian prefixes
  (`$sName`, `$iCount`, `$bFlag`, `$aList`, `$oObject`, `$hHandle`), PascalCase method names, no namespaces in
  `core/`, one main class per `*.class.inc.php`.
- `Utils::Log($iLevel, $sFormat, ...$aArgs)`: with arguments, the message goes through `vsprintf`. String and array
  arguments are JSON-encoded and their outer quotes or brackets stripped. Prefer positional placeholders (`%1$s`).
- Keep Combodo copyright headers unchanged.
- Commits: `N°<ticket> - <imperative summary>` or a gitmoji code prefix (`:bug:`, `:white_check_mark:`…), first line
  72 characters at most. Development happens on `master`; releases are git tags (e.g. `1.5.1`).

## 10. Known quirks (be aware; do not change silently)

- On Windows `LOG_DEBUG == LOG_INFO == 6`. Detailed synchro output is enabled only when the console level is
  literally `7`.
- `Orchestrator` declares PHP 7.0 as its minimum, but the code needs PHP 8 (named arguments, e.g. `escape:`).
- `Orchestrator::Synchronize()` calls `Collector::Synchronize()` without a chunk size, so `max_chunk_size` is never
  forwarded to `synchro_exec.php`.
- `Orchestrator::Collect()` passes a second argument (`$bCollectOnly`) that `Collector::Collect()` ignores.
- `CSVCollector::Prepare()` wraps its fallback to `APPROOT.<csv_file>` in try/catch, but `fopen()` does not throw:
  relative CSV paths are not resolved against `APPROOT`. `JsonCollector`'s equivalent fallback does work.
- `Collector::GetVersionFromModuleFile()` `eval()`s the first `collectors/module.*.php` file.
- `DataSourcesAreEquivalent()` ignores differences in `database_table_name`, and only logs attributes that exist in
  iTop but not in the JSON.
- `SQLCollector` and `JsonCollector` implement `AttributeIsOptional()` through `<class>_ignored_attributes`;
  `CSVCollector` relies on `ignored_columns` and `defaults` instead.
- `CheckColumns()` only validates the first fetched row.
- A `Collector` instance can run `Collect()` only once: `Cleanup()` closes the CSV file without resetting
  `$iFileIndex`, so a second call fails in `NextCSVFile()`. `exec.php` uses each instance once.
- An SDS definition whose `attribute_list` entries hold a `friendlyname` (as files exported from iTop do) is found
  different at every run, hence updated: `RestClient::GetFullSynchroDataSource()` removes `friendlyname` from the
  iTop side only. Derived from the iTop 3.2 sources and frozen by
  `ItopContractTestCase::testDefinitionWithAttributeFriendlynamesIsUpdatedAtEveryRun`, which the integration suite
  checks on a real iTop.
- `ParseSynchroExecOutput()` uses a greedy regular expression per line: when several `Objects ... errors: N`
  counters share one line, only the last one is read. iTop 3.2 prints one counter per line.
- With the default `stop_on_synchro_error=no`, a failed synchronization does not change the exit code (0).
- `exec.php` succeeds (exit code 0) when `collectors/main.php` is missing: it only logs an error.
- `exec.php` calls `RestClient::GetNewestKnownVersion()` to build a debug message, even when debug is off: with wrong
  credentials, the run fails with "Unable to detect an iTop API".
- A `synchro_user` unknown to iTop raises a PHP warning (`foreach` on a null `objects`) instead of an error message;
  `$synchro_user$` becomes 0. An unknown `contact_to_notify` gives a proper warning.
- An empty `<curl_options/>` in a configuration file gives `''` instead of an empty hash: every HTTP call then fails
  with a `TypeError`.
- `Parameters::Merge()` merges lists by index: a shorter list in `params.local.xml` does not replace a longer one.
- `Utils::LoadConfig()` appends to the list of loaded files without resetting it.
- `--console_log_level=7` is stored as the string `"7"`; `Collector::Synchronize()` compares it as a string.
- A trailing slash in `itop_url` gives a double slash in the URLs (`.../itop//webservices/rest.php`).
- `HeaderIsAllowed()` keeps a column named after an attribute even when the attribute declares `import_columns`.
- `ProcessLineBeforeSynchro()` also receives the header line, as line 0.
- `MappingTable` replacements go through `vsprintf()` with the `preg_match()` matches: `%1$s` is the whole match,
  `%2$s` the first group. The replacement cannot contain the delimiter.
- `LookupTable` writes the found id into the line as an int.
- PHPStan reports one pre-existing error in `collector.class.inc.php` (`$sModuleFileContents` might be undefined).

## 11. Further reading

- The tests in `test/` are the executable specification. Read the tests of the class you are changing first.
- iTop's side of the contract: `webservices/rest.php`, `core/restservices.class.inc.php`, `synchro/synchro_import.php`,
  `synchro/synchro_exec.php` and `synchro/synchrodatasource.class.inc.php` in the iTop sources. `FakeItop` cites them.
- Collector base: https://www.itophub.io/wiki/page?id=extensions%3Aitop-data-collector-base
- Data synchronization: https://www.itophub.io/wiki/page?id=latest:advancedtopics:data_synchro_overview
- `synchro_import.php`: https://www.itophub.io/wiki/page?id=latest:advancedtopics:synchro_import
- `synchro_exec.php`: https://www.itophub.io/wiki/page?id=latest:advancedtopics:synchro_exec
