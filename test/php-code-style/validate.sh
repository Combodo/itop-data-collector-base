#! /bin/bash

DIR=$(dirname $0)
cd $DIR && composer install
cd -
test/php-code-style/vendor/bin/php-cs-fixer check --config test/php-code-style/.php-cs-fixer.dist.php --format=junit >logs/codestyle_results.xml
sed -i -e 's|.*<?xml |<?xml |' logs/codestyle_results.xml