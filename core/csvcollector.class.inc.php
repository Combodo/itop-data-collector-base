<?php

// Copyright (C) 2014 Combodo SARL
//
//   This application is free software; you can redistribute it and/or modify
//   it under the terms of the GNU Affero General Public License as published by
//   the Free Software Foundation, either version 3 of the License, or
//   (at your option) any later version.
//
//   iTop is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU Affero General Public License for more details.
//
//   You should have received a copy of the GNU Affero General Public License
//   along with this application. If not, see <http://www.gnu.org/licenses/>

/**
 * Base class for creating collectors which retrieve their data via a CSV files
 *
 * The minimum implementation for such a collector consists in:
 * - creating a class derived from CSVCollector
 * - configuring a CLI command <name_of_the_collector_class>_command : executed before reading CSV file
 * - configuring a CSV file path as <name_of_the_collector_class>_csv
 * - configuring a CSV encoding as <name_of_the_collector_class>_encoding
 */
abstract class CSVCollector extends Collector
{
	protected $iIdx = 0;
	protected $aCsvFieldsPerLine = array();
	protected $sCsvSeparator;
	protected $sCsvEncoding;
	protected $bHasHeader = true;
	protected $sCsvCliCommand;
	protected $aSynchroColumns;
	protected $aSynchroFieldsToDefaultValues = array();
	protected $aConfiguredHeaderColumns;
	protected $aMappingCsvToSynchro = array();
	protected $aIgnoredCsvColumns = array();
	protected $aIgnoredSynchroFields = array();

	/**
	 * Initalization
	 *
	 * @throws Exception
	 */
	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * Parses configured csv file to fetch data
	 *
	 * @see Collector::Prepare()
	 * @throws Exception
	 */
	public function Prepare()
	{
		$bRet = parent::Prepare();

		$this->sCsvSeparator = ';';
		$this->sCsvEncoding = 'UTF-8';
		$this->sCsvCliCommand = '';
		$this->aSynchroFieldsToDefaultValues = array();
		$this->bHasHeader = true;

		if (is_array($this->aCollectorConfig)) {
			if (array_key_exists('csv_file', $this->aCollectorConfig)) {
				$sCsvFilePath = $this->aCollectorConfig['csv_file'];
			}

			if (array_key_exists('separator', $this->aCollectorConfig)) {
				$this->sCsvSeparator = $this->aCollectorConfig['separator'];
				if ($this->sCsvSeparator === 'TAB') {
					$this->sCsvSeparator = "\t";
				}
			}
			if (array_key_exists('encoding', $this->aCollectorConfig)) {
				$this->sCsvEncoding = $this->aCollectorConfig['encoding'];
			}
			if (array_key_exists('command', $this->aCollectorConfig)) {
				$this->sCsvCliCommand = $this->aCollectorConfig['command'];
			}
			if (array_key_exists('has_header', $this->aCollectorConfig)) {
				$this->bHasHeader = ($this->aCollectorConfig['has_header'] !== 'no');
			}


			if (array_key_exists('defaults', $this->aCollectorConfig)) {
				if ($this->aCollectorConfig['defaults'] !== '') {
					$this->aSynchroFieldsToDefaultValues = $this->aCollectorConfig['defaults'];
					if (!is_array($this->aSynchroFieldsToDefaultValues)) {
						Utils::Log(LOG_ERR,
							"[".get_class($this)."] defaults section configuration is not correct. please see documentation.");

						return false;
					}
				}
			}

			if (array_key_exists('ignored_columns', $this->aCollectorConfig)) {
				if ($this->aCollectorConfig['ignored_columns'] !== '') {
					if (!is_array($this->aCollectorConfig['ignored_columns'])) {
						Utils::Log(LOG_ERR,
							"[".get_class($this)."] ignored_columns section configuration is not correct. please see documentation.");

						return false;
					}
					$this->aIgnoredCsvColumns = array_values($this->aCollectorConfig['ignored_columns']);
				}
			}

			if (array_key_exists('fields', $this->aCollectorConfig)) {
				if ($this->aCollectorConfig['fields'] !== '') {
					$aCurrentConfiguredHeaderColumns = $this->aCollectorConfig['fields'];
					if (!is_array($aCurrentConfiguredHeaderColumns)) {
						Utils::Log(LOG_ERR,
							"[".get_class($this)."] fields section configuration is not correct. please see documentation.");

						return false;
					}

					array_multisort($aCurrentConfiguredHeaderColumns);
					$this->aConfiguredHeaderColumns = array_keys($aCurrentConfiguredHeaderColumns);

					if ($this->bHasHeader) {
						foreach ($aCurrentConfiguredHeaderColumns as $sSynchroField => $sCsvColumn) {
							$this->aMappingCsvToSynchro[$sCsvColumn] = $sSynchroField;
						}
					}
				}
			}
		}

		if ($sCsvFilePath === '') {
			// No query at all !!
			Utils::Log(LOG_ERR,
				"[".get_class($this)."] no CSV file configured! Cannot collect data. The csv was expected to be configured as '".strtolower(get_class($this))."_csv' in the configuration file.");

			return false;
		}

		Utils::Log(LOG_INFO, "[".get_class($this)."] CSV file is [".$sCsvFilePath."]");
		Utils::Log(LOG_DEBUG, "[".get_class($this)."] Has cs header [".$this->bHasHeader."]");
		Utils::Log(LOG_DEBUG, "[".get_class($this)."] Separator used is [".$this->sCsvSeparator."]");
		Utils::Log(LOG_DEBUG, "[".get_class($this)."] Encoding used is [".$this->sCsvEncoding."]");
		Utils::Log(LOG_DEBUG, "[".get_class($this)."] Fields [".var_export($this->aConfiguredHeaderColumns, true)."]");
		Utils::Log(LOG_DEBUG, "[".get_class($this)."] Ignored csv fields [".var_export($this->aIgnoredCsvColumns, true)."]");
		Utils::Log(LOG_DEBUG, "[".get_class($this)."] Default values [".var_export($this->aSynchroFieldsToDefaultValues, true)."]");

		if (!empty($this->sCsvCliCommand)) {
			utils::Exec($this->sCsvCliCommand);
		}

		try {
			$hHandle = fopen($sCsvFilePath, "r");
		} catch (Exception $e) {
			Utils::Log(LOG_INFO, "[".get_class($this)."] Cannot open CSV file $sCsvFilePath");
			$sCsvFilePath = APPROOT.$sCsvFilePath;
			try {
				$hHandle = fopen($sCsvFilePath, "r");
			} catch (Exception $e) {
				Utils::Log(LOG_ERR, "[".get_class($this)."] Cannot open CSV file $sCsvFilePath");

				return false;
			}
		}

		if (!$hHandle) {
			Utils::Log(LOG_ERR, "[".get_class($this)."] Cannot use CSV file handle for $sCsvFilePath");

			return false;
		}

		$sTmpFile = tempnam(sys_get_temp_dir(), "decoded_");
		file_put_contents($sTmpFile, iconv($this->sCsvEncoding, $this->GetCharset(), stream_get_contents($hHandle)));
		$oTmpHandle = fopen($sTmpFile, "r");

		while (($aData = fgetcsv($oTmpHandle, 0, $this->sCsvSeparator)) !== false) {
			$this->aCsvFieldsPerLine[] = $aData;
		}

		fclose($oTmpHandle);
		unlink($sTmpFile);

		return $bRet;
	}

	/**
	 * @return NextLineObject
	 */
	public function getNextLine()
	{
		$aValues = $this->aCsvFieldsPerLine[$this->iIdx];
		$sCsvLine = implode($this->sCsvSeparator, $aValues);

		return new NextLineObject($sCsvLine, $aValues);
	}

	/**
	 * Fetches one csv row at a time
	 * The first row is used to check if the columns of the result match the expected "fields"
	 *
	 * @see Collector::Fetch()
	 * @throws Exception
	 */
	public function Fetch()
	{
		$iCount = count($this->aCsvFieldsPerLine);
		if (($iCount == 0) || (($iCount == 1) && $this->bHasHeader)) {
			Utils::Log(LOG_ERR, "[".get_class($this)."] CSV file is empty. Data collection stops here.");

			return false;
		}
		if ($this->iIdx >= $iCount) {

			return false;
		}

		/** NextLineObject**/
		$oNextLineArr = $this->getNextLine();

		if (!$this->aSynchroColumns) {
			$aCsvHeaderColumns = $oNextLineArr->getValues();

			$this->Configure($aCsvHeaderColumns);
			$this->CheckColumns(array_fill_keys($this->aSynchroColumns, ''), [], 'csv file');

			if ($this->bHasHeader) {
				$this->iIdx++;
				/** NextLineObject**/
				$oNextLineArr = $this->getNextLine();
			}
		}

		$iColumnSize = count($this->aSynchroColumns);
		$iLineSize = count($oNextLineArr->getValues());
		if ($iColumnSize !== $iLineSize) {
			$line = $this->iIdx + 1;
			Utils::Log(LOG_ERR,
				"[".get_class($this)."] Wrong number of columns ($iLineSize) on line $line (expected $iColumnSize columns just like in header): ".$oNextLineArr->getCsvLine());
			throw new Exception("Invalid CSV file.");
		}

		$aData = array();
		$i = 0;
		foreach ($oNextLineArr->getValues() as $sVal) {
			$sSynchroColumn = $this->aSynchroColumns[$i];
			$i++;
			if (array_key_exists($sSynchroColumn, $this->aSynchroFieldsToDefaultValues)) {
				if (empty($sVal)) {
					$aData[$sSynchroColumn] = $this->aSynchroFieldsToDefaultValues[$sSynchroColumn];
				} else {
					$aData[$sSynchroColumn] = $sVal;
				}
			} else {
				if (!in_array($sSynchroColumn, $this->aIgnoredSynchroFields)) {
					$aData[$sSynchroColumn] = $sVal;
				}
			}
		}

		foreach ($this->aSynchroFieldsToDefaultValues as $sAttributeId => $sAttributeValue) {
			if (!array_key_exists($sAttributeId, $aData)) {
				$aData[$sAttributeId] = $sAttributeValue;
			}
		}

		$this->iIdx++;

		return $aData;
	}

	/**
	 * @param $aCsvHeaderColumns
	 */
	protected function Configure($aCsvHeaderColumns)
	{
		if ($this->bHasHeader) {
			$this->aSynchroColumns = array();
			foreach ($aCsvHeaderColumns as $sCsvColumn) {
				if (array_key_exists($sCsvColumn, $this->aMappingCsvToSynchro)) {
					//use mapping instead of csv header sSynchroColumn
					$this->aSynchroColumns[] = $this->aMappingCsvToSynchro[$sCsvColumn];
				} else {
					$this->aSynchroColumns[] = $sCsvColumn;
					$this->aMappingCsvToSynchro[$sCsvColumn] = $sCsvColumn;
				}
			}
		} else {
			$this->aSynchroColumns = $this->aConfiguredHeaderColumns;
		}

		foreach ($this->aIgnoredCsvColumns as $sIgnoredCsvColumn) {
			$this->aIgnoredSynchroFields[] = ($this->bHasHeader) ? $this->aMappingCsvToSynchro[$sIgnoredCsvColumn] : $this->aSynchroColumns[$sIgnoredCsvColumn - 1];
		}
	}

	/**
	 * @inheritdoc
	 */
	protected function CheckColumns($aSynchroColumns, $aColumnsToIgnore, $sSource)
	{
		Utils::Log(LOG_DEBUG, "[".get_class($this)."] Columns [".var_export($aSynchroColumns, true)."]");
		foreach ($this->aFields as $sSynchroColumn => $aDefs) {
			if (array_key_exists($sSynchroColumn, $this->aSynchroFieldsToDefaultValues) || in_array($sSynchroColumn, $this->aIgnoredSynchroFields)) {
				$aColumnsToIgnore[] = $sSynchroColumn;
			}
		}

		parent::CheckColumns($aSynchroColumns, $aColumnsToIgnore, $sSource);
	}
}

class NextLineObject
{
	private $sCsvLine;
	private $aValues;

	/**
	 * NextLineObject constructor.
	 *
	 * @param $csv_line
	 * @param $aValues
	 */
	public function __construct($sCsvLine, $aValues)
	{
		$this->sCsvLine = $sCsvLine;
		$this->aValues = $aValues;
	}

	/**
	 * @return mixed
	 */
	public function getCsvLine()
	{
		return $this->sCsvLine;
	}

	/**
	 * @return mixed
	 */
	public function getValues()
	{
		return $this->aValues;
	}
}
