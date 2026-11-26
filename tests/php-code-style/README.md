Code formatting tool used by iTop is PHP-CS-Fixer:
https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/tree/master

to install it locally, run once:
```
cd tests/php-code-style/; composer install; cd -
```

to check code style issues (no path provided means whole iTop code base):

```
tests/php-code-style/vendor/bin/php-cs-fixer check --config tests/php-code-style/.php-cs-fixer.dist.php
```

to respect iTop code standards and re-format (no path provided means whole iTop code base):

```
tests/php-code-style/vendor/bin/php-cs-fixer fix --config tests/php-code-style/.php-cs-fixer.dist.php

```

report generated in Jenkins is in verbose. you can have it by calling below CLI:

```
tests/php-code-style/validate.sh
```