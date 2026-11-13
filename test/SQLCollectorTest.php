<?php

namespace UnitTestFiles\Test;

use PHPUnit\Framework\TestCase;
use Utils;

@define('APPROOT', dirname(__FILE__, 2).'/');

require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');
require_once(APPROOT.'core/orchestrator.class.inc.php');
require_once(APPROOT.'core/sqlcollector.class.inc.php');

class TestableSQLCollector extends \SQLCollector
{
	public function CallConnect(): bool
	{
		return $this->Connect();
	}

	public function CallGetPdoOptions()
	{
		return $this->GetPDOOptions();
	}

	public function GetPdoConnection()
	{
		return $this->oDB;
	}
}

class SQLCollectorTest extends TestCase
{
	public function tearDown(): void
	{
		parent::tearDown();
		Utils::MockLog(null);

		$oConfigReflection = new \ReflectionProperty(Utils::class, 'oConfig');
		$oConfigReflection->setAccessible(true);
		$oConfigReflection->setValue(null, null);
	}

	public function testConnectReturnsFalseWhenPdoConnectionFails()
	{
		$oMockedLogger = $this->createMock("UtilsLogger");
		Utils::MockLog($oMockedLogger, LOG_ERR);
		$this->MockConfiguration([
			'sql_engine' => 'driver_that_does_not_exist',
			'sql_host' => 'localhost',
			'sql_database' => 'dummy',
			'sql_login' => 'root',
			'sql_password' => '',
			'sql_ssl' => 'no',
		]);

		$oMockedLogger->expects($this->exactly(2))
			->method('Log');

		$oCollector = new TestableSQLCollector();
		$bResult = $oCollector->CallConnect();

		$this->assertFalse($bResult);
		$this->assertNull($oCollector->GetPdoConnection());
	}

	public function testConnectReturnsTrueWithSqliteMemory()
	{
		if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
			$this->markTestSkipped("Le driver PDO sqlite n'est pas disponible.");
		}

		$this->MockConfiguration([
			'sql_engine' => 'sqlite',
			'sql_host' => 'localhost',
			'sql_database' => '',
			'sql_login' => '',
			'sql_password' => '',
			'sql_ssl' => 'no',
			'sql_connection_string' => 'sqlite::memory:',
		]);

		$oCollector = new TestableSQLCollector();
		$bResult = $oCollector->CallConnect();

		$this->assertTrue($bResult);
		$this->assertInstanceOf(\PDO::class, $oCollector->GetPdoConnection());
	}

	public function testGetPdoOptionsReturnsNullWithoutSsl()
	{
		$this->MockConfiguration([
			'sql_ssl' => 'no',
		]);

		$oCollector = new TestableSQLCollector();
		$this->assertNull($oCollector->CallGetPdoOptions());
	}

	public function testGetPdoOptionsReturnsTlsOptionsWhenSslEnabled()
	{
		if (!defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
			$this->markTestSkipped("Les constantes PDO MySQL SSL ne sont pas disponibles.");
		}

		$this->MockConfiguration([
			'sql_ssl' => 'yes',
			'sql_cafile' => 'C:\\certs\\ca.pem',
			'sql_check_server_certification' => 'yes',
			'sql_cert_file' => '',
			'sql_key_file' => '',
		]);

		$oCollector = new TestableSQLCollector();
		$aOptions = $oCollector->CallGetPdoOptions();

		$this->assertEquals([
			\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
			\PDO::MYSQL_ATTR_SSL_CA => 'C:\\certs\\ca.pem',
		], $aOptions);
	}

	public function testGetPdoOptionsReturnsMutualTlsOptionsWhenCertAndKeyAreSet()
	{
		if (!defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
			$this->markTestSkipped("Les constantes PDO MySQL SSL ne sont pas disponibles.");
		}

		$this->MockConfiguration([
			'sql_ssl' => 'yes',
			'sql_cafile' => 'C:\\certs\\ca.pem',
			'sql_check_server_certification' => 'no',
			'sql_cert_file' => 'C:\\certs\\client-cert.pem',
			'sql_key_file' => 'C:\\certs\\client-key.pem',
		]);

		$oCollector = new TestableSQLCollector();
		$aOptions = $oCollector->CallGetPdoOptions();

		$this->assertEquals([
			\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
			\PDO::MYSQL_ATTR_SSL_CA => 'C:\\certs\\ca.pem',
			\PDO::MYSQL_ATTR_SSL_CERT => 'C:\\certs\\client-cert.pem',
			\PDO::MYSQL_ATTR_SSL_KEY => 'C:\\certs\\client-key.pem',
		], $aOptions);
	}

	private function MockConfiguration(array $aConfiguration): void
	{
		$oParametersMock = $this->createMock(\Parameters::class);
		$oParametersMock->expects($this->any())
			->method('Get')
			->will($this->returnCallback(
				function ($sKey, $defaultValue) use ($aConfiguration) {
					if (array_key_exists($sKey, $aConfiguration)) {
						return $aConfiguration[$sKey];
					}

					return $defaultValue;
				}
			));

		$oConfigReflection = new \ReflectionProperty(Utils::class, 'oConfig');
		$oConfigReflection->setAccessible(true);
		$oConfigReflection->setValue(null, $oParametersMock);
	}
}
