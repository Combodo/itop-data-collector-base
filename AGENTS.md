# AGENTS.md — iTop Data Collector Base

Guidance for AI coding agents (and humans) working on this repository. Read it entirely before changing code.
The reference documentation of the framework is in `docs/`: read the files that match the code you touch (see
"Where to read"), not all of `docs/` at once. When asked to review code, also follow `REVIEW.md`.

## What this repository is

`itop-data-collector-base` is a small PHP CLI framework for building **data collectors** for
[iTop](https://github.com/Combodo/iTop). A collector extracts data from a third-party system, writes it as CSV and
loads it into iTop through iTop's **Synchro Data Source** (SDS) mechanism (`synchro/synchro_import.php` +
`synchro/synchro_exec.php`), using the iTop REST API (`webservices/rest.php`) to create/update the SDS definitions.

This repository contains **only the framework**. Concrete collectors (LDAP, vSphere, Proxmox, MS Graph, OCS-NG…)
live in separate repositories and are dropped into `collectors/` at runtime (see `docs/architecture.md`).

- Plain PHP, no runtime Composer dependency: `vendor/` holds dev tools only (PHPUnit 9, PHPStan 2).
- Supported PHP versions: read `require.php` in `composer.json`, the only reference; do not copy them into other files
  (the CI chooses the PHP versions it runs). Code must work on the whole range, its lowest version included.
- PHP extensions: `simplexml` and `dom` (checked at startup), `curl` strongly recommended, `pdo_*` for SQL collectors.

## Golden rule: do not break downstream collectors

About 25 Combodo collectors, plus third-party collectors, subclass these classes. **None of them is tested by this
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
- Odd-looking behaviour is often relied upon (`docs/known-behaviours.md`). Do not "clean it up" as a side effect of
  an unrelated change.

## Before changing code

1. Read the `docs/` files that match the code you touch (table below), and the tests of the class you change: the
   tests in `tests/php-unit-tests/` are the executable specification.
2. Check `docs/known-behaviours.md` for the code you touch. A behaviour listed there must not change as a side
   effect; to change one on purpose, cite its id (`KB-<nn>`) in your plan and in the pull request. Its status says
   what you may do:
   - `observed`: read in the code, not decided yet. Do not change it without an explicit request.
   - `validated`: intended. Change it only on an explicit request, and update its entry.
   - `to fix`: a known defect: a change is expected, opt-in when collectors may rely on it.
   - `obsolete`: gone, or code to remove.
3. If the code and `docs/` disagree, say so instead of silently changing either one.

### Where to read

| Code touched | Read |
|---|---|
| `exec.php`, `core/orchestrator.class.inc.php`, `core/collectionplan.class.inc.php` | `docs/architecture.md` |
| `core/parameters.class.inc.php`, configuration and CLI functions of `core/utils.class.inc.php`, `conf/` | `docs/configuration.md` |
| `core/collector.class.inc.php`, `core/restclient.class.inc.php`, `core/callitopservice.class.inc.php`, HTTP functions of `core/utils.class.inc.php` | `docs/itop-contract.md` |
| `core/csvcollector.class.inc.php`, `core/jsoncollector.class.inc.php`, `core/sqlcollector.class.inc.php`, `core/lookuptable.class.inc.php`, `core/mappingtable.class.inc.php` | `docs/architecture.md` ("Map of core/"), `docs/configuration.md` |
| Any behaviour of `core/` or `exec.php` | `docs/known-behaviours.md` |
| `tests/` | `docs/testing.md` |
| A term you do not know (SDS, replica, placeholder, nullified attribute…) | `docs/glossary.md` |

## Quality gates: all three must pass before a change is done

```bash
composer install                                  # once: dev tools only
(cd tests/php-code-style && composer install)      # once: PHP-CS-Fixer

php vendor/bin/phpunit --configuration tests/php-unit-tests/phpunit.xml
php vendor/bin/phpstan analyse
tests/php-code-style/vendor/bin/php-cs-fixer check --config tests/php-code-style/.php-cs-fixer.dist.php
```

- PHPUnit: the unit suite (about 240 tests, 2 seconds, no network).
- PHPStan: level 1 with `phpstan-baseline.neon`. Introduce no new error; never grow the baseline to hide one.
- PHP-CS-Fixer: `fix` instead of `check` reformats. The Jenkinsfile runs the same three steps.

## Tests

How to write and run them, with the helpers and fakes: `docs/testing.md`. The rules:
- Add or update tests for every behaviour change or bug fix.
- New tests extend `UnitTestFiles\Test\BaseTestCase` and live in
  `tests/php-unit-tests/unitary-tests/<source path>/<Subject>Test.php`, in the namespace of their folder
  (`UnitTestFiles\Test\Unitary\Core` for `unitary-tests/core/`). The collectors they run stay in the global
  namespace, like real collectors.
- **No network, no real iTop, no real third-party system** in the unit suite: use `$this->StartFakeItop()` and
  `$this->GetFakeHttpServer()`.
- When iTop's behaviour matters, add a scenario to `ItopContractTestCase` or `ExecTestCase`: they run against the fake
  and against a real iTop. When a scenario passes on the real iTop and fails on the fake, fix the fake; never weaken
  a scenario to suit it.
- A test that changes what a known behaviour asserts must say so, and cite its id.
- The tests set `conf/params.local.xml` and the content of `collectors/` aside during the run, and restore them.

## Conventions

- Code style: PSR-12 with **tabs**, short array syntax, trailing commas in multi-line arrays. Hungarian prefixes
  (`$sName`, `$iCount`, `$bFlag`, `$aList`, `$oObject`, `$hHandle`), PascalCase method names, no namespaces in
  `core/`, one main class per `*.class.inc.php`.
- `Utils::Log($iLevel, $sFormat, ...$aArgs)`: with arguments, the message goes through `vsprintf`. String and array
  arguments are JSON-encoded and their outer quotes or brackets stripped. Prefer positional placeholders (`%1$s`).
- Keep Combodo copyright headers unchanged.

## Delivering a change

- In the same change: the code, its tests, and the update of the `docs/` files that describe the changed behaviour
  (for a known behaviour: its text, status and tests). An odd-looking behaviour found on the way goes to
  `docs/known-behaviours.md` with the next free id and the status `observed`.
- Update this file when a rule it states changes.
- Commits: `N°<ticket> - <imperative summary>` or a gitmoji code prefix (`:bug:`, `:white_check_mark:`…), first line
  72 characters at most. Development happens on `master`; releases are git tags (e.g. `1.5.1`).
