<?php

/**
 * Shared bootstrap of the test suites.
 *
 * - Loads the framework the way exec.php does.
 * - Protects the developer's conf/params.local.xml and collectors/ (see WorkingCopyGuard).
 * - Records the initial global state, restored around each test by BaseTestCase (see GlobalState).
 *
 * It is safe to require it several times. Test files extending BaseTestCase get it through that class, so they also
 * work when PHPUnit runs without the "bootstrap" setting of tests/php-unit-tests/phpunit.xml.
 */

if (!defined('APPROOT')) {
	define('APPROOT', dirname(__DIR__, 2).'/');
}

if (file_exists(APPROOT.'vendor/autoload.php')) {
	require_once APPROOT.'vendor/autoload.php';
}

require_once APPROOT.'core/parameters.class.inc.php';
require_once APPROOT.'core/ioexception.class.inc.php';
require_once APPROOT.'core/utils.class.inc.php';
require_once APPROOT.'core/restclient.class.inc.php';
require_once APPROOT.'core/lookuptable.class.inc.php';
require_once APPROOT.'core/mappingtable.class.inc.php';
require_once APPROOT.'core/collectionplan.class.inc.php';
require_once APPROOT.'core/collector.class.inc.php';
require_once APPROOT.'core/orchestrator.class.inc.php';
require_once APPROOT.'core/sqlcollector.class.inc.php';
require_once APPROOT.'core/csvcollector.class.inc.php';
require_once APPROOT.'core/jsoncollector.class.inc.php';
require_once APPROOT.'core/polyfill.inc.php';

require_once __DIR__.'/src/Service/WorkingCopyGuard.php';
require_once __DIR__.'/src/Service/GlobalState.php';

\UnitTestFiles\Test\Service\WorkingCopyGuard::Protect();
\UnitTestFiles\Test\Service\GlobalState::Capture();
