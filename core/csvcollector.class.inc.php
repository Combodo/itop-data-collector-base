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
 * - configuring a CSV file path as <name_of_the_collector_class>_csv
 * sql_engine: Which PDO DB driver to use (defaults to mysql)
 */
abstract class CSVCollector extends Collector
{
    protected $csv_lines = array();
    protected $csv_separator ;
    protected $csv_encoding ;
    protected $columns;
    protected $csv_clicommand;

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
        $this->csv_separator = Utils::GetConfigurationValue(get_class($this)."_separator", '');
        if ($this->csv_separator == '')
        {
            // Try all lowercase
            $this->csv_separator = Utils::GetConfigurationValue(strtolower(get_class($this))."_separator", ';');
        }
        Utils::Log(LOG_INFO, "[".get_class($this)."] Separator used is [". $this->csv_separator . "]");

        $this->csv_encoding = Utils::GetConfigurationValue(get_class($this)."_encoding", '');
        if ($this->csv_encoding == '')
        {
            // Try all lowercase
            $this->csv_encoding = Utils::GetConfigurationValue(strtolower(get_class($this))."_encoding", 'UTF-8');
        }
        Utils::Log(LOG_INFO, "[".get_class($this)."] Encoding used is [". $this->csv_encoding . "]");

        $this->csv_clicommand = Utils::GetConfigurationValue(get_class($this)."_command", '');
        if ($this->csv_clicommand == '')
        {
            // Try all lowercase
            $this->csv_clicommand = Utils::GetConfigurationValue(strtolower(get_class($this))."_command", '');
        }
        Utils::Log(LOG_INFO, "[".get_class($this)."] CLI command used is [". $this->csv_clicommand . "]");

        // Read the SQL query from the configuration
        $csvFilePath = APPROOT . Utils::GetConfigurationValue(get_class($this)."_csv", '');
        if ($csvFilePath == '')
        {
            // Try all lowercase
            $csvFilePath = APPROOT . Utils::GetConfigurationValue(strtolower(get_class($this))."_csv", '');
        }
        if ($csvFilePath == '')
        {
            // No query at all !!
            Utils::Log(LOG_ERR, "[".get_class($this)."] no CSV file configured! Cannot collect data. The csv was expected to be configured as '".strtolower(get_class($this))."_csv' in the configuration file.");
            return false;
        }

        if (!is_file($csvFilePath))
        {
            Utils::Log(LOG_ERR, "[".get_class($this)."] Cannot find CSV file $csvFilePath");
            return false;
        }

        if (!is_readable($csvFilePath))
        {
            Utils::Log(LOG_ERR, "[".get_class($this)."] Cannot read CSV file $csvFilePath");
            return false;
        }

        if (!empty($this->csv_clicommand))
        {
            $this->Exec($this->csv_clicommand);
        }

        $handle = fopen($csvFilePath, "r");
        if (!$handle) {
            Utils::Log(LOG_ERR, "[" . get_class($this) . "] Handle issue with file $csvFilePath");
            return false;
        }

        while (($line = fgets($handle)) !== false) {
            $this->csv_lines[] = rtrim(iconv($this->csv_encoding,$this->GetCharset(), $line), "\n");
        }

        fclose($handle);
        $this->idx = 0;
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
    function Exec($cmd) {
        $iBeginTime = time();
        $workdir = APPROOT;
        $descriptorspec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "w"),  // stderr
        );
        $process = proc_open($cmd, $descriptorspec, $pipes, $workdir, null);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $code = proc_close($process);

        $iElapsed = time() - $iBeginTime;
        Utils::Log(LOG_INFO, "Command: $cmd. Workdir: $workdir");
        if (0 === $code) {
            Utils::Log(LOG_INFO, "elapsed:${iElapsed}s output: $stdout");
            return $stdout;
        } else {
            throw new Exception("Command failed : $cmd \n\t\t=== with status:$code \n\t\t=== stderr:$stderr \n\t\t=== stdout: $stdout");
        }
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
        if ($this->idx >= sizeof($this->csv_lines))
        {
            return false;
        }

        /** NextLineObject**/ $next_line_arr = $this->get_next_line($this->idx);

        if (! $this->columns)
        {
            $aChecks = $this->CheckSQLCsvHeaders($next_line_arr->getValues());
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
            $this->columns = array_merge($next_line_arr->getValues());
            $this->idx++;
        }

        /** NextLineObject**/ $next_line_arr = $this->get_next_line($this->idx);
        $column_size = sizeof($this->columns);
        $line_size = sizeof($next_line_arr->getValues());
        if ($column_size !== $line_size)
        {
            $line = $this->idx + 1;
            Utils::Log(LOG_ERR, "[" . get_class($this) . "] Wrong number of columns ($line_size) on line $line (expected $column_size columns just like in header): " . $next_line_arr->getCsvLine());
            throw new Exception("Invalid CSV file.");
        }

        $aData = array();
        $i=0;
        foreach ($next_line_arr->getValues() as $val)
        {
            $column = $this->columns[$i];
            if (!array_key_exists($column, $this->aSkippedAttributes))
            {
                $aData[$column] = $val;
            }
            $i++;
        }

        $this->idx++;
        return $aData;
    }

    /**
     * @return array
     */
    public function get_next_line()
    {
        $csv_line = $this->csv_lines[$this->idx];
        $aValues = explode($this->csv_separator, $csv_line);
        return new NextLineObject($csv_line, $aValues);
    }
}

class NextLineObject
{
    private $csv_line;
    private $aValues;

    /**
     * NextLineObject constructor.
     * @param $csv_line
     * @param $aValues
     */
    public function __construct($csv_line, $aValues)
    {
        $this->csv_line = $csv_line;
        $this->aValues = $aValues;
    }

    /**
     * @return mixed
     */
    public function getCsvLine()
    {
        return $this->csv_line;
    }

    /**
     * @return mixed
     */
    public function getValues()
    {
        return $this->aValues;
    }
}
