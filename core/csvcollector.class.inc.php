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

Orchestrator::AddRequirement('5.6.0'); // Minimum PHP version to get PDO support

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
    protected $aCsvLines = array();
    protected $sCsvSeparator ;
    protected $sCsvEncoding ;
    protected $aColumns;
    protected $sCsvCliCommand;
    protected $aAttributeValues = array();
    protected $aIgnoredAttributes = array();
    protected $aConfiguredHeaderColumns;

    /**
	 * Initalization
	 */
	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * Parses configured csv file to fetch data
	 * @see Collector::Prepare()
	 */
	public function Prepare()
	{
		$bRet = parent::Prepare();

        // Read the SQL query from the configuration
        $this->sCsvSeparator = Utils::GetConfigurationValue(get_class($this)."_separator", '');
        if ($this->sCsvSeparator == '')
        {
            // Try all lowercase
            $this->sCsvSeparator = Utils::GetConfigurationValue(strtolower(get_class($this))."_separator", ';');
        }
        Utils::Log(LOG_INFO, "[".get_class($this)."] Separator used is [". $this->sCsvSeparator . "]");

        $this->sCsvEncoding = Utils::GetConfigurationValue(get_class($this)."_encoding", '');
        if ($this->sCsvEncoding == '')
        {
            // Try all lowercase
            $this->sCsvEncoding = Utils::GetConfigurationValue(strtolower(get_class($this))."_encoding", 'UTF-8');
        }
        Utils::Log(LOG_INFO, "[".get_class($this)."] Encoding used is [". $this->sCsvEncoding . "]");

        $this->aAttributeValues = Utils::GetConfigurationValue(get_class($this)."_attribute_values", null);
        if ($this->aAttributeValues === null)
        {
            // Try all lowercase
            $this->aAttributeValues = Utils::GetConfigurationValue(strtolower(get_class($this))."_attribute_values", null);
            if ($this->aAttributeValues === null) {
                $this->aAttributeValues = array();
            }
        }

        $aCurrentConfiguredHeaderColumns = Utils::GetConfigurationValue(get_class($this)."_header_columns", null);
        if ($aCurrentConfiguredHeaderColumns === null)
        {
            // Try all lowercase
            $aCurrentConfiguredHeaderColumns = Utils::GetConfigurationValue(strtolower(get_class($this))."_header_columns", null);
        }
        if (is_array($aCurrentConfiguredHeaderColumns))
        {
            array_multisort($aCurrentConfiguredHeaderColumns);
            $this->aConfiguredHeaderColumns = array_keys($aCurrentConfiguredHeaderColumns);
        }

        $this->aIgnoredAttributes = Utils::GetConfigurationValue(get_class($this)."_ignored_attributes", null);
        if ($this->aIgnoredAttributes === null)
        {
            // Try all lowercase
            $this->aIgnoredAttributes = Utils::GetConfigurationValue(strtolower(get_class($this))."_ignored_attributes", null);
            if ($this->aIgnoredAttributes === null) {
                $this->aIgnoredAttributes = array();
            }
        }

        $this->sCsvCliCommand = Utils::GetConfigurationValue(get_class($this)."_command", '');
        if ($this->sCsvCliCommand == '')
        {
            // Try all lowercase
            $this->sCsvCliCommand = Utils::GetConfigurationValue(strtolower(get_class($this))."_command", '');
        }
        Utils::Log(LOG_INFO, "[".get_class($this)."] CLI command used is [". $this->sCsvCliCommand . "]");

        // Read the SQL query from the configuration
        $sCsvFilePath = Utils::GetConfigurationValue(get_class($this)."_csv", '');
        if ($sCsvFilePath == '')
        {
            // Try all lowercase
            $sCsvFilePath = Utils::GetConfigurationValue(strtolower(get_class($this))."_csv", '');
        }
        if ($sCsvFilePath == '')
        {
            // No query at all !!
            Utils::Log(LOG_ERR, "[".get_class($this)."] no CSV file configured! Cannot collect data. The csv was expected to be configured as '".strtolower(get_class($this))."_csv' in the configuration file.");
            return false;
        }

        if (!is_file($sCsvFilePath))
        {
            Utils::Log(LOG_INFO, "[".get_class($this)."] CSV file not found in [". $sCsvFilePath . "]");
            $sCsvFilePath = APPROOT . $sCsvFilePath;
            if (!is_file($sCsvFilePath)) {
                Utils::Log(LOG_ERR, "[" . get_class($this) . "] Cannot find CSV file $sCsvFilePath");
                return false;
            }
        }

        if (!is_readable($sCsvFilePath))
        {
            Utils::Log(LOG_ERR, "[".get_class($this)."] Cannot read CSV file $sCsvFilePath");
            return false;
        }

        if (!empty($this->sCsvCliCommand))
        {
            $this->Exec($this->sCsvCliCommand);
        }

        $hHandle = fopen($sCsvFilePath, "r");
        if (!$hHandle) {
            Utils::Log(LOG_ERR, "[" . get_class($this) . "] Handle issue with file $sCsvFilePath");
            return false;
        }

        while (($sLine = fgets($hHandle)) !== false) {
            $this->aCsvLines[] = rtrim(iconv($this->sCsvEncoding,$this->GetCharset(), $sLine), "\n\r");
        }

        fclose($hHandle);
        $this->iIdx = 0;
		return $bRet;
    }

    /**
     * Executes a command and returns an array with exit code, stdout and stderr content
     *
     * @param string $cmd - Command to execute
     *
     * @return string[] - Array with keys: 'code' - exit code, 'out' - stdout, 'err' - stderr
     * @throws \Exception
     */
    function Exec($sCmd) {
        $iBeginTime = time();
        $sWorkDir = APPROOT;
        $aDescriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "w"),  // stderr
        );
        $rProcess = proc_open($sCmd, $aDescriptorSpec, $aPipes, $sWorkDir, null);

        $sStdOut = stream_get_contents($aPipes[1]);
        fclose($aPipes[1]);

        $sStdErr = stream_get_contents($aPipes[2]);
        fclose($aPipes[2]);

        $iCode = proc_close($rProcess);

        $iElapsed = time() - $iBeginTime;
        Utils::Log(LOG_INFO, "Command: $sCmd. Workdir: $sWorkDir");
        if (0 === $iCode) {
            Utils::Log(LOG_INFO, "elapsed:${iElapsed}s output: $sStdOut");
            return $sStdOut;
        } else {
            throw new Exception("Command failed : $sCmd \n\t\t=== with status:$iCode \n\t\t=== stderr:$sStdErr \n\t\t=== stdout: $sStdOut");
        }
    }

    /**
     * Determine if a given attribute is allowed to be missing in the data datamodel.
     *
     * The implementation is based on a predefined configuration parameter named from the
     * class of the collector (all lowercase) with _ignored_attributes appended.
     *
     * Example: here is the configuration to "ignore" the attribute 'location_id' for the class MyJSONCollector:
     * <myjsoncollector_ignored_attributes type="array">
     *    <attribute>location_id</attribute>
     * </myjsoncollector_ignored_attributes>
     * @param string $sAttCode
     * @return boolean True if the attribute can be skipped, false otherwise
     */
    public function AttributeIsOptional($sAttCode)
    {
        $aIgnoredAttributes = Utils::GetConfigurationValue(get_class($this) . "_ignored_attributes", null);
        if ($aIgnoredAttributes === null)
        {
            // Try all lowercase
            $aIgnoredAttributes = Utils::GetConfigurationValue(strtolower(get_class($this)) . "_ignored_attributes", null);
        }
        if (is_array($aIgnoredAttributes))
        {
            if (in_array($sAttCode, $aIgnoredAttributes)) return true;
        }

        return parent::AttributeIsOptional($sAttCode);
    }

    /**
     * Check if the keys of the supplied hash array match the expected fields
     * @param array $aData
     * @return array A hash array with two entries: 'errors' => array of strings and 'warnings' => array of strings
     */
    protected function CheckSQLCsvHeaders($aData)
    {
        $aRet = array('errors' => array(), 'warnings' => array());

        if(!in_array('primary_key', $aData))
        {
            $aRet['errors'][] = 'The mandatory column "primary_key" is missing from the csv.';
        }
        foreach($this->aFields as $sCode => $aDefs)
        {
            if (array_key_exists($sCode, $this->aAttributeValues)
                || $this->AttributeIsOptional($sCode))
            {
                continue;
            }

            // Check for missing columns
            if (!in_array($sCode, $aData) && $aDefs['reconcile'])
            {
                $aRet['errors'][] = 'The column "'.$sCode.'", used for reconciliation, is missing from the csv.';
            }
            else if (!in_array($sCode, $aData) && $aDefs['update'])
            {
                $aRet['errors'][] = 'The column "'.$sCode.'", used for update, is missing from the csv.';
            }

            // Check for useless columns
            if (in_array($sCode, $aData) && !$aDefs['reconcile']  && !$aDefs['update'])
            {
                $aRet['warnings'][] = 'The column "'.$sCode.'" is used neither for update nor for reconciliation.';
            }

        }
        return $aRet;
    }


    /**
     * Fetches one csv row at a time
     * The first row is used to check if the columns of the result match the expected "fields"
     * @see Collector::Fetch()
     */
    public function Fetch()
    {
        if ($this->iIdx >= sizeof($this->aCsvLines))
        {
            return false;
        }

        /** NextLineObject**/ $oNextLineArr = $this->getNextLine();

        if (! $this->aColumns)
        {
            if (is_array($this->aConfiguredHeaderColumns))
            {
                $aCurrentColumns = $this->aConfiguredHeaderColumns;
            }
            else
            {
                $aCurrentColumns = $oNextLineArr->getValues();
                $this->iIdx++;
            }

            $aChecks = $this->CheckSQLCsvHeaders($aCurrentColumns);
            foreach($aChecks['errors'] as $sError)
            {
                Utils::Log(LOG_ERR, "[".get_class($this)."] $sError");
            }
            foreach($aChecks['warnings'] as $sWarning)
            {
                Utils::Log(LOG_WARNING, "[".get_class($this)."] $sWarning");
            }
            if(count($aChecks['errors']) > 0)
            {
                throw new Exception("Missing columns in the CSV file.");
            }
            $this->aColumns = array_merge($aCurrentColumns);
        }

        /** NextLineObject**/ $oNextLineArr = $this->getNextLine();
        $iColumnSize = sizeof($this->aColumns);
        $iLineSize = sizeof($oNextLineArr->getValues());
        if ($iColumnSize !== $iLineSize)
        {
            $line = $this->iIdx + 1;
            Utils::Log(LOG_ERR, "[" . get_class($this) . "] Wrong number of columns ($iLineSize) on line $line (expected $iColumnSize columns just like in header): " . $oNextLineArr->getCsvLine());
            throw new Exception("Invalid CSV file.");
        }

        $aData = array();
        $i=0;
        foreach ($oNextLineArr->getValues() as $sVal)
        {
            $column = $this->aColumns[$i];
            if (array_key_exists($column, $this->aAttributeValues))
            {
                $aData[$column] = $this->aAttributeValues[$column];
            }
            else if (!array_key_exists($column, $this->aSkippedAttributes))
            {
                $aData[$column] = $sVal;
            }
            $i++;
        }

        foreach ($this->aAttributeValues as $sAttributeId => $sAttributeValue)
        {
            if (!array_key_exists($sAttributeId, $aData))
            {
                $aData[$sAttributeId] = $sAttributeValue;
            }
        }
        
        $this->iIdx++;
        return $aData;
    }

    /**
     * @return NextLineObject
     */
    public function getNextLine()
    {
        $sCsvLine = $this->aCsvLines[$this->iIdx];
        $aValues = explode($this->sCsvSeparator, $sCsvLine);
        return new NextLineObject($sCsvLine, $aValues);
    }
}

class NextLineObject
{
    private $sCsvLine;
    private $aValues;

    /**
     * NextLineObject constructor.
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
