# REVIEW.md: AI code review instructions for iTop Data Collector Base

For automated reviewers (GitHub Copilot code review, Greptile, ...). The rules for changing the code, including the de
facto public API, are in `AGENTS.md`: read it first. How the framework works and its known behaviours are in `docs/`:
read the files that match the changed code (the "Where to read" table of `AGENTS.md`). This file says what to comment
on and how.

The tests are how pull requests get validated here: about 25 collectors subclass this framework and none of them is
tested by this repository's CI, and the maintainers have little time to check each change by hand. Review the tests of
a PR as carefully as its code.

## Output
- Write every comment in English.
- Start each comment with its priority: `[P1-Security]`, `[P2-Compatibility]`, `[P2-Bug]`, `[P2-Tests]` or `[P3]`.
- Report every P1 and P2 issue. Report at most 3 `[P3]` comments per PR: keep the most useful ones.
- Put each comment on the code it is about: a `[P2-Tests]` comment goes on the code that lacks a test.
- No praise, no comment that only describes the change.

## Priorities
1. **P1 Security**: credentials (`itop_password`, `itop_token`, database or third-party secrets) written to logs, data
   files or command lines; a real secret or host committed in a fixture or in `conf/params.distrib.xml`; TLS
   verification weakened by the PR (e.g. `CURLOPT_SSL_VERIFYPEER` set to 0 in the defaults); a shell command built
   from values not escaped with `escapeshellarg()`; unsafe file or path handling; XML parsing that loads external
   entities (`LIBXML_NOENT`, `LIBXML_DTDLOAD`); a new use of `eval()`.
2. **P2 Compatibility**: any breach of the "Golden rule" of `AGENTS.md`, which protects the collectors that this CI
   does not test: a public or protected member of a `core/` class renamed, removed, or changed in signature,
   visibility, return type or meaning; a new parameter that is not last, optional and defaulting to the old
   behaviour; a changed configuration key, file lookup path or order, or CSV format sent to iTop; a behaviour change
   that is not opt-in; a member replaced without deprecation; a new public or protected member without `@since`; a
   known behaviour (`docs/known-behaviours.md`) changed as a side effect, or changed on purpose without citing its id
   (`KB-<nn>`) or against what its status allows (see "Before changing code" in `AGENTS.md`).
3. **P2 Bug**: logic error, wrong condition, unhandled `null`/`false` return or exception, a failure that does not
   change the exit code when it should, code incompatible with the lowest PHP version of `require.php` in
   `composer.json`, leftover debug output (`var_dump()`, `print_r()`, a temporary `echo`).
4. **P2 Tests**: see "Tests" below.
5. **P3 Other**: deviation from the "Conventions" of `AGENTS.md` (Hungarian prefixes, PascalCase methods, no
   namespace in `core/`, `Utils::Log()` rather than direct output), a file of `docs/` not updated when the PR changes
   a behaviour it describes (for a known behaviour: its text, status and tests), `AGENTS.md` not updated when the PR
   changes a rule it states, unclear names, typos in identifiers, comments and log messages, duplicated logic or
   re-implementation of an existing helper (`Utils`, `LookupTable`, `MappingTable`, `RestClient`), dead or
   commented-out code, function too long or too nested to read.

## Tests
Every PR must keep the tests in line with the code it changes, as far as it is relevant:
- A behaviour change, a bug fix, a new feature, configuration key or command line option, a new public or protected
  member, or a new way of handling an answer from iTop needs tests. A bug fix needs a test that fails without it.
  Every new or changed branch of `core/` and `exec.php` should be reached by a test. When one is missing, comment
  `[P2-Tests]` on that code: say what to assert, and in which file of `tests/php-unit-tests/unitary-tests/` (it
  mirrors the sources).
- Not relevant: documentation, comments, formatting, CI configuration, or a refactoring whose behaviour the existing
  tests already cover. Do not ask for tests there.
- Check the new tests against the "Tests" rules of `AGENTS.md` (details in `docs/testing.md`): they extend
  `BaseTestCase`; the unit suite uses no network, no real iTop and no real third-party system (`StartFakeItop()`,
  `GetFakeHttpServer()`); namespaces follow the folders; collectors stay in the global namespace.
- A new or changed request to iTop must be emulated by `FakeItop` as the iTop 3.2 sources behave, never bent to make a
  test pass. When it relies on an iTop behaviour no scenario covers yet, it also needs a scenario in
  `ItopContractTestCase`, which the integration suite runs against a real iTop.
- A test that changes what a known behaviour asserts must say so and cite its id (`KB-<nn>`), and the entry in
  `docs/known-behaviours.md` must be updated. The tests that freeze a behaviour say so ("Known behaviour KB-02: ...").
- Flag weakened tests: a test removed or skipped without a reason, a loosened assertion, a test that passes whatever
  the code does.
- Integration tests must delete everything they create in iTop, and must not depend on what iTop already holds.

## Do not comment on
- Formatting: indentation, spacing, line breaks, brace placement. PHP-CS-Fixer handles it.
- Third-party code in `vendor/` and `tests/php-code-style/vendor/`.
- Code the PR does not touch, unless the change breaks it or it needs to be adjusted accordingly.
- The known behaviours of `docs/known-behaviours.md` in code the PR does not touch: they are kept on purpose.

## Vulnerabilities
- A vulnerability introduced by the PR is always a `[P1-Security]` comment: explain the risk and how to fix it.
- This repository is public. If the PR seems to fix an existing vulnerability, review it as an ordinary change: never
  call it a security fix, never describe the vulnerability, how to exploit it, or the affected versions, in comments
  or in the PR overview. If the fix looks incomplete, describe only the missing check in neutral technical terms (for
  example "this value is not escaped here").
