<?php
// Copyright (C) 2022 Combodo SARL
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
 * Base class for all collection plans
 *
 */
abstract class CollectionPlan
{
	// Instance of the collection plan
	static protected $oCollectionPlan;

	public function __construct()
	{
		self::$oCollectionPlan = $this;
	}

	/**
	 * Initialize collection plan
	 *
	 * @return void
	 * @throws \IOException
	 */
	public function Init(): void
	{
		Utils::Log(LOG_INFO, "---------- Build collection plan ----------");
	}

	/**
	 * @return \CollectionPlan
	 */
	public static function GetPlan(): CollectionPlan
	{
		return self::$oCollectionPlan;
	}

	/**
	 * Provide the launch sequence as defined in the configuration files
	 *
	 * @return array|false
	 * @throws \Exception
	 */
	public function GetSortedLaunchSequence(): array
	{
		$aCollectorsLaunchSequence = utils::GetConfigurationValue('collectors_launch_sequence', []);
		$aExtensionsCollectorsLaunchSequence = utils::GetConfigurationValue('extensions_collectors_launch_sequence', []);
		$aCollectorsLaunchSequence = array_merge($aCollectorsLaunchSequence, $aExtensionsCollectorsLaunchSequence);
		if (!empty($aCollectorsLaunchSequence)) {
			// Sort sequence
			$aSortedCollectorsLaunchSequence = [];
			foreach ($aCollectorsLaunchSequence as $aCollector) {
				if (array_key_exists('rank', $aCollector)) {
					$aRank[] = $aCollector['rank'];
					$aSortedCollectorsLaunchSequence[] = $aCollector;
				} else {
					Utils::Log(LOG_INFO, "> Rank is missing from the launch_sequence of ".$aCollector['name']." It will not be launched.");
				}
			}
			array_multisort($aRank, SORT_ASC, $aSortedCollectorsLaunchSequence);

			return $aSortedCollectorsLaunchSequence;
		}

		return $aCollectorsLaunchSequence;
	}

	/**
	 * Look for the collector definition file in the different possible collector directories
	 *
	 * @param $sCollector
	 *
	 * @return bool
	 */
	public function GetCollectorDefinitionFile($sCollector): bool
	{
		if (file_exists(APPROOT.'collectors/extensions/src/'.$sCollector.'.class.inc.php')) {
			require_once(APPROOT.'collectors/extensions/src/'.$sCollector.'.class.inc.php');
		} elseif (file_exists(APPROOT.'collectors/src/'.$sCollector.'.class.inc.php')) {
			require_once(APPROOT.'collectors/src/'.$sCollector.'.class.inc.php');
		} elseif (file_exists(APPROOT.'collectors/'.$sCollector.'.class.inc.php')) {
			require_once(APPROOT.'collectors/'.$sCollector.'.class.inc.php');
		} else {
			return false;
		}

		return true;
	}

	/**
	 *  Add the collectors to be launched to the orchestrator
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function AddCollectorsToOrchestrator(): bool
	{
		// Read and order launch sequence
		$aCollectorsLaunchSequence = $this->GetSortedLaunchSequence();
		if (empty($aCollectorsLaunchSequence)) {
			Utils::Log(LOG_INFO, "---------- No Launch sequence has been found, no collector has been orchestrated ----------");

			return false;
		}

		$iIndex = 1;
		$aOrchestratedCollectors = [];
		foreach ($aCollectorsLaunchSequence as $iKey => $aCollector) {
			$sCollectorName = $aCollector['name'];

			// Skip disabled collectors
			if (!array_key_exists('enable', $aCollector) || ($aCollector['enable'] != 'yes')) {
				Utils::Log(LOG_INFO, "> ".$sCollectorName." is disabled and will not be launched.");
				continue;
			}

			// Read collector php definition file
			if (!$this->GetCollectorDefinitionFile($sCollectorName)) {
				Utils::Log(LOG_INFO, "> No file definition file has been found for ".$sCollectorName." It will not be launched.");
				continue;
			}

			// Instantiate collector
			$oCollector = new $sCollectorName;
			$oCollector->Init();
			if ($oCollector->CheckToLaunch($aOrchestratedCollectors)) {
				Utils::Log(LOG_INFO, $sCollectorName.' will be launched !');
				Orchestrator::AddCollector($iIndex++, $sCollectorName);
				$aOrchestratedCollectors[$sCollectorName] = true;
			} else {
				$aOrchestratedCollectors[$sCollectorName] = false;
			}
			unset($oCollector);
		}
		Utils::Log(LOG_INFO, "---------- Collectors have been orchestrated ----------");

		return true;
	}

}