# AGENTS.md — iTop Data Collector Base

Guidance for AI coding agents (and humans) working on this repository.
Read it entirely before changing code, and update it when you change a behaviour it describes.
When asked to review code, also follow `REVIEW.md`.

## 1. What this repository is

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

How the framework works (layout, execution flow, configuration, data contract with iTop, known behaviours, tests) is
documented in `docs/`.

## 2. Golden rule: do not break downstream collectors

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

## 3. Development workflow

### Setup

```bash
composer install                                  # dev tools only
(cd tests/php-code-style && composer install)      # PHP-CS-Fixer
```

### Quality gates: all three must pass before a change is done

```bash
php vendor/bin/phpunit --configuration tests/php-unit-tests/phpunit.xml
php vendor/bin/phpstan analyse
tests/php-code-style/vendor/bin/php-cs-fixer check --config tests/php-code-style/.php-cs-fixer.dist.php
```

- PHPUnit: the unit suite (about 240 tests, 2 seconds, no network). Add or update tests for every behaviour change
  or bug fix.
- PHPStan: level 1 with `phpstan-baseline.neon`. Introduce no new error; never grow the baseline to hide one.
- PHP-CS-Fixer: `fix` instead of `check` reformats. The Jenkinsfile runs the same three steps.

Code coverage, the two levels of tests, the protection of local files and how to write tests: `docs/testing.md`.

## 4. Conventions

- Code style: PSR-12 with **tabs**, short array syntax, trailing commas in multi-line arrays. Hungarian prefixes
  (`$sName`, `$iCount`, `$bFlag`, `$aList`, `$oObject`, `$hHandle`), PascalCase method names, no namespaces in
  `core/`, one main class per `*.class.inc.php`.
- `Utils::Log($iLevel, $sFormat, ...$aArgs)`: with arguments, the message goes through `vsprintf`. String and array
  arguments are JSON-encoded and their outer quotes or brackets stripped. Prefer positional placeholders (`%1$s`).
- Keep Combodo copyright headers unchanged.
- Commits: `N°<ticket> - <imperative summary>` or a gitmoji code prefix (`:bug:`, `:white_check_mark:`…), first line
  72 characters at most. Development happens on `master`; releases are git tags (e.g. `1.5.1`).
