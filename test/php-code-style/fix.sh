#! /bin/bash

CODESTYLE_DIR=$(dirname $0)
cd $CODESTYLE_DIR
composer install
vendor/bin/php-cs-fixer fix --config .php-cs-fixer.dist.php