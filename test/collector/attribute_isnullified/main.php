<?php
require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');
require_once(APPROOT.'core/orchestrator.class.inc.php');
require_once(APPROOT.'core/csvcollector.class.inc.php');
require_once(APPROOT.'collectors/iTopPersonCollector.class.inc.php');

$index = 1;

Orchestrator::AddCollector($index++, 'iTopPersonCollector');
