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
    /**
     * @var   array<int, string[]>  Table of number of columns in input csv file corresponding to output fields or input columns names (if the specification never mention the input column)
     * [0 => [synchro_field1, synchro_field2], 1 => [synchro_field3], 2 => [col_name]]
     */
	protected $aMappingCsvColumnIndexToFields;
	protected $aSynchroFieldsToDefaultValues = array();
	protected $aConfiguredHeaderColumns;
    /**
     * @var array<string, string[]>  Mapping of csv columns to synchro fields
     * [column_name => [synchro_field1, synchro_field2], column_name2 => [synchro_field3]]
     */
    protected $aMappingCsvColumnNameToFields = array();
    /**
     * @var array<string, string>  Table of all synchronised fields
     * [synchro_field => '', synchro_field2 => '']
     */
    protected $aMappedFields = array();
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

		$sCsvFilePath='';
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
                    $this->aConfiguredHeaderColumns = [];
                    if ($this->bHasHeader) {
                        array_multisort($aCurrentConfiguredHeaderColumns);
                        $this->aConfiguredHeaderColumns = array_keys($aCurrentConfiguredHeaderColumns);

                        foreach ($aCurrentConfiguredHeaderColumns as $sSynchroField => $sCsvColumn) {
                            $this->aMappingCsvColumnNameToFields[$sCsvColumn][] = $sSynchroField;
                            $this->aMappedFields[$sSynchroField] = '';
                        }
                    } else {
                        $this->aConfiguredHeaderColumns = $aCurrentConfiguredHeaderColumns;
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
        Utils::Log(LOG_DEBUG, "[".get_class($this)."] Has csv header [".($this->bHasHeader?"yes":"no")."]");
		Utils::Log(LOG_DEBUG, "[".get_class($this)."] Separator used is [".$this->sCsvSeparator."]");
		Utils::Log(LOG_DEBUG, "[".get_class($this)."] Encoding used is [".$this->sCsvEncoding."]");
		Utils::Log(LOG_DEBUG, "[".get_class($this)."] Fields [".var_export($this->aConfiguredHeaderColumns, true)."]");
		Utils::Log(LOG_DEBUG, "[".get_class($this)."] Ignored csv fields [".var_export($this->aIgnoredCsvColumns, true)."]");
		Utils::Log(LOG_DEBUG, "[".get_class($this)."] Default values [".var_export($this->aSynchroFieldsToDefaultValues, true)."]");

		if (!empty($this->sCsvCliCommand)) {
            Utils::Exec($this->sCsvCliCommand);
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

		if (!$this->aMappingCsvColumnIndexToFields) {
			$aCsvHeaderColumns = $oNextLineArr->getValues();

			$this->Configure($aCsvHeaderColumns);
			$this->CheckColumns($this->aMappedFields, [], 'csv file');

			if ($this->bHasHeader) {
				$this->iIdx++;
				/** NextLineObject**/
				$oNextLineArr = $this->getNextLine();
			}
		}

		$iColumnSize = count($this->aMappingCsvColumnIndexToFields);
		$iLineSize = count($oNextLineArr->getValues());
		if ($iColumnSize !== $iLineSize) {
			$line = $this->iIdx + 1;
			Utils::Log(LOG_ERR,
				"[".get_class($this)."] Wrong number of columns ($iLineSize) on line $line (expected $iColumnSize columns just like in header): ".$oNextLineArr->getCsvLine());
			throw new Exception("Invalid CSV file.");
		}

		$aData = array();

		foreach ($oNextLineArr->getValues() as $i =>$sVal) {
			$aSynchroFields = $this->aMappingCsvColumnIndexToFields[$i];
            foreach ($aSynchroFields as $sSynchroField) {
                if (array_key_exists($sSynchroField, $this->aSynchroFieldsToDefaultValues)) {
                    if (empty($sVal)) {
                        $aData[$sSynchroField] = $this->aSynchroFieldsToDefaultValues[$sSynchroField];
                    } else {
                        $aData[$sSynchroField] = $sVal;
                    }
                } else {
                    if (!in_array($sSynchroField, $this->aIgnoredSynchroFields)) {
                        $aData[$sSynchroField] = $sVal;
                    }
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
			$this->aMappingCsvColumnIndexToFields = [];

			foreach ($aCsvHeaderColumns as $sCsvColumn) {
				if (array_key_exists($sCsvColumn, $this->aMappingCsvColumnNameToFields)) {
					//use mapping instead of csv header sSynchroColumn
					$this->aMappingCsvColumnIndexToFields[] = $this->aMappingCsvColumnNameToFields[$sCsvColumn];
				} else {
                    if(!array_key_exists($sCsvColumn, $this->aMappedFields)) {
                        $this->aMappingCsvColumnIndexToFields[] = [$sCsvColumn];
                        $this->aMappingCsvColumnNameToFields[$sCsvColumn] = [$sCsvColumn];
                        $this->aMappedFields[$sCsvColumn] = '';
                    } else {
                        $this->aMappingCsvColumnIndexToFields[] = [''];
                        $this->aMappingCsvColumnNameToFields[$sCsvColumn] = [''];
                    }
				}
			}
		} else {
            foreach ($this->aConfiguredHeaderColumns as $sSynchroField => $sCsvColumn) {
                $this->aMappingCsvColumnIndexToFields[$sCsvColumn-1][] = $sSynchroField;
                $this->aMappedFields[$sSynchroField] = '';
            }
            foreach ( $this->aIgnoredCsvColumns as $sCsvColumn) {
                $this->aMappingCsvColumnIndexToFields[$sCsvColumn-1]  = ['ignored_attribute_'.$sCsvColumn];
            }
        }
		foreach ($this->aIgnoredCsvColumns as $sIgnoredCsvColumn) {
			$this->aIgnoredSynchroFields = array_merge( $this->aIgnoredSynchroFields, ($this->bHasHeader) ? $this->aMappingCsvColumnNameToFields[$sIgnoredCsvColumn] : $this->aMappingCsvColumnIndexToFields[$sIgnoredCsvColumn - 1]);
		}

	}

	/**
	 * @inheritdoc
	 */
	protected function CheckColumns($aSynchroColumns, $aColumnsToIgnore, $sSource)
	{
		Utils::Log(LOG_DEBUG, "[".get_class($this)."] Columns [".var_export($aSynchroColumns, true)."]");
		foreach ($this->aFields as $sField => $aDefs) foreach ($aDefs['columns'] as $sSynchroColumn) {
			if (array_key_exists($sSynchroColumn, $this->aSynchroFieldsToDefaultValues) || in_array($sSynchroColumn, $this->aIgnoredSynchroFields)) {
				$aColumnsToIgnore[] = $sField;
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
