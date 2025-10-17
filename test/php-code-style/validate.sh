#! /bin/bash

LOG_DIR=$(pwd)/logs
if [ ! -d $LOG_DIR ]
then 
	mkdir $LOG_DIR
fi

CODESTYLE_DIR=$(dirname $0)
cd $CODESTYLE_DIR && composer install
cd -

$CODESTYLE_DIR/vendor/bin/php-cs-fixer check --config $CODESTYLE_DIR/.php-cs-fixer.dist.php -vvv --format=junit >$LOG_DIR/codestyle_results.xml
sed -i -e 's|.*<?xml |<?xml |' $LOG_DIR/codestyle_results.xml
ls -alh $LOG_DIR