<?php

namespace UnitTestFiles\Test;

use Collector;
use CollectorHooks;
use Exception;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

@define('APPROOT', dirname(__FILE__, 2).'/');

require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');
require_once(APPROOT.'core/orchestrator.class.inc.php');
require_once(APPROOT.'core/ioexception.class.inc.php');

/**
 * Collector working fully in memory.
 */
class HookTestCollector extends Collector {
	/** @var array[] */
	public array $aSource = [];

	/** @var array[] Rows which reached AddRow(). */
	public array $aAddedRows = [];

	/** @var string[] */
	public array $aCalls = [];

	public function Prepare() {
		$this->aCalls[] = 'Prepare';

		return true;
	}

	public function Fetch() {
		$this->aCalls[] = 'Fetch';

		return array_shift($this->aSource) ?? false;
	}

	protected function Cleanup() {
		$this->aCalls[] = 'Cleanup';
	}

	protected function NextCSVFile() {
		return true;
	}

	protected function AddHeader($aHeaders) {
		$this->aCalls[] = 'AddHeader';
	}

	protected function AddRow($aRow) {
		$this->aAddedRows[] = $aRow;
	}

	protected function ProcessLineBeforeSynchro(&$aLineData, $iLineIndex) {
		$aLineData[] = 'method';
	}
}

/**
 * Derived collector, overriding a method without calling the parent.
 */
class HookTestDerivedCollector extends HookTestCollector {
	public function Fetch() {
		$this->aCalls[] = 'DerivedFetch';

		return array_shift($this->aSource) ?? false;
	}
}

class OtherHookTestCollector extends HookTestCollector {
}

class CollectorHooksTest extends TestCase {
	private string $sHooksFolder;

	private bool $bHooksFolderCreated = false;

	public function setUp(): void {
		parent::setUp();
		CollectorHooks::Reset();
		$this->sHooksFolder = APPROOT.CollectorHooks::HOOKS_FOLDER;
	}

	public function tearDown(): void {
		foreach(glob($this->sHooksFolder.'*') ?: [] as $sFile) {
			unlink($sFile);
		}
		if($this->bHooksFolderCreated) {
			// - Remove only what has been created for the test.
			rmdir($this->sHooksFolder);
			@rmdir(APPROOT.'collectors/extensions');
			@rmdir(APPROOT.'collectors');
		}
		CollectorHooks::Reset();
		parent::tearDown();
	}

	public function testNoHooksCallsMethodDirectly(): void {
		$oCollector = new HookTestCollector();
		$oCollector->aSource = [['a' => 1]];

		$this->assertFalse($oCollector->HasHooks('Fetch'));
		$this->assertTrue($oCollector->Collect());
		$this->assertSame([['a' => 1]], $oCollector->aAddedRows);
		$this->assertSame(['Prepare', 'Fetch', 'AddHeader', 'Fetch', 'Cleanup'], $oCollector->aCalls);
	}

	public function testBeforeAndAfterOrder(): void {
		$aLog = [];
		$fnLog = static function(string $sName) use (&$aLog): callable {
			return static function() use (&$aLog, $sName): void {
				$aLog[] = $sName;
			};
		};

		Collector::AddHook(HookTestCollector::class, 'Prepare', 'after', $fnLog('after-10'), 10);
		Collector::AddHook(HookTestCollector::class, 'Prepare', 'after', $fnLog('after-0'), 0);
		Collector::AddHook(HookTestCollector::class, 'Prepare', 'before', $fnLog('before-5'), 5);
		Collector::AddHook(HookTestCollector::class, 'Prepare', 'before', $fnLog('before-0-first'));
		Collector::AddHook(HookTestCollector::class, 'Prepare', 'before', $fnLog('before-0-second'));

		$oCollector = new HookTestCollector();
		$oCollector->Collect();

		$this->assertSame(['before-0-first', 'before-0-second', 'before-5', 'after-0', 'after-10'], $aLog);
	}

	public function testBeforeHookAltersArguments(): void {
		Collector::AddHook(HookTestCollector::class, 'AddRow', 'before', static function(Collector $oCollector, array &$aArgs): void {
			$aArgs[0]['b'] = 2;
		});

		$oCollector = new HookTestCollector();
		$oCollector->aSource = [['a' => 1]];
		$oCollector->Collect();

		$this->assertSame([['a' => 1, 'b' => 2]], $oCollector->aAddedRows);
	}

	public function testAfterHookAltersResult(): void {
		Collector::AddHook(HookTestCollector::class, 'Fetch', 'after', static function(Collector $oCollector, array $aArgs, mixed &$mResult): void {
			if(is_array($mResult)) {
				$mResult['email'] = strtolower($mResult['email']);
			}
		});

		$oCollector = new HookTestCollector();
		$oCollector->aSource = [['email' => 'A@B.COM'], ['email' => 'C@D.COM']];
		$oCollector->Collect();

		$this->assertSame([['email' => 'a@b.com'], ['email' => 'c@d.com']], $oCollector->aAddedRows);
	}

	public function testBeforeHookSkipsMethod(): void {
		$aLog = [];
		Collector::AddHook(HookTestCollector::class, 'Fetch', 'before', static function() use (&$aLog): mixed {
			$aLog[] = 'first';

			return CollectorHooks::Skip(false);
		}, 0);
		Collector::AddHook(HookTestCollector::class, 'Fetch', 'before', static function() use (&$aLog): void {
			$aLog[] = 'second';
		}, 1);
		Collector::AddHook(HookTestCollector::class, 'Fetch', 'after', static function(Collector $oCollector, array $aArgs, mixed &$mResult) use (&$aLog): void {
			$aLog[] = 'after';
		});

		$oCollector = new HookTestCollector();
		$oCollector->aSource = [['a' => 1]];
		$oCollector->Collect();

		// - The method is not called, the remaining before hooks are skipped, the after hooks still run.
		$this->assertSame(['first', 'after'], $aLog);
		$this->assertSame([], $oCollector->aAddedRows);
		$this->assertNotContains('Fetch', $oCollector->aCalls);
	}

	public function testSkipSuppliesResult(): void {
		Collector::AddHook(HookTestCollector::class, 'Prepare', 'before', static fn(): mixed => CollectorHooks::Skip(false));

		$oCollector = new HookTestCollector();

		$this->assertFalse($oCollector->Collect());
		$this->assertNotContains('Prepare', $oCollector->aCalls);
	}

	public function testHookTargets(): void {
		$iAll = 0;
		$iParent = 0;
		$iDerived = 0;
		Collector::AddHook('*', 'Prepare', 'after', static function() use (&$iAll): void {
			$iAll++;
		});
		Collector::AddHook(HookTestCollector::class, 'Prepare', 'after', static function() use (&$iParent): void {
			$iParent++;
		});
		Collector::AddHook(HookTestDerivedCollector::class, 'Prepare', 'after', static function() use (&$iDerived): void {
			$iDerived++;
		});

		(new HookTestCollector())->Collect();
		$this->assertSame([1, 1, 0], [$iAll, $iParent, $iDerived]);

		(new HookTestDerivedCollector())->Collect();
		$this->assertSame([2, 2, 1], [$iAll, $iParent, $iDerived]);

		(new OtherHookTestCollector())->Collect();
		$this->assertSame([3, 3, 1], [$iAll, $iParent, $iDerived]);
	}

	public function testHooksRunOnOverridesWithoutParentCall(): void {
		Collector::AddHook(HookTestCollector::class, 'Fetch', 'after', static function(Collector $oCollector, array $aArgs, mixed &$mResult): void {
			if(is_array($mResult)) {
				$mResult['hooked'] = true;
			}
		});

		$oCollector = new HookTestDerivedCollector();
		$oCollector->aSource = [['a' => 1]];
		$oCollector->Collect();

		$this->assertSame([['a' => 1, 'hooked' => true]], $oCollector->aAddedRows);
		$this->assertContains('DerivedFetch', $oCollector->aCalls);
		$this->assertNotContains('Fetch', $oCollector->aCalls);
	}

	public function testHookRegisteredAfterFirstUseIsPickedUp(): void {
		$oCollector = new HookTestCollector();
		$this->assertFalse($oCollector->HasHooks('Prepare'));

		Collector::AddHook('*', 'Prepare', 'after', static function(): void {
		});
		$this->assertTrue($oCollector->HasHooks('Prepare'));
	}

	public function testByReferenceArgumentsAreWrittenBack(): void {
		Collector::AddHook(HookTestCollector::class, 'ProcessLineBeforeSynchro', 'before', static function(Collector $oCollector, array &$aArgs): void {
			$aArgs[0][] = 'before';
		});
		Collector::AddHook(HookTestCollector::class, 'ProcessLineBeforeSynchro', 'after', static function(Collector $oCollector, array &$aArgs): void {
			$aArgs[0][] = 'after';
		});

		$oCollector = new HookTestCollector();
		$aArgs = [['x'], 0];
		$oCollector->Dispatch('ProcessLineBeforeSynchro', $aArgs);

		$this->assertSame(['x', 'before', 'method', 'after'], $aArgs[0]);
	}

	public function testExceptionInHookPropagatesLikeMethodException(): void {
		Collector::AddHook(HookTestCollector::class, 'Prepare', 'before', static function(): void {
			throw new Exception('Hook failure');
		});

		// - Collect() catches exceptions and reports a failure.
		$this->assertFalse((new HookTestCollector())->Collect());
	}

	public function testInvalidRegistrations(): void {
		try {
			Collector::AddHook('*', 'GetName', 'before', static function(): void {
			});
			$this->fail('A non hookable method must be rejected.');
		}
		catch(InvalidArgumentException $e) {
			$this->assertStringContainsString('GetName', $e->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		Collector::AddHook('*', 'Fetch', 'during', static function(): void {
		});
	}

	public function testHookFilesAreLoadedFromFolder(): void {
		$this->CreateHooksFolder();
		file_put_contents($this->sHooksFolder.'20-second.php', <<<'PHP'
<?php

return [
	[
		'target' => 'UnitTestFiles\Test\HookTestCollector',
		'method' => 'Fetch',
		'when' => 'after',
		'priority' => 20,
		'callback' => static function(Collector $oCollector, array $aArgs, mixed &$mResult): void {
			if(is_array($mResult)) {
				$mResult['order'][] = 'second';
			}
		},
	],
];
PHP
		);
		file_put_contents($this->sHooksFolder.'10-first.php', <<<'PHP'
<?php

return [
	[
		'target' => 'UnitTestFiles\Test\HookTestCollector',
		'method' => 'Fetch',
		'when' => 'after',
		'callback' => static function(Collector $oCollector, array $aArgs, mixed &$mResult): void {
			if(is_array($mResult)) {
				$mResult['order'][] = 'first';
			}
		},
	],
];
PHP
		);
		// - A file which does not return an array registers nothing, but is not an error.
		file_put_contents($this->sHooksFolder.'helper.php', "<?php\n\nclass HookTestHelper {\n}\n");

		CollectorHooks::LoadHookFiles();

		$oCollector = new HookTestCollector();
		$oCollector->aSource = [[]];
		$oCollector->Collect();

		$this->assertSame([['order' => ['first', 'second']]], $oCollector->aAddedRows);
	}

	public function testHookFilesAreLoadedOnce(): void {
		$this->CreateHooksFolder();
		file_put_contents($this->sHooksFolder.'once.php', <<<'PHP'
<?php

return [
	['target' => '*', 'method' => 'Prepare', 'when' => 'after', 'callback' => static function(): void {
	}],
];
PHP
		);

		CollectorHooks::LoadHookFiles();
		CollectorHooks::LoadHookFiles();

		$this->assertCount(1, (new HookTestCollector())->GetHooks('Prepare')['after']);
	}

	public function testInvalidHookFileIsReported(): void {
		$this->CreateHooksFolder();
		file_put_contents($this->sHooksFolder.'broken.php', "<?php\n\nreturn [['target' => '*', 'method' => 'Nope', 'when' => 'before', 'callback' => 'strlen']];\n");

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/broken\.php/');
		CollectorHooks::LoadHookFiles();
	}

	public function testMissingHooksFolderIsIgnored(): void {
		CollectorHooks::LoadHookFiles();

		$this->assertFalse((new HookTestCollector())->HasHooks('Prepare'));
	}

	private function CreateHooksFolder(): void {
		if(!is_dir($this->sHooksFolder)) {
			mkdir($this->sHooksFolder, 0777, true);
			$this->bHooksFolderCreated = true;
		}
	}
}
