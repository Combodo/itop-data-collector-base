<?php

/**
 * Collector used by the iTop contract scenarios (ItopContractTestCase): it returns the rows set by the test.
 * Its SDS definition is written by the test into collectors/json/ContractTestCollector.json.
 */
class ContractTestCollector extends Collector
{
	/** @var array<int, array<string, ?string>> Rows returned by Fetch(), set by the test */
	public static $aRows = [];

	/** @var int */
	private $iRowIndex = 0;

	protected function Prepare()
	{
		$this->iRowIndex = 0;

		return parent::Prepare();
	}

	protected function Fetch()
	{
		return self::$aRows[$this->iRowIndex++] ?? false;
	}

	/**
	 * @return int|null The id of the data source in iTop, once InitSynchroDataSource() has run
	 */
	public function GetSourceId()
	{
		return $this->iSourceId;
	}
}
