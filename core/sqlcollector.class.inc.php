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

Orchestrator::AddRequirement('5.1.0'); // Minimum PHP version to get PDO support

/**
 * Base class for creating collectors which retrieve their data via a SQL query
 * 
 * The minimum implementation for such a collector consists in:
 * - creating a class derived from SQLCollector
 * - configuring a SQL query as <name_of_the_collector_class>_query
 * - configuring the SQL connection parameters:
 * sql_engine: Which PDO DB driver to use (defaults to mysql)
 * sql_host: name/IP address of the database server (defaults to localhost)
 * sql_login: Login to use to connect to the database
 * sql_password: Password to connect to the database
 * sql_database: Name of the database to use for running the query
 */
abstract class SQLCollector extends Collector
{
	protected $oDB;
	protected $oStatement;
	
	/**
	 * Initalization
	 */
	public function __construct()
	{
		parent::__construct();
		$this->oDB = null;
		$this->oStatement = null;
	}
	
	/**
	 * Runs the configured query to start fetching the data from the database
	 * @see Collector::Prepare()
	 */
	public function Prepare()
	{
		$bRet = parent::Prepare();
		if (!$bRet) return false;

		$bRet = $this->Connect(); // Establish the connection to the database
		if (!$bRet) return false;
		
		// Read the SQL query from the configuration
		$sQuery = Utils::GetConfigurationValue(get_class($this)."_query", '');
		if ($sQuery == '')
		{
			// Try all lowercase
			$sQuery = Utils::GetConfigurationValue(strtolower(get_class($this))."_query", '');
		}
		if ($sQuery == '')
		{
			// No query at all !!
			Utils::Log(LOG_ERR, "[".get_class($this)."] no SQL query configured! Cannot collect data. The query was expected to be configured as '".strtolower(get_class($this))."_query' in the configuration file.");
			return false;
		}
		
		
		$this->oStatement =  $this->oDB->prepare($sQuery);
		if ($this->oStatement === false)
		{
			$aInfo = $this->oDB->errorInfo();
			Utils::Log(LOG_ERR, "[".get_class($this)."] Failed to prepare the query: '$sQuery'. Reason: ".$aInfo[0].', '.$aInfo[2]);
			return false;
		}
		
		$bRet = $this->oStatement->execute();
		if ($this->oStatement->errorCode() !== '00000')
		{
			$aInfo = $this->oStatement->errorInfo();
			Utils::Log(LOG_ERR, "[".get_class($this)."] Failed to execute the query: '$sQuery'. Reason: ".$aInfo[0].', '.$aInfo[2]);
			return false;
		}
		
		$this->idx = 0;
		return true;
	}
	
	/**
	 * Establish the connection to the database, based on the configuration parameters.
	 * By default all collectors derived from SQLCollector will share the same connection
	 * parameters (same DB server, login, DB name...). If you don't want this behavior,
	 * overload this method in your connector.
	 */
	protected function Connect()
	{
		$aAvailableDrivers = PDO::getAvailableDrivers();
		
		Utils::Log(LOG_DEBUG, "Available PDO drivers: ".implode(', ', $aAvailableDrivers));
		
	    $sEngine = Utils::GetConfigurationValue('sql_engine', 'mysql');
	    if (!in_array($sEngine, $aAvailableDrivers))
	    {
			Utils::Log(LOG_ERR, "The requested PDO driver: '$sEngine' is not installed on this system. Available PDO drivers: ".implode(', ', $aAvailableDrivers));
	    }
        $sHost = Utils::GetConfigurationValue('sql_host', 'localhost');
        $sDatabase = Utils::GetConfigurationValue('sql_database', '');
        $sLogin = Utils::GetConfigurationValue('sql_login', 'root');
        $sPassword = Utils::GetConfigurationValue('sql_password', '');
        
        $sConnectionStringFormat = Utils::GetConfigurationValue('sql_connection_string', '%1$s:dbname=%2$s;host=%3$s');
        $sConnectionString = sprintf($sConnectionStringFormat, $sEngine, $sDatabase, $sHost);
		
        Utils::Log(LOG_DEBUG, "[".get_class($this)."] Connection string: '$sConnectionString'");
        
		try
		{
        	$this->oDB = new PDO($sConnectionString, $sLogin, $sPassword); 
		}
		catch (PDOException $e)
		{
			Utils::Log(LOG_ERR, "[".get_class($this)."] Database connection failed: ".$e->getMessage());
			$this->oDB = null;
			return false;
		}
		return true;		
	}

	/**
	 * Fetch one row of data from the database
	 * The first row is used to check if the columns of the result match the expected "fields"
	 * @see Collector::Fetch()
	 */
	public function Fetch()
	{
		if ($aData = $this->oStatement->fetch(PDO::FETCH_ASSOC))
		{
		    foreach($this->aSkippedAttributes as $sCode)
		    {
		        unset($aData[$sCode]);
		    }
		    
			if ($this->idx == 0)
			{
				$aChecks = $this->CheckSQLColumn($aData);
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
					throw new Exception("Missing columns in the SQL query.");
				}
			}
			$this->idx++;
			return $aData;
		}
		return false;
	}
	
	/**
	 * Determine if a given attribute is allowed to be missing in the data datamodel.
	 * 
	 * The implementation is based on a predefined configuration parameter named from the
	 * class of the collector (all lowercase) with _ignored_attributes appended.
	 * 
	 * Example: here is the configuration to "ignore" the attribute 'location_id' for the class MySQLCollector:
	 * <mysqlcollector_ignored_attributes type="array">
	 *   	<attribute>location_id</attribute>
	 * </mysqlcollector_ignored_attributes> 
	 * @param string $sAttCode
	 * @return boolean True if the attribute can be skipped, false otherwise
	 */
	public function AttributeIsOptional($sAttCode)
	{
		$aCollectorParams = Utils::GetConfigurationValue(get_class($this), array());
		$aIgnoredAttributes = Utils::GetConfigurationValue(get_class($this)."_ignored_attributes", null);
		if ($aIgnoredAttributes === null)
		{
			// Try all lowercase
			$aIgnoredAttributes = Utils::GetConfigurationValue(strtolower(get_class($this))."_ignored_attributes", null);
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
	protected function CheckSQLColumn($aData)
	{
		$aRet = array('errors' => array(), 'warnings' => array());
		
		if(!array_key_exists('primary_key', $aData))
		{
			$aRet['errors'][] = 'The mandatory column "primary_key" is missing from the query.';
		}
		foreach($this->aFields as $sCode => $aDefs)
		{
			// Check for missing columns
			if (!array_key_exists($sCode, $aData) && $aDefs['reconcile'])
			{
				$aRet['errors'][] = 'The column "'.$sCode.'", used for reconciliation, is missing from the query.';
			}
			else if (!array_key_exists($sCode, $aData) && $aDefs['update'])
			{
				$aRet['errors'][] = 'The column "'.$sCode.'", used for update, is missing from the query.';
			}
			
			// Check for useless columns
			if (array_key_exists($sCode, $aData) && !$aDefs['reconcile']  && !$aDefs['update'])
			{
				$aRet['warnings'][] = 'The column "'.$sCode.'" is used neither for update nor for reconciliation.';
			}
			
		}
		return $aRet;
	}
}

/**
 * Specific extension for MySQL in order to make sure that the collected data are in UTF-8
 *
 * The minimum implementation for such a collector consists in:
 * - creating a class derived from MySQLCollector
 * - configuring a SQL query as <name_of_the_collector_class>_query
 * - configuring the SQL connection parameters:
 * sql_engine: Which PDO DB driver to use (defaults to mysql)
 * sql_host: name/IP address of the database server (defaults to localhost)
 * sql_login: Login to use to connect to the database
 * sql_password: Password to connect to the database
 * sql_database: Name of the database to use for running the query
 */
abstract class MySQLCollector extends SQLCollector
{
	/**
	 * Establish the connection to the database, based on the configuration parameters.
	 * By default all collectors derived from SQLCollector will share the same connection
	 * parameters (same DB server, login, DB name...).
	 * Moreover, forces the connection to use utf8 using the SET NAMES SQL command.
	 * If you don't want this behavior, overload this method in your connector.
	 */
	protected function Connect()
	{
		$bRet = parent::Connect();
		if ($bRet)
		{
			try
			{
				$this->oStatement =  $this->oDB->prepare("SET NAMES 'utf8'");
				if ($this->oStatement === false)
				{
					$aInfo = $this->oDB->errorInfo();
					Utils::Log(LOG_ERR, "[".get_class($this)."] Failed to prepare the query: '$sQuery'. Reason: ".$aInfo[0].', '.$aInfo[2]);
					return false;
				}
				
				$bRet = $this->oStatement->execute();
				if ($this->oStatement->errorCode() !== '00000')
				{
					$aInfo = $this->oStatement->errorInfo();
					Utils::Log(LOG_ERR, "[".get_class($this)."] Failed to execute the query: '$sQuery'. Reason: ".$aInfo[0].', '.$aInfo[2]);
					return false;
				}
			}
			catch (PDOException $e)
			{
				Utils::Log(LOG_ERR, "[".get_class($this)."] SQL query: \"SET NAMES 'utf8'\" failed: ".$e->getMessage());
				$this->oDB = null;
				return false;
			}			
		}
		return $bRet;
	}
}
