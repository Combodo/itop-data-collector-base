<?php

class FakeCollector extends Collector {
	public function __construct() {
		parent::__construct();
		$this->sSourceName = "fakesource";
		$this->iSourceId = 666;
	}
}
