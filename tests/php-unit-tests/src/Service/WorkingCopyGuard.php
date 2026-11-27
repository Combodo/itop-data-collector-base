<?php

namespace UnitTestFiles\Test\Service;

use RuntimeException;

/**
 * Keeps the developer's files safe while the tests run.
 *
 * The tests need a clean runtime layout: they copy fixtures into `collectors/`, wipe it, and must not read the
 * developer's `conf/params.local.xml`. Before the first test, this guard moves `conf/params.local.xml` and the
 * content of `collectors/` (or the `collectors` symlink itself) to `tests/php-unit-tests/.stash/`, and puts them back when the PHP
 * process ends, even after a fatal error or Ctrl-C (Ctrl-C needs the pcntl extension).
 *
 * During the run, a `conf/params.local.xml` can only have been written by a test (the developer's one is stashed, or
 * there is none): it is deleted at the end, so that exec.php never reads a configuration left by the tests.
 *
 * If a run is killed before it can restore them, the next run restores them first. It never deletes a file it did
 * not create: when in doubt, it stops and explains what to check.
 */
final class WorkingCopyGuard
{
	/** @var string[] Paths, relative to APPROOT, that the tests must neither read nor alter */
	private const PROTECTED_PATHS = [self::LOCAL_CONFIG, 'collectors'];
	private const LOCAL_CONFIG = 'conf/params.local.xml';
	private const STASH_DIR = 'tests/php-unit-tests/.stash';
	private const OWNER_FILE = 'owner.pid';

	/** @var bool */
	private static $bProtecting = false;

	/**
	 * Stash the protected paths and leave an empty `collectors/` directory. Does nothing if already protecting.
	 *
	 * @throws \RuntimeException When the stash cannot be done without risking the developer's files
	 */
	public static function Protect(): void
	{
		if (self::$bProtecting) {
			return;
		}

		self::RecoverFromInterruptedRun();

		$sStashDir = self::GetStashDir();
		if (!is_dir($sStashDir) && !mkdir($sStashDir, 0700, true)) {
			throw new RuntimeException("Cannot create the test stash directory '$sStashDir'.");
		}
		file_put_contents($sStashDir.'/'.self::OWNER_FILE, (string)getmypid());

		foreach (self::PROTECTED_PATHS as $sPath) {
			$sTarget = APPROOT.$sPath;
			if (!is_link($sTarget) && !file_exists($sTarget)) {
				continue;
			}
			if (!is_link($sTarget) && is_dir($sTarget) && self::IsEmptyDir($sTarget)) {
				continue;
			}
			if (!rename($sTarget, $sStashDir.'/'.basename($sPath))) {
				throw new RuntimeException("Cannot move '$sTarget' to '$sStashDir' before running the tests.");
			}
		}
		self::EmptyCollectorsDir();

		self::$bProtecting = true;
		register_shutdown_function([self::class, 'Release']);
		if (function_exists('pcntl_async_signals')) {
			pcntl_async_signals(true);
			foreach ([SIGINT, SIGTERM] as $iSignal) {
				// exit() runs the shutdown functions, hence Release()
				pcntl_signal($iSignal, function () {
					exit(1);
				});
			}
		}
	}

	/**
	 * Wipe what the tests left in `collectors/` and put the developer's files back. Registered as a shutdown function.
	 */
	public static function Release(): void
	{
		if (!self::$bProtecting) {
			return;
		}
		self::$bProtecting = false;

		try {
			self::EmptyCollectorsDir();
			if (file_exists(APPROOT.self::LOCAL_CONFIG)) {
				unlink(APPROOT.self::LOCAL_CONFIG); // Written by a test
			}
			self::RestoreStash();
		} catch (RuntimeException $e) {
			fwrite(STDERR, PHP_EOL.'WARNING: '.$e->getMessage().PHP_EOL);
		}
	}

	/**
	 * @return string Absolute path of the stash directory
	 */
	public static function GetStashDir(): string
	{
		return APPROOT.self::STASH_DIR;
	}

	/**
	 * A stash left by a dead process means a run was killed: give the files back before anything else.
	 *
	 * @throws \RuntimeException
	 */
	private static function RecoverFromInterruptedRun(): void
	{
		$sStashDir = self::GetStashDir();
		if (!is_dir($sStashDir)) {
			return;
		}

		$sOwnerFile = $sStashDir.'/'.self::OWNER_FILE;
		$iOwnerPid = file_exists($sOwnerFile) ? (int)file_get_contents($sOwnerFile) : 0;
		if ($iOwnerPid > 0 && $iOwnerPid !== getmypid() && self::IsProcessRunning($iOwnerPid)) {
			throw new RuntimeException("Another test run (PID $iOwnerPid) is using this working copy. Wait for it to end, then retry.");
		}

		if (file_exists(APPROOT.self::LOCAL_CONFIG)) {
			throw new RuntimeException(
				"A previous test run was interrupted, and '".APPROOT.self::LOCAL_CONFIG."' exists: it was most probably written by "
				."a test (your own one, if any, is in '$sStashDir'). Check it, delete it, then run the tests again."
			);
		}
		$sCollectorsDir = APPROOT.'collectors';
		if (file_exists($sStashDir.'/collectors') && is_dir($sCollectorsDir) && !is_link($sCollectorsDir) && !self::IsEmptyDir($sCollectorsDir)) {
			throw new RuntimeException(
				"A previous test run was interrupted. Your original files are in '$sStashDir', and '$sCollectorsDir' holds "
				."what that run left. Check it, empty it, then run the tests again: your files will be restored first."
			);
		}

		self::RestoreStash();
		fwrite(STDERR, "Restored the files stashed by an interrupted test run.".PHP_EOL);
	}

	/**
	 * @throws \RuntimeException When a file cannot be put back without overwriting another one
	 */
	private static function RestoreStash(): void
	{
		$sStashDir = self::GetStashDir();
		if (!is_dir($sStashDir)) {
			return;
		}

		foreach (self::PROTECTED_PATHS as $sPath) {
			$sStashed = $sStashDir.'/'.basename($sPath);
			if (!is_link($sStashed) && !file_exists($sStashed)) {
				continue;
			}
			$sTarget = APPROOT.$sPath;
			if (!is_link($sTarget) && is_dir($sTarget) && self::IsEmptyDir($sTarget)) {
				rmdir($sTarget);
			}
			if (is_link($sTarget) || file_exists($sTarget)) {
				throw new RuntimeException("Cannot restore '$sTarget': it already exists. Your original is still in '$sStashed', move it back yourself.");
			}
			if (!rename($sStashed, $sTarget)) {
				throw new RuntimeException("Cannot move '$sStashed' back to '$sTarget'. Move it back yourself.");
			}
		}

		@unlink($sStashDir.'/'.self::OWNER_FILE);
		@rmdir($sStashDir);
	}

	/**
	 * Leave `collectors/` as an empty real directory. Only called while the developer's content is stashed.
	 *
	 * @throws \RuntimeException
	 */
	private static function EmptyCollectorsDir(): void
	{
		$sCollectorsDir = APPROOT.'collectors';
		if (is_link($sCollectorsDir)) {
			throw new RuntimeException("'$sCollectorsDir' is a symbolic link created during the tests: it was not removed.");
		}
		if (is_dir($sCollectorsDir)) {
			self::DeleteDirContent($sCollectorsDir);
		} elseif (!mkdir($sCollectorsDir, 0755)) {
			throw new RuntimeException("Cannot create '$sCollectorsDir'.");
		}
	}

	private static function DeleteDirContent(string $sDir): void
	{
		foreach (scandir($sDir) as $sEntry) {
			if ($sEntry === '.' || $sEntry === '..') {
				continue;
			}
			$sPath = $sDir.'/'.$sEntry;
			if (is_dir($sPath) && !is_link($sPath)) {
				self::DeleteDirContent($sPath);
				rmdir($sPath);
			} else {
				unlink($sPath);
			}
		}
	}

	private static function IsEmptyDir(string $sDir): bool
	{
		return count(scandir($sDir)) === 2;
	}

	private static function IsProcessRunning(int $iPid): bool
	{
		if (function_exists('posix_kill')) {
			return posix_kill($iPid, 0);
		}

		return file_exists("/proc/$iPid");
	}
}
