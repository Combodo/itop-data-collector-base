# Documentation of iTop Data Collector Base

Reference documentation of the framework: how it works, what it exchanges with iTop, and the odd-looking behaviours
that collectors may rely on. It lives with the code: a change that alters a documented behaviour updates these files
in the same pull request.

`AGENTS.md`, at the root of the repository, holds the rules for changing the code. This folder holds the knowledge
those rules rely on.

| File | Content |
|---|---|
| [glossary.md](glossary.md) | Terms of the framework and of iTop, and their names in the code |
| [architecture.md](architecture.md) | Runtime layout, execution flow of `exec.php`, map of `core/` |
| [configuration.md](configuration.md) | Configuration files and their merge order, XML semantics, placeholders, credentials |
| [itop-contract.md](itop-contract.md) | What the framework sends to iTop, and how it reads the answers |
| [known-behaviours.md](known-behaviours.md) | Odd-looking behaviours: be aware of them, do not change them silently |
| [testing.md](testing.md) | The two levels of tests, code coverage, protection of local files, how to write tests |

## Conventions

### Known behaviours

Each known behaviour has an id `KB-<nn>`, never reused: a behaviour that no longer exists keeps its id with the
status `obsolete`. A new one gets the next free number.

| Status | Meaning |
|---|---|
| `observed` | Read in the code, not decided yet. Every behaviour starts with this status. |
| `validated` | Intended: collectors may rely on it. |
| `to fix` | Observed but not intended: it calls for a change, opt-in when collectors may rely on it. |
| `obsolete` | Gone from the code, or to remove. |

### Sources and tests

- A source is cited by class and function (`Collector::HeaderIsAllowed()`), not by line number: names do not drift.
- The "Test" column cites the PHPUnit tests that assert the behaviour, as `Class::method`; "—" means none does yet.
  Such a test asserts today's behaviour on purpose: a change that makes it fail changes the behaviour.

## Keeping it alive

1. A change that alters a documented behaviour updates the matching file in the same pull request; for a known
   behaviour, its text, status and tests.
2. A pull request that changes a known behaviour cites its id.
3. When the code and this documentation disagree, the tests say what the code does: report it rather than silently
   align one on the other.

## Further reading

- The tests in `tests/php-unit-tests/` are the executable specification. Read the tests of the class you are changing
  first.
- Collector base: https://www.itophub.io/wiki/page?id=extensions%3Aitop-data-collector-base
- Data synchronization: https://www.itophub.io/wiki/page?id=latest:advancedtopics:data_synchro_overview
- `synchro_import.php`: https://www.itophub.io/wiki/page?id=latest:advancedtopics:synchro_import
- `synchro_exec.php`: https://www.itophub.io/wiki/page?id=latest:advancedtopics:synchro_exec
