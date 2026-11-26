<?php

class LegacyCollector extends Collector
{
	/**
	 * @inheritDoc
	 */
	public function CheckToLaunch(array $aOrchestratedCollectors): bool
	{
		return false;
	}
}
