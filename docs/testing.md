# Testing

The quality gates (PHPUnit, PHPStan, PHP-CS-Fixer) and their commands are in `AGENTS.md`.

## Code coverage

```bash
XDEBUG_MODE=coverage php vendor/bin/phpunit --configuration tests/php-unit-tests/phpunit.xml --coverage-text
```

- Needs Xdebug (the variable enables its coverage mode) or PCOV. `--coverage-html <dir>` writes a browsable report,
  `--coverage-clover <file>` a report for the CI.
- The scope is `core/` and `exec.php`, set in the `<coverage>` section of `tests/php-unit-tests/phpunit.xml`, whose comments explain
  the choices. Never set `processUncoveredFiles` to true there: it would include `exec.php` and run a collector.
- `exec.php` shows 0%: the end-to-end tests run it in a child PHP process, which coverage does not follow.
- Coverage is not a quality gate: no threshold is enforced.

## Two levels of tests

| | Level 1: unit suite | Level 2: integration suite |
|---|---|---|
| Configuration | `phpunit.xml` | `phpunit-ci-with-itop.xml` (`--testsuite integration` for level 2 only) |
| iTop | `FakeItop`, in memory, or served over HTTP by `php -S` (`FakeItopServer`) | a real iTop, from environment variables |
| Runs | at every change, in Jenkins at every commit | in the nightly CI (iTop 3.2 at `http://localhost`), or on demand |
| Files | `unitary-tests/**/*Test.php` | `integration-tests/*Test.php` |

Paths are relative to `tests/php-unit-tests/`. The scenarios that involve iTop are written once and run at both levels:
- `src/BaseTestCase/ItopContractTestCase.php`: the dialog with iTop through the framework's classes (REST, data source
  creation and update, upload, synchronization, `<NULL>`). Level 1: `FakeItopContractTest`. Level 2:
  `RealItopContractTest`.
- `src/BaseTestCase/ExecTestCase.php`: `exec.php` run in its own process with a CSV collector, with every CLI option.
  Level 1: `FakeItopExecTest`. Level 2: `RealItopExecTest`.

When a scenario passes on the real iTop and fails on the fake, the fake is wrong: fix `FakeItop`. Never weaken a
scenario to suit the fake. When iTop's behaviour matters for a change, add a scenario to these classes rather than a
fake-only test.

Running level 2 needs an iTop that tests may write into (never a production one). They create data sources and
organizations whose name holds a unique run id, and delete them afterwards: the data sources first, because iTop
refuses to delete an object that a data source created while the data source exists. A test whose cleanup fails
fails too, so that nothing is left behind unnoticed.

```bash
ITOP_TEST_URL=http://localhost/itop ITOP_TEST_LOGIN=admin ITOP_TEST_PASSWORD=admin \
  php vendor/bin/phpunit --configuration tests/php-unit-tests/phpunit-ci-with-itop.xml --testsuite integration
```

The account needs the "Administrator" and "REST Services User" profiles. `ITOP_TEST_TOKEN` can replace the password,
not the login: the tests look the account up by its login, as the framework does to resolve `$synchro_user$`.
`ITOP_TEST_SSL_VERIFY=0` accepts a self-signed certificate. Without `ITOP_TEST_URL` and credentials, the level 2 tests
are skipped and send nothing. `tests/ci_description.ini` describes the repository for the CI factory.

## The tests protect your local files

The tests copy fixtures into `collectors/`, wipe it, and must not read `conf/params.local.xml`. So
`tests/php-unit-tests/bootstrap.php` (the PHPUnit bootstrap) moves `conf/params.local.xml` and the content of
`collectors/` to `tests/php-unit-tests/.stash/` before the first test, and puts them back when PHPUnit ends (`WorkingCopyGuard`).

- A `conf/params.local.xml` written by a test (`ConfigurationTest` does) is deleted at the end of the run, even after
  a fatal error: exec.php never reads a configuration left by the tests.
- If a run is killed (`kill -9`, or Ctrl-C without the pcntl extension), the next run restores the files first. If
  `collectors/` or `conf/params.local.xml` still hold that run's leftovers, it stops and asks you to delete them
  yourself: it never deletes files it did not create.
- Two runs in the same working copy are refused: the second one stops immediately.
- Files the tests write into `data/` are not protected: they are generated files.

## Writing tests

- Layout of `tests/php-unit-tests/`, the same as in iTop:
  - `unitary-tests/` mirrors the sources: `core/` holds the tests of the `core/` classes, with their fixture folders
    next to them; `FakeItopExecTest.php` tests `exec.php`; `tests/php-unit-tests/` tests the test tooling itself.
  - `integration-tests/`: the level 2 tests, against a real iTop.
  - `src/BaseTestCase/`: the abstract test cases. `src/Service/`: the working copy guard, the global state, the
    recording logger and the fakes. `src/Collector/`: the collectors the tests run.
  - `bootstrap.php` is the PHPUnit bootstrap; `bootstrap.inc.php` is a legacy file that nothing uses.
- Namespaces follow the folders, each sub-folder in PascalCase:
  - `UnitTestFiles\Test` for `src/BaseTestCase/` (the root namespace, as in iTop), `UnitTestFiles\Test\Service` for
    `src/Service/`;
  - `UnitTestFiles\Test\Unitary` for `unitary-tests/`, so `UnitTestFiles\Test\Unitary\Core` for
    `unitary-tests/core/` and `UnitTestFiles\Test\Unitary\Tests\PhpUnitTests` for `unitary-tests/tests/php-unit-tests/`;
  - `UnitTestFiles\Test\Integration` for `integration-tests/`;
  - exception: the collectors of `src/Collector/` and of the fixtures stay in the global namespace, like real
    collectors, because the framework finds their JSON definition and configuration section by class name
    (`get_class()`).
- New tests extend `UnitTestFiles\Test\BaseTestCase`
  (`require_once dirname(__DIR__, 2).'/src/BaseTestCase/BaseTestCase.php';` from `unitary-tests/core/`).
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
- `src/BaseTestCase/ItopContractTestCase.php` holds scenarios of the dialog with iTop. They run against the fake
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
- Collect scenarios use fixture folders (`unitary-tests/core/single_csv/<case>/`,
  `unitary-tests/core/single_json/<case>/`) holding a `params.distrib.xml`, the input file and
  `expected_generated.csv`, compared with `data/<Class>-1.csv`.
- `ScriptableCollector` (and its sub-classes `One`, `Two`, `Three`) is a collector whose behaviour a test scripts
  (rows, optional attributes, reprocessing, return values) and which records the calls it receives.
- Test classes live in `unitary-tests/<source path>/<Subject>Test.php`, in the namespace of their folder.
- A test that changes or "fixes" a known behaviour ([known-behaviours.md](known-behaviours.md)) must say so and cite
  its id, as the existing ones do ("Known behaviour KB-02: ..."): these tests assert today's behaviour on purpose.
- Older tests extend PHPUnit's `TestCase` directly and reset the state themselves; `CollectorSynchroTest` adds a
  `--config_file` to `$argv` that persists until a `BaseTestCase` test restores it. Prefer `BaseTestCase` for new tests.
- Files must use LF line endings: a CRLF working copy makes heredoc-based expectations fail.
- Without `pdo_sqlite` or `pdo_mysql`, some SQL tests are skipped: this is expected. In older tests, the mocked
  logger prints `string(...)` dumps: expected noise.
