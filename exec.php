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
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/restclient.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');
require_once(APPROOT.'core/orchestrator.class.inc.php');

$bResult = true;
$bConfigureOnly = (Utils::ReadBooleanParameter('configure_only', false) == true);
$bCollectOnly = (Utils::ReadBooleanParameter('collect_only', false) == true);
$bSynchroOnly = (Utils::ReadBooleanParameter('synchro_only', false) == true);

Utils::$iConsoleLogLevel = Utils::ReadParameter('console_log_level', Utils::GetConfigurationValue('console_log_level', LOG_INFO));
$iMaxChunkSize = Utils::ReadParameter('max_chunk_size', Utils::GetConfigurationValue('max_chunk_size', 1000));

try
{
	if (file_exists(APPROOT.'collector/main.php'))
	{
		require_once(APPROOT.'collector/main.php');
	}
	else
	{
		Utils::Log(LOG_ERR, "The file '".APPROOT."collector/main.php' is missing (or unreadable).");
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
	Utils::Log(LOG_ERR, "Exception: ".$e->getMessage());
}

exit ($bResult ? 0 : 1); // exit code is zero means success