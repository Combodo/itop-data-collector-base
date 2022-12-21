<?php

class LegacyCollector extends Collector
{
	public function CheckToLaunch($aOrchestratedCollectors): bool
	{
		return false;
	}
}