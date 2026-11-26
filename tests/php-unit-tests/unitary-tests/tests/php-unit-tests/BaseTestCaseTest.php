<?php

namespace UnitTestFiles\Test;

use DoPostRequestService;
use Orchestrator;
use UnitTestFiles\Test\Support\BaseTestCase;
use Utils;

require_once dirname(__DIR__, 3).'/src/BaseTestCase/BaseTestCase.php';

/**
 * Checks the isolation guarantees that every test extending BaseTestCase relies on.
 */
class BaseTestCaseTest extends BaseTestCase
{
	public function testGlobalStateIsRestoredBetweenTests(): void
	{
		Utils::$iConsoleLogLevel = LOG_DEBUG;
		Orchestrator::$aCollectors['SomeCollector'] = ['order' => 1, 'class' => 'SomeCollector', 'sds_name' => '', 'sds_id' => 0];
		Orchestrator::AddRequirement('99.0', 'some_extension');
		Utils::MockDoPostRequestService(new DoPostRequestService());
		$this->SetCliArguments(['--collect_only']);
		$this->WriteCollectorFile('main.php', '<?php');

		// What PHPUnit does between two tests
		$this->tearDown();
		$this->setUp();

		$this->assertSame(LOG_INFO, Utils::$iConsoleLogLevel);
		$this->assertSame([], Orchestrator::$aCollectors);
		$this->assertArrayNotHasKey('some_extension', Orchestrator::$aMinVersions);
		$this->assertNull($this->GetNonPublicProperty(Utils::class, 'oMockedDoPostRequestService'));
		$this->assertFalse(Utils::ReadBooleanParameter('collect_only', false));
		$this->assertFileDoesNotExist(APPROOT.'collectors/main.php');
	}

	public function testDataFilesGoToAPrivateTemporaryDirectory(): void
	{
		$sDataFile = Utils::GetDataFilePath('SomeCollector-1.csv');

		$this->assertStringStartsWith(sys_get_temp_dir(), $sDataFile);
		$this->assertStringNotContainsString(APPROOT, $sDataFile);
	}

	public function testOnlyTheDistributedConfigurationIsLoaded(): void
	{
		$this->assertSame([CONF_DIR.'params.distrib.xml'], Utils::GetConfigFiles());
		$this->assertFileDoesNotExist(CONF_DIR.'params.local.xml', 'The developer\'s local configuration must be stashed during the tests');
	}

	public function testConfigurationTakesCollectorFilesAndOverrides(): void
	{
		$this->WriteCollectorFile('params.distrib.xml', '<?xml version="1.0"?><parameters><my_param>from collector</my_param><other>x</other></parameters>');

		$this->LoadConfiguration(['other' => 'overridden']);

		$this->assertSame('from collector', Utils::GetConfigurationValue('my_param'));
		$this->assertSame('overridden', Utils::GetConfigurationValue('other'));
		$this->assertSame([CONF_DIR.'params.distrib.xml', APPROOT.'collectors/params.distrib.xml'], Utils::GetConfigFiles());
	}

	public function testLogsAreRecorded(): void
	{
		Utils::Log(LOG_WARNING, 'Hello %1$s', 'world');
		Utils::Log(LOG_DEBUG, 'Some detail');

		$this->assertTrue($this->oLogger->HasMessage(LOG_WARNING, '/^Hello world$/'));
		$this->assertSame(['Hello world'], $this->oLogger->GetMessages(LOG_WARNING));
		$this->assertCount(2, $this->oLogger->GetEntries());
	}

	public function testInstallCollectorFilesCopiesATree(): void
	{
		$this->InstallCollectorFiles(dirname(__DIR__, 2).'/core/collectionplan/collectors_files');

		$this->assertFileExists(APPROOT.'collectors/src/TestCollectionPlan.class.inc.php');
		$this->assertFileExists(APPROOT.'collectors/extensions/json/ExtendedCollector.json');
	}
}
