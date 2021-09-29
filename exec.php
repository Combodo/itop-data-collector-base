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
 * Main entry point for the collector application
 */
define('APPROOT', dirname(__FILE__).'/');

require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/ioexception.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/restclient.class.inc.php');
require_once(APPROOT.'core/lookuptable.class.inc.php');
require_once(APPROOT.'core/mappingtable.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');
require_once(APPROOT.'core/orchestrator.class.inc.php');
require_once(APPROOT.'core/sqlcollector.class.inc.php'); // Depends on Orchestrator for settings a minimum version for PHP because of the use of PDO
require_once(APPROOT.'core/csvcollector.class.inc.php');
require_once(APPROOT.'core/jsoncollector.class.inc.php');

$aOptionalParams = array(
    'configure_only' => 'boolean',
    'collect_only' => 'boolean',
    'synchro_only' => 'boolean',
    'dump_config_only' => 'boolean',
    'console_log_level' => 'integer',
    'eventissue_log_level' => 'integer',
    'max_chunk_size' => 'integer',
    'help' => 'boolean',
    'config_file' => 'string'
);
$bHelp = (Utils::ReadBooleanParameter('help', false) == true);
$aUnknownParameters = Utils::CheckParameters($aOptionalParams);
if ($bHelp || count($aUnknownParameters) > 0)
{
	if (!$bHelp)
    {
        Utils::Log(LOG_ERR, "Unknown parameter(s): ".implode(' ', $aUnknownParameters));
    }

    echo "Usage:\n";
	echo 'php '.basename($argv[0]);
	foreach($aOptionalParams as $sParam => $sType)
	{
		switch($sType)
		{
			case 'boolean':
			echo '[--'.$sParam.']';
			break;

			default:
			echo '[--'.$sParam.'=xxx]';
			break;
		}
	}
	echo "\n";
	exit(1);
}
	
$bResult = true;
// Note: The parameter 'config_file' is read directly by Utils::LoadConfig()
$bConfigureOnly = (Utils::ReadBooleanParameter('configure_only', false) == true);
$bCollectOnly = (Utils::ReadBooleanParameter('collect_only', false) == true);
$bSynchroOnly = (Utils::ReadBooleanParameter('synchro_only', false) == true);
$bDumpConfigOnly = (Utils::ReadBooleanParameter('dump_config_only', false) == true);

try
{
    Utils::$iConsoleLogLevel = Utils::ReadParameter('console_log_level', Utils::GetConfigurationValue('console_log_level', LOG_WARNING));//On windows LOG_NOTICE=LOG_INFO=LOG_DEBUG=6
    Utils::$iEventIssueLogLevel = Utils::ReadParameter('eventissue_log_level', Utils::GetConfigurationValue('eventissue_log_level', LOG_NONE));//On windows LOG_NOTICE=LOG_INFO=LOG_DEBUG=6
    $iMaxChunkSize = Utils::ReadParameter('max_chunk_size', Utils::GetConfigurationValue('max_chunk_size', 1000));
    
    if (file_exists(APPROOT.'collectors/main.php'))
	{
		require_once(APPROOT.'collectors/main.php');
	}
	else
	{
		Utils::Log(LOG_ERR, "The file '".APPROOT."collectors/main.php' is missing (or unreadable).");
	}

	if (!Orchestrator::CheckRequirements())
	{
		exit(1);
	}
	
	$aConfig = Utils::GetConfigFiles();
	$sConfigDebug = "The following configuration files were loaded (in this order):\n\n";
	$idx = 1;
	foreach($aConfig as $sFile)
	{
		$sConfigDebug .= "\t{$idx}. $sFile\n";
		$idx++;
	}
	$sConfigDebug .= "\nThe resulting configuration is:\n\n";
		
	$sConfigDebug .= Utils::DumpConfig();
	
	if ($bDumpConfigOnly)
	{
		echo $sConfigDebug;
		exit(0);
	}
	else
	{
		Utils::Log(LOG_DEBUG, $sConfigDebug);
	}

	$oOrchestrator = new Orchestrator();
	$aCollectors = $oOrchestrator->ListCollectors();
	Utils::Log(LOG_DEBUG, "Registered collectors:");
	foreach($aCollectors as $oCollector)
	{
		Utils::Log(LOG_DEBUG, "Collector: ".$oCollector->GetName().", version: ".$oCollector->GetVersion());
	}

	if(!$bCollectOnly)
	{
		Utils::Log(LOG_DEBUG, 'iTop web services version: '.RestClient::GetNewestKnownVersion());
		$bResult = $oOrchestrator->InitSynchroDataSources($aCollectors);
	}
	if ($bResult && !$bSynchroOnly && !$bConfigureOnly)
	{
		$bResult = $oOrchestrator->Collect($aCollectors, $iMaxChunkSize, $bCollectOnly);
	}
	
	if ($bResult && !$bConfigureOnly && !$bCollectOnly)
	{
		$bResult = $oOrchestrator->Synchronize($aCollectors);
	}
}
catch(Exception $e)
{
    $bResult = false;
	Utils::Log(LOG_ERR, "Exception: ".$e->getMessage());
}

exit ($bResult ? 0 : 1); // exit code is zero means success
