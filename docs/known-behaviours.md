# Known behaviours

Odd-looking behaviours of the framework, read in the code. Collectors may rely on them: be aware of them, and do not
change them as a side effect. The ids, statuses and columns are described in [README.md](README.md#conventions).

Every behaviour starts as `observed`: nobody has decided yet whether it is intended. Reviewing them means giving
each one the status `validated`, `to fix` or `obsolete`.

## Execution and orchestration

| ID | Behaviour | Source | Test | Status |
|---|---|---|---|---|
| KB-01 | `Orchestrator` declares PHP 7.0 as its minimum, but the code needs PHP 8 (named arguments, e.g. `escape:`). | `Orchestrator::$aMinVersions`, `Orchestrator::CheckRequirements()` | `OrchestratorPhasesTest::testDeclaredPhpRequirementIsOnlyPhp7` | observed |
| KB-02 | `Orchestrator::Synchronize()` calls `Collector::Synchronize()` without a chunk size, so `max_chunk_size` is never forwarded to `synchro_exec.php`. | `Orchestrator::Synchronize()` | `OrchestratorPhasesTest::testSynchronizePhaseGoesOnAfterAFailureAndReportsSuccessByDefault`, `FakeItopExecTest::testRequestsReceivedByTheFakeItop` | observed |
| KB-03 | `Orchestrator::Collect()` passes a second argument (`$bCollectOnly`) that `Collector::Collect()` ignores. | `Orchestrator::Collect()`, `Collector::Collect()` | `OrchestratorPhasesTest::testCollectPhaseStopsAtTheFirstFailingCollector` | observed |
| KB-04 | With the default `stop_on_synchro_error=no`, a failed synchronization does not change the exit code (0). | `Orchestrator::Synchronize()` | `OrchestratorPhasesTest::testSynchronizePhaseGoesOnAfterAFailureAndReportsSuccessByDefault` | observed |
| KB-05 | `exec.php` succeeds (exit code 0) when `collectors/main.php` is missing: it only logs an error. | `exec.php` | `ExecTestCase::testMissingMainFileIsLoggedButTheRunSucceeds` | observed |
| KB-06 | `exec.php` calls `RestClient::GetNewestKnownVersion()` to build a debug message, even when debug is off: with wrong credentials, the run fails with "Unable to detect an iTop API". | `exec.php`, `RestClient::GetNewestKnownVersion()` | `ExecTestCase::testWrongCredentialsFailTheRun` | observed |
| KB-07 | A `synchro_user` unknown to iTop raises a PHP warning (`foreach` on a null `objects`) instead of an error message; `$synchro_user$` becomes 0. An unknown `contact_to_notify` gives a proper warning. | `Orchestrator::InitSynchroDataSources()` | `OrchestratorPhasesTest::testUnknownSynchroUserRaisesAPhpWarning` | observed |

## Collectors

| ID | Behaviour | Source | Test | Status |
|---|---|---|---|---|
| KB-08 | `CSVCollector::Prepare()` wraps its fallback to `APPROOT.<csv_file>` in try/catch, but `fopen()` does not throw: relative CSV paths are not resolved against `APPROOT`. `JsonCollector`'s equivalent fallback does work. | `CSVCollector::Prepare()`, `JsonCollector::Prepare()` | — (the JSON side: `JsonCollectorTest::testOrgCollector`, case "json file with relative path") | observed |
| KB-09 | `Collector::GetVersionFromModuleFile()` `eval()`s the first `collectors/module.*.php` file. | `Collector::GetVersionFromModuleFile()` | `OrchestratorTest::testOrgCollectorGetProjectName` | observed |
| KB-10 | `SQLCollector` and `JsonCollector` implement `AttributeIsOptional()` through `<class>_ignored_attributes`; `CSVCollector` relies on `ignored_columns` and `defaults` instead. | `SQLCollector::AttributeIsOptional()`, `JsonCollector::AttributeIsOptional()` | `JsonCollectorTest::testFetchWithIgnoredAttributesJson` (JSON only) | observed |
| KB-11 | `CheckColumns()` only validates the first fetched row. | `Collector::CheckColumns()`, `CSVCollector::CheckColumns()` | — | observed |
| KB-12 | A `Collector` instance can run `Collect()` only once: `Cleanup()` closes the CSV file without resetting `$iFileIndex`, so a second call fails in `NextCSVFile()`. `exec.php` uses each instance once. | `Collector::Cleanup()`, `Collector::NextCSVFile()` | — | observed |
| KB-13 | `HeaderIsAllowed()` keeps a column named after an attribute even when the attribute declares `import_columns`. | `Collector::HeaderIsAllowed()` | `CollectorDataFlowTest::testImportColumnsDeclareTheColumnsOfAMultiColumnAttribute` | observed |
| KB-14 | `ProcessLineBeforeSynchro()` also receives the header line, as line 0. | `Collector::DoProcessBeforeSynchro()` | `CollectorDataFlowTest::testRawFilesAreProcessedLineByLineBeforeTheUpload` | observed |

## Data sources and synchronization

| ID | Behaviour | Source | Test | Status |
|---|---|---|---|---|
| KB-15 | `DataSourcesAreEquivalent()` ignores differences in `database_table_name`, and only logs attributes that exist in iTop but not in the JSON. | `Collector::DataSourcesAreEquivalent()` | — | observed |
| KB-16 | An SDS definition whose `attribute_list` entries hold a `friendlyname` (as files exported from iTop do) is found different at every run, hence updated: `RestClient::GetFullSynchroDataSource()` removes `friendlyname` from the iTop side only. Derived from the iTop 3.2 sources. | `RestClient::GetFullSynchroDataSource()` | `ItopContractTestCase::testDefinitionWithAttributeFriendlynamesIsUpdatedAtEveryRun` (also on a real iTop) | observed |
| KB-17 | `ParseSynchroExecOutput()` uses a greedy regular expression per line: when several `Objects ... errors: N` counters share one line, only the last one is read. iTop 3.2 prints one counter per line. | `Collector::ParseSynchroExecOutput()` | `CollectorSynchroTest::testParseSynchroExecOutput`, case "synchro error count parsing" | observed |

## Logging and command line

| ID | Behaviour | Source | Test | Status |
|---|---|---|---|---|
| KB-18 | On Windows `LOG_DEBUG == LOG_INFO == 6`. Detailed synchro output is enabled only when the console level is literally `7`. | `Collector::Synchronize()` | `CollectorDataFlowTest::testDetailedImportOutputIsRequestedOnlyAtConsoleLogLevel7` | observed |
| KB-19 | `--console_log_level=7` is stored as the string `"7"`; `Collector::Synchronize()` compares it as a string. | `Utils::InitConsoleLogLevel()`, `Utils::ReadParameter()` | `ConfigurationTest::testConsoleLogLevelFromTheCommandLineIsAString` | observed |

## Configuration

| ID | Behaviour | Source | Test | Status |
|---|---|---|---|---|
| KB-20 | An empty `<curl_options/>` in a configuration file gives `''` instead of an empty hash: every HTTP call then fails with a `TypeError`. | `Parameters`, `Utils::GetCurlOptions()` | `ConfigurationTest::testAnEmptyCurlOptionsElementCrashesTheHttpCalls` | observed |
| KB-21 | `Parameters::Merge()` merges lists by index: a shorter list in `params.local.xml` does not replace a longer one. | `Parameters::Merge()` | `ConfigurationTest::testMergeOverridesRecursivelyAndListsByIndex` | observed |
| KB-22 | `Utils::LoadConfig()` appends to the list of loaded files without resetting it. | `Utils::LoadConfig()` | `ConfigurationTest::testEachLoadAppendsToTheListOfLoadedFiles` | observed |

## HTTP and helpers

| ID | Behaviour | Source | Test | Status |
|---|---|---|---|---|
| KB-23 | A trailing slash in `itop_url` gives a double slash in the URLs (`.../itop//webservices/rest.php`). | `RestClient`, `Collector` (URLs built from `itop_url`) | `RestClientTest::testATrailingSlashInTheUrlIsKept` | observed |
| KB-24 | `MappingTable` replacements go through `vsprintf()` with the `preg_match()` matches: `%1$s` is the whole match, `%2$s` the first group. The replacement cannot contain the delimiter. | `MappingTable::MapValue()` | `MappingTableTest::testReplacementPlaceholdersAreShiftedByOne`, `MappingTableTest::testAnyDelimiterCanBeUsedButTheReplacementCannotContainIt` | observed |
| KB-25 | `LookupTable` writes the found id into the line as an int. | `LookupTable::Lookup()` | `RestClientTest::testLookupTableQueriesItopWithOql` | observed |
