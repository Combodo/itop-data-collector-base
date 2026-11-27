# Known behaviours

Odd-looking behaviours of the framework. Collectors may rely on them: be aware of them, and do not change them
silently.

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
