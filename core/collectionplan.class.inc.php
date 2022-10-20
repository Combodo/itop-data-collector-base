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
		Utils::Log(LOG_INFO, "---------- Build collection plan ----------");
		self::$oCollectionPlan = $this;
	}

	/**
	 * Initialize collection plan
	 *
	 * @return void
	 * @throws \IOException
	 */
	public function Init()
	{
	}

	/**
	 * @return \CollectionPlan
	 */
	public static function GetPlan()
	{
		return self::$oCollectionPlan;
	}

	/**
	 * Tells if a collector needs to be orchestrated or not
	 *
	 * @param $sCollectorClass
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function IsCollectorToBeLaunched($sCollectorClass): bool
	{
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
		$aCollectorsLaunchSequence = utils::GetConfigurationValue('collectors_launch_sequence', []);
		$aExtensionsCollectorsLaunchSequence = utils::GetConfigurationValue('extensions_collectors_launch_sequence', []);
		$aCollectorsLaunchSequence = array_merge($aCollectorsLaunchSequence, $aExtensionsCollectorsLaunchSequence);
		if (empty($aCollectorsLaunchSequence)) {
			Utils::Log(LOG_INFO, "---------- No Launch sequence has been found, no collector has been orchestrated ----------");

			return false;
		} else {
			// Sort sequence
			foreach ($aCollectorsLaunchSequence as $sCollector) {
				$aRank[] = $sCollector['rank'];
			}
			array_multisort($aRank, SORT_ASC, $aCollectorsLaunchSequence);

			// Orchestrate collectors
			$iIndex = 1;
			foreach ($aCollectorsLaunchSequence as $sCollector) {
				if (file_exists(APPROOT.'collectors/extensions/src/'.$sCollector['name'].'.class.inc.php')) {
					require_once(APPROOT.'collectors/extensions/src/'.$sCollector['name'].'.class.inc.php');
				} elseif (file_exists(APPROOT.'collectors/src/'.$sCollector['name'].'.class.inc.php')) {
					require_once(APPROOT.'collectors/src/'.$sCollector['name'].'.class.inc.php');
				} else {
					require_once(APPROOT.'collectors/'.$sCollector['name'].'.class.inc.php');
				}
				if ($this->IsCollectorToBeLaunched($sCollector['name'])) {
					Orchestrator::AddCollector($iIndex++, $sCollector['name']);
				}
			}
			Utils::Log(LOG_INFO, "---------- Collectors have been orchestrated ----------");

			return true;
		}
	}

}