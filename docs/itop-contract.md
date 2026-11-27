# Data contract with iTop

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

## iTop's side of the contract

`webservices/rest.php`, `core/restservices.class.inc.php`, `synchro/synchro_import.php`, `synchro/synchro_exec.php`
and `synchro/synchrodatasource.class.inc.php` in the iTop sources. `FakeItop` cites them.
