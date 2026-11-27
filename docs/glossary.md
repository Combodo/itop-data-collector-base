# Glossary

Terms of the framework, and the iTop terms it relies on. The name used in the code follows in parentheses when it
differs.

## iTop

| Term | Meaning |
|---|---|
| Synchro Data Source (SDS) | iTop object (`SynchroDataSource`) that describes how external data is synchronized into one iTop class: scope class, attributes, reconciliation and update policies, deletion policy. Each collector has one. |
| Replica | Row of the synchronization table of an SDS: one external record, as last uploaded. `synchro_import.php` writes replicas; `synchro_exec.php` turns them into iTop objects. |
| Reconciliation | How `synchro_exec.php` finds the iTop object that matches a replica: through the attributes whose `reconcile` flag is `1`, or through `primary_key` depending on the SDS policy. |
| Synchro user | iTop user set on the SDS (`user_id`), on behalf of whom the synchronization runs. Configured by `synchro_user`, else `itop_login`. |
| Contact to notify | iTop contact set on the SDS, notified of synchronization errors (`contact_to_notify`). |
| EventIssue | iTop object the framework can create to log a message in iTop (`eventissue_log_level`). |
| REST Services User | iTop profile required to use the REST API when `secure_rest_services` is true, the default in iTop 3.2. |

## Framework

| Term | Meaning |
|---|---|
| Collector | Class that extracts data from a third-party system and writes it as CSV for one SDS. Subclass of `Collector`, often through `CSVCollector`, `JsonCollector` or `SQLCollector`. Lives in `collectors/`, outside this repository. |
| SDS definition | JSON file describing the SDS a collector needs (`<Class>.json`). The framework creates or updates the SDS in iTop to match it. |
| Collection plan | Class that decides which collectors run, and in which order, from `collectors_launch_sequence` (`CollectionPlan`, since 1.3.0). The legacy alternative is `Orchestrator::AddCollector()` in `main.php`. |
| Phase | One of the three steps of a run: configure (the SDS), collect (the CSV files), synchronize (upload and `synchro_exec.php`). |
| Placeholder | `$name$` in a configuration value, replaced by the value of key `name`; or `$version$`, `$synchro_user$`, `$contact_to_notify$` and the keys of `json_placeholders` in an SDS definition. |
| Ignored attribute | SDS attribute that a SQL or JSON collector does not provide (`<class>_ignored_attributes`): it is optional, so its column may be missing. CSV collectors use `ignored_columns` and `defaults` instead (KB-10). |
| Nullified attribute | Attribute whose `null` value is written as `<NULL>`, which leaves the iTop field untouched, where an empty string would reset it (`nullified_attributes`). |
| Raw file | CSV written before a processing pass (`data/<Class>.raw-<n>.csv`), when `MustProcessBeforeSynchro()` is true. `ProcessLineBeforeSynchro()` turns it into the final CSV. |
| Chunk | One CSV file of a collector: a new file starts every `max_chunk_size` rows. |
| Lookup table | `LookupTable`: maps key fields to the id of an iTop object, read once through an OQL query. |
| Mapping table | `MappingTable`: ordered list of regular expressions that normalize a value (e.g. an OS name). |
| Extensions | `collectors/extensions/`: customer overrides of a collector's classes, SDS definitions and configuration, looked up first. |
| Module file | `collectors/module.<name>.php`: gives the project name and the version (`$version$`). |
| Known behaviour | Odd-looking behaviour listed in [known-behaviours.md](known-behaviours.md), with an id `KB-<nn>`. |

## Tests

| Term | Meaning |
|---|---|
| Level 1, level 2 | The unit suite, against `FakeItop`; the integration suite, against a real iTop. See [testing.md](testing.md). |
| FakeItop | In-memory iTop 3.2 that answers `rest.php`, `synchro_import.php` and `synchro_exec.php` like the iTop sources do. |
| Contract scenario | Test written once in `ItopContractTestCase` or `ExecTestCase`, and run against both `FakeItop` and a real iTop. |
