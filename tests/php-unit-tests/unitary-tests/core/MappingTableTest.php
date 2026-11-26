<?php

namespace UnitTestFiles\Test;

use MappingTable;
use UnitTestFiles\Test\Support\BaseTestCase;

require_once dirname(__DIR__, 2).'/src/BaseTestCase/BaseTestCase.php';

/**
 * MappingTable normalizes values through an ordered list of "<delimiter><regexp><delimiter><replacement>" entries read
 * from the configuration.
 */
class MappingTableTest extends BaseTestCase
{
	private function CreateMappingTable(array $aMapping): MappingTable
	{
		$this->SetConfigurationOverrides(['os_family_mapping' => $aMapping]);

		return new MappingTable('os_family_mapping');
	}

	public function testFirstMatchingPatternWinsAndMatchingIsCaseInsensitive(): void
	{
		$oMapping = $this->CreateMappingTable(['/^win.*/Windows', '/linux/Linux', '/.*/Other']);

		$this->assertSame('Windows', $oMapping->MapValue('WINDOWS Server 2022'));
		$this->assertSame('Windows', $oMapping->MapValue('win linux'));
		$this->assertSame('Linux', $oMapping->MapValue('Ubuntu Linux'));
		$this->assertSame('Other', $oMapping->MapValue('AIX'));
	}

	public function testReplacementPlaceholdersAreShiftedByOne(): void
	{
		// The replacement goes through vsprintf() with the preg_match() matches: %1$s is the whole match and
		// %2$s the first capturing group
		$oMapping = $this->CreateMappingTable(['/^(linux) (\d+)\.\d+/%3$s %2$s (%1$s)']);

		$this->assertSame('5 linux (linux 5.15)', $oMapping->MapValue('linux 5.15.0-generic'));
	}

	public function testUnmatchedValueGivesTheDefault(): void
	{
		$oMapping = $this->CreateMappingTable(['/^win/Windows']);

		$this->assertNull($oMapping->MapValue('AIX'));
		$this->assertSame('Unknown', $oMapping->MapValue('AIX', 'Unknown'));
	}

	public function testAnyDelimiterCanBeUsedButTheReplacementCannotContainIt(): void
	{
		$oMapping = $this->CreateMappingTable(['|^a/b|slash', '/^c/d/e']);

		$this->assertSame('slash', $oMapping->MapValue('a/b'));
		// The last delimiter ends the pattern: '/^c/d/' + replacement 'e', and the pattern is invalid
		$this->assertNull(@$oMapping->MapValue('c'));
	}

	public function testUnicodeIsSupported(): void
	{
		$oMapping = $this->CreateMappingTable(['/^été/Summer']);

		$this->assertSame('Summer', $oMapping->MapValue('ÉTÉ 2026'));
	}

	public function testMissingConfigurationGivesAnEmptyTable(): void
	{
		$oMapping = new MappingTable('no_such_mapping');

		$this->assertSame('default', $oMapping->MapValue('anything', 'default'));
	}
}
