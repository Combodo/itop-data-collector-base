Code formatting tool used by iTop is PHP-CS-Fixer:
https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/tree/master

to install it locally, run once:
```
cd test/php-code-style/; composer install; cd -
```

to check code style issues (no path provided means whole iTop code base):

```
test/php-code-style/vendor/bin/php-cs-fixer check --config test/php-code-style/.php-cs-fixer.dist.php
```

to respect iTop code standards and re-format (no path provided means whole iTop code base):

```
test/php-code-style/vendor/bin/php-cs-fixer fix --config test/php-code-style/.php-cs-fixer.dist.php

```