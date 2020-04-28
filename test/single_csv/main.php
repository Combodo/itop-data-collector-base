<?php

define('APPROOT', dirname(dirname(__FILE__)) . "/");

require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');
require_once(APPROOT.'core/orchestrator.class.inc.php');
require_once(APPROOT.'core/csvcollector.class.inc.php');
require_once(APPROOT.'collectors/iTopPersonCsvCollector.class.inc.php');

/*class iTopPersonCsvCollector extends CSVCollector
{
}*/

$index = 1;

Orchestrator::AddCollector($index++, 'iTopPersonCsvCollector');