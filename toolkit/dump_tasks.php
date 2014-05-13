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
 * Command line utility to generate the JSON representation of the SynchroDataSource
 * already configured in iTop
 * 
 * Usage: php dump_task.php [--task_name="name of the task"]
 */
define('APPROOT', dirname(dirname(__FILE__)).'/');

require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/restclient.class.inc.php');

$sTaskName = Utils::ReadParameter('task_name', '*');

if ($sTaskName == '*')
{
	// Usage
	echo "Command line utility to generate the JSON representation of an iTop SynchroDataSource.\n\n";
	echo "Usage: php ".basename($argv[0])." --task_name=\"selected task name\"\n\n";
	
	// List all tasks defined in iTop
	$oRestClient = new RestClient();
	$aResult = $oRestClient->Get('SynchroDataSource', 'SELECT SynchroDataSource');
	
	switch(count($aResult['objects']))
	{
		case 0:
		echo "There is no SynchroDataSource defined on the iTop server.\n";
		break;
		
		case 1:
		echo "There is 1 SynchroDataSource defined on the iTop server:\n";
		break;
		
		default:
		echo "There are ".count($aResult['objects'])." SynchroDataSource defined on the iTop server:\n";
	}
	
	echo "+--------------------------------+----------------------------------------------------+\n";
	echo "|            Name                |                    Description                     |\n";
	echo "+--------------------------------+----------------------------------------------------+\n";
	foreach($aResult['objects'] as $aValues)
	{
		$aCurrentTaskDefinition = $aValues['fields'];
		echo sprintf("| %-30.30s | %-50.50s |\n", $aCurrentTaskDefinition['name'], $aCurrentTaskDefinition['description']);
	}
	echo "+--------------------------------+----------------------------------------------------+\n";
}
else
{
	// Generate the pretty-printed JSON representation of the specified task
	$oRestClient = new RestClient();
	$aResult = $oRestClient->Get('SynchroDataSource', array('name' => $sTaskName));
	if ($aResult['code'] != 0)
	{
		echo "Sorry, an error occured while retrieving the information from iTop: {$aResult['message']} ({$aResult['code']})\n";
	}
	else if (is_array($aResult['objects']))
	{
		foreach($aResult['objects'] as $aValues)
		{
			$aCurrentTaskDefinition = $aValues['fields'];
			$sDefinition = json_encode($aCurrentTaskDefinition);
			echo Utils::JSONPrettyPrint($sDefinition)."\n";
		}
	}
	else
	{
		echo "Sorry, no SynchroDataSource named '$sTaskName' found in iTop.\n";
	}
}