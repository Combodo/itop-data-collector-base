# Documentation of iTop Data Collector Base

Reference documentation of the framework: how it works, what it exchanges with iTop, and the odd-looking behaviours
that collectors may rely on. It lives with the code: a change that alters a documented behaviour updates these files
in the same pull request.

`AGENTS.md`, at the root of the repository, holds the rules for changing the code. This folder holds the knowledge
those rules rely on.

| File | Content |
|---|---|
| [architecture.md](architecture.md) | Runtime layout, execution flow of `exec.php`, map of `core/` |
| [configuration.md](configuration.md) | Configuration files and their merge order, XML semantics, placeholders, credentials |
| [itop-contract.md](itop-contract.md) | What the framework sends to iTop, and how it reads the answers |
| [known-behaviours.md](known-behaviours.md) | Odd-looking behaviours: be aware of them, do not change them silently |
| [testing.md](testing.md) | The two levels of tests, code coverage, protection of local files, how to write tests |

## Further reading

- The tests in `tests/php-unit-tests/` are the executable specification. Read the tests of the class you are changing
  first.
- Collector base: https://www.itophub.io/wiki/page?id=extensions%3Aitop-data-collector-base
- Data synchronization: https://www.itophub.io/wiki/page?id=latest:advancedtopics:data_synchro_overview
- `synchro_import.php`: https://www.itophub.io/wiki/page?id=latest:advancedtopics:synchro_import
- `synchro_exec.php`: https://www.itophub.io/wiki/page?id=latest:advancedtopics:synchro_exec
