<?php

namespace UnitTestFiles\Test\Unitary\Core;

use Exception;
use InvalidParameterException;
use Parameters;
use ScriptableCollector;
use ScriptableCollectorOne;
use UnitTestFiles\Test\BaseTestCase;
use Utils;

require_once dirname(__DIR__, 2).'/src/BaseTestCase/BaseTestCase.php';
require_once dirname(__DIR__, 2).'/src/Collector/ScriptableCollector.php';

/**
 * How the configuration is read (XML to PHP values), merged from several files, overridden from the command line,
 * and how placeholders are substituted.
 */
class ConfigurationTest extends BaseTestCase
{
	private function WriteXml(string $sFileName, string $sParametersContent): string
	{
		$sPath = $this->GetTempDir().'/'.$sFileName;
		file_put_contents($sPath, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<parameters>$sParametersContent</parameters>\n");

		return $sPath;
	}

	/////////////////////////////////////////////////////////////////////////
	// XML format

	public function testXmlElementsBecomeStringsHashesListsOrIntegers(): void
	{
		$oParameters = new Parameters($this->WriteXml('params.xml', '
			<a_string> text </a_string>
			<an_int type="int">42</an_int>
			<an_integer type="integer">7</an_integer>
			<a_number>12</a_number>
			<empty/>
			<a_hash><key1>v1</key1><key2>v2</key2></a_hash>
			<a_list type="array"><item>x</item><item>y</item></a_list>
			<nested><list type="array"><entry><name>n</name></entry></list></nested>
		'));

		$this->assertSame(' text ', $oParameters->Get('a_string'));
		$this->assertSame(42, $oParameters->Get('an_int'));
		$this->assertSame(7, $oParameters->Get('an_integer'));
		$this->assertSame('12', $oParameters->Get('a_number'), 'Without type, a number stays a string');
		$this->assertSame('', $oParameters->Get('empty'));
		$this->assertSame(['key1' => 'v1', 'key2' => 'v2'], $oParameters->Get('a_hash'));
		$this->assertSame(['x', 'y'], $oParameters->Get('a_list'));
		$this->assertSame(['list' => [['name' => 'n']]], $oParameters->Get('nested'));
		$this->assertSame('default', $oParameters->Get('missing', 'default'));
	}

	public function testDuplicateTagsInAHashAreRejected(): void
	{
		$this->expectException(InvalidParameterException::class);
		$this->expectExceptionMessage("duplicate tags 'key' inside hash 'a_hash'");

		new Parameters($this->WriteXml('params.xml', '<a_hash><key>1</key><key>2</key></a_hash>'));
	}

	public function testMixedTagsInAListAreRejected(): void
	{
		$this->expectException(InvalidParameterException::class);
		$this->expectExceptionMessage("mixed tags ('item' and 'other') inside array 'a_list'");

		new Parameters($this->WriteXml('params.xml', '<a_list type="array"><item>1</item><other>2</other></a_list>'));
	}

	public function testInvalidXmlIsRejectedWithTheLine(): void
	{
		$sPath = $this->GetTempDir().'/broken.xml';
		file_put_contents($sPath, "<parameters>\n<unclosed>\n</parameters>");

		$this->expectException(InvalidParameterException::class);
		$this->expectExceptionMessageMatches("/^Invalid Parameters file '.*broken\.xml': \(line: \d+\) /");

		new Parameters($sPath);
	}

	public function testMergeOverridesRecursivelyAndListsByIndex(): void
	{
		$oBase = new Parameters($this->WriteXml('base.xml', '
			<kept>base</kept><replaced>base</replaced>
			<section><a>base</a><b>base</b></section>
			<a_list type="array"><item>x</item><item>y</item></a_list>
		'));
		$oBase->Merge(new Parameters($this->WriteXml('override.xml', '
			<replaced>override</replaced><added>override</added>
			<section><b>override</b></section>
			<a_list type="array"><item>z</item></a_list>
		')));

		$this->assertSame('base', $oBase->Get('kept'));
		$this->assertSame('override', $oBase->Get('replaced'));
		$this->assertSame('override', $oBase->Get('added'));
		$this->assertSame(['a' => 'base', 'b' => 'override'], $oBase->Get('section'));
		// Known behaviour KB-21: lists are merged by index, so a shorter list does not replace a longer one
		$this->assertSame(['z', 'y'], $oBase->Get('a_list'));
	}

	public function testDumpGivesTheXmlOfTheConfiguration(): void
	{
		$oParameters = new Parameters($this->WriteXml('params.xml', '<a>1</a><b><c>2</c></b><d type="array"><item>3</item></d>'));

		$this->assertSame(
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<parameters>\n  <a>1</a>\n  <b>\n    <c>2</c>\n  </b>\n  <d type=\"array\">\n    <item>3</item>\n  </d>\n</parameters>\n",
			$oParameters->Dump()
		);
	}

	/////////////////////////////////////////////////////////////////////////
	// Files and their precedence

	public function testFilesAreMergedInOrderAndTheLocalFileWins(): void
	{
		$this->WriteCollectorFile('params.distrib.xml', '<?xml version="1.0"?><parameters><level>collector</level><from_collector>yes</from_collector></parameters>');
		$this->WriteCollectorFile('extensions/params.distrib.xml', '<?xml version="1.0"?><parameters><level>extension</level><from_extension>yes</from_extension></parameters>');
		file_put_contents(CONF_DIR.'params.local.xml', '<?xml version="1.0"?><parameters><level>local</level></parameters>');

		$this->LoadConfiguration();

		$this->assertSame('local', Utils::GetConfigurationValue('level'));
		$this->assertSame('yes', Utils::GetConfigurationValue('from_collector'));
		$this->assertSame('yes', Utils::GetConfigurationValue('from_extension'));
		$this->assertSame('Y-m-d H:i:s', Utils::GetConfigurationValue('date_format'), 'From conf/params.distrib.xml');
		$this->assertSame([
			CONF_DIR.'params.distrib.xml',
			APPROOT.'collectors/params.distrib.xml',
			APPROOT.'collectors/extensions/params.distrib.xml',
			CONF_DIR.'params.local.xml',
		], Utils::GetConfigFiles());
	}

	public function testConfigFileOptionReplacesTheLocalFile(): void
	{
		file_put_contents(CONF_DIR.'params.local.xml', '<?xml version="1.0"?><parameters><level>local</level><only_local>yes</only_local></parameters>');
		$sConfigFile = $this->WriteXml('custom.xml', '<level>custom</level>');
		$this->SetCliArguments(["--config_file=$sConfigFile"]);

		$this->LoadConfiguration();

		$this->assertSame('custom', Utils::GetConfigurationValue('level'));
		$this->assertSame('', Utils::GetConfigurationValue('only_local'), 'params.local.xml is not read at all');
		$this->assertSame([CONF_DIR.'params.distrib.xml', $sConfigFile], Utils::GetConfigFiles());
	}

	public function testMissingConfigFileIsAnError(): void
	{
		$this->SetCliArguments(['--config_file=/no/such/file.xml']);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage("The specified configuration file '/no/such/file.xml' does not exist.");

		$this->LoadConfiguration();
	}

	public function testConfigurationIsLoadedOnFirstUseOnly(): void
	{
		$this->SetNonPublicProperty(Utils::class, 'oConfig', null);
		$this->SetNonPublicProperty(Utils::class, 'aConfigFiles', []);

		$this->assertSame('Y-m-d H:i:s', Utils::GetConfigurationValue('date_format'));
		$this->WriteCollectorFile('params.distrib.xml', '<?xml version="1.0"?><parameters><date_format>d/m/Y</date_format></parameters>');

		$this->assertSame('Y-m-d H:i:s', Utils::GetConfigurationValue('date_format'), 'Files written after the first read are ignored');
		$this->assertSame([CONF_DIR.'params.distrib.xml'], Utils::GetConfigFiles());
	}

	public function testEachLoadAppendsToTheListOfLoadedFiles(): void
	{
		// Known behaviour KB-22: Utils::LoadConfig() never resets the list it appends to
		$this->SetNonPublicProperty(Utils::class, 'aConfigFiles', []);
		Utils::LoadConfig();
		Utils::LoadConfig();

		$this->assertSame([CONF_DIR.'params.distrib.xml', CONF_DIR.'params.distrib.xml'], Utils::GetConfigFiles());
	}

	/////////////////////////////////////////////////////////////////////////
	// Values

	public function testPlaceholdersAreSubstitutedRecursively(): void
	{
		$this->SetConfigurationOverrides([
			'url' => 'https://$host$/$path$',
			'host' => '$sub$.example.com',
			'sub' => 'www',
			'path' => 'itop',
			'section' => ['link' => '$host$', 'list' => ['$path$', 'plain']],
			'broken' => 'x-$nope$',
		]);

		$this->assertSame('https://www.example.com/itop', Utils::GetConfigurationValue('url'));
		$this->assertSame(['link' => 'www.example.com', 'list' => ['itop', 'plain']], Utils::GetConfigurationValue('section'));
		$this->assertSame('x-#ERROR_UNDEFINED_PLACEHOLDER_nope#', Utils::GetConfigurationValue('broken'));
	}

	public function testCollectorSectionIsLookedUpWithTheExactCaseThenInLowercase(): void
	{
		ScriptableCollector::Reset();
		ScriptableCollector::$aScripts[ScriptableCollectorOne::class] = ['skip_init' => true];
		$oCollector = new ScriptableCollectorOne();

		$this->SetConfigurationOverrides(['scriptablecollectorone' => ['from' => 'lowercase']]);
		$oCollector->ReadCollectorConfig();
		$this->assertSame(['from' => 'lowercase'], $this->GetNonPublicProperty($oCollector, 'aCollectorConfig'));

		$this->SetConfigurationOverrides(['ScriptableCollectorOne' => ['from' => 'exact case']]);
		$oCollector->ReadCollectorConfig();
		$this->assertSame(['from' => 'exact case'], $this->GetNonPublicProperty($oCollector, 'aCollectorConfig'));
	}

	public function testDataPathSupportsTheApprootPlaceholderAndIsCreated(): void
	{
		$this->SetConfigurationOverrides(['data_path' => $this->GetTempDir().'/new/dir//']);

		$this->assertSame($this->GetTempDir().'/new/dir/file.csv', Utils::GetDataFilePath('some/path/file.csv'));
		$this->assertDirectoryExists($this->GetTempDir().'/new/dir');

		$this->SetConfigurationOverrides(['data_path' => '%APPROOT%/data']);
		$this->assertSame(APPROOT.'/data/file.csv', Utils::GetDataFilePath('file.csv'));
	}

	public function testAnEmptyCurlOptionsElementCrashesTheHttpCalls(): void
	{
		// Known behaviour KB-20: <curl_options/> gives '' (not an empty hash), which replaces the default options
		$this->SetConfigurationOverrides(['curl_options' => '']);

		$this->expectException(\TypeError::class);

		Utils::GetCurlOptions();
	}

	/////////////////////////////////////////////////////////////////////////
	// Command line

	public function testCommandLineParameters(): void
	{
		$this->SetCliArguments(['--max_chunk_size=10', '--max_chunk_size=20', '--collect_only', '--synchro_only=0', '--dump_config_only=yes']);

		$this->assertSame('20', Utils::ReadParameter('max_chunk_size', 1000), 'The last occurrence wins; values are strings');
		$this->assertSame('default', Utils::ReadParameter('config_file', 'default'));
		$this->assertTrue(Utils::ReadBooleanParameter('collect_only', false));
		$this->assertFalse(Utils::ReadBooleanParameter('synchro_only', true));
		$this->assertTrue(Utils::ReadBooleanParameter('dump_config_only', false), 'Any value other than 0 means true');
		$this->assertFalse(Utils::ReadBooleanParameter('configure_only', false));
	}

	public function testUnknownOrMistypedCommandLineParametersAreReported(): void
	{
		$this->SetCliArguments(['--collect_only', '--max_chunk_size=5', '--unknown', '--collect_only=1', '--max_chunk_size', 'positional']);

		$aUnknown = Utils::CheckParameters(['collect_only' => 'boolean', 'max_chunk_size' => 'integer']);

		$this->assertSame(['--unknown', '--collect_only=1', '--max_chunk_size', 'positional'], $aUnknown);
	}

	public function testConsoleLogLevelFromTheCommandLineIsAString(): void
	{
		$this->SetConfigurationOverrides(['console_log_level' => '4']);
		Utils::InitConsoleLogLevel();
		$this->assertSame('4', Utils::$iConsoleLogLevel);

		$this->SetCliArguments(['--console_log_level=7']);
		Utils::InitConsoleLogLevel();
		$this->assertSame('7', Utils::$iConsoleLogLevel, 'Collector::Synchronize() compares it as a string with "7"');
	}
}
