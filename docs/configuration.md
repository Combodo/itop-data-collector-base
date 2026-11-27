# Configuration

## Files and merge order

Later files override earlier ones (recursive merge): `conf/params.distrib.xml`, then `collectors/params.distrib.xml`,
then `collectors/extensions/params.distrib.xml`, then either `--config_file=<path>` **or else**
`conf/params.local.xml`.

## Semantics

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
  `auth_user` / `auth_pwd` and login mode `form`. `itop_login_mode` overrides the login mode. Even with a token,
  `$synchro_user$` is looked up by `synchro_user`, else `itop_login`: with neither, it stays 0.
- The configuration is a lazily loaded static singleton (`Utils::$oConfig`), read once per process.
