# iTop Collector base

## About

iTop data collectors are simple PHP command line applications to collect data from an external application and load/synchronize the
 information into [iTop](https://github.com/Combodo/iTop) using the data synchronization mechanism built into iTop.

This module is a small framework for creating your own data collectors for iTop.

For more information about this module have a look at the [documentation](https://www.itophub.io/wiki/page?id=extensions%3Aitop-data-collector-base).

## Download

Release packages can be found on the [iTop Hub Store](https://store.itophub.io/en_US/taxons/all-extensions). This is the best way to get
 a running package as those contains all the needed modules and stable code. 

When downloading directly from GitHub (by cloning or downloading as zip) you will get potentially unstable code, and you will miss
 additional modules.  

## Hooks

Extensions can run code before and/or after the methods of any collector, without modifying or subclassing it.

Every `*.php` file in `collectors/extensions/hooks/` is included (in alphabetical order) when the collection plan is built.
A file is active when it is present and inactive when it is not. It returns an array of hook definitions:

```php
<?php

return [
	[
		'target' => 'iTopPersonCollector', // Collector class, parent class or '*' (all collectors).
		'method' => 'Fetch',               // One of Collector::HOOKABLE_METHODS.
		'when' => 'after',                 // 'before' or 'after'.
		'priority' => 10,                  // Optional, lower runs first (default 0).
		'callback' => static function(Collector $oCollector, array $aArgs, mixed &$mResult): void {
			if(is_array($mResult)) {
				$mResult['email'] = strtolower($mResult['email']);
			}
		},
	],
];
```

- A `before` callback has the signature `fn(Collector $oCollector, array &$aArgs): mixed`. The arguments of the method can be altered through `$aArgs`.
  Returning `CollectorHooks::Skip($mResult)` skips the remaining `before` hooks and the method itself; `$mResult` is used as the result of the method.
- An `after` callback has the signature `fn(Collector $oCollector, array $aArgs, mixed &$mResult): void`. The result of the method can be altered through `$mResult`.
- Hooks also run when a derived collector overrides the method without calling `parent::`.
- A file which does not return an array is included, but registers no hooks (e.g. a file with a helper class).
- Hooks can also be registered from code with `Collector::AddHook($sTarget, $sMethod, $sWhen, $cCallback, $iPriority)`.

Hookable methods: `Init`, `Collect`, `Prepare`, `Fetch`, `Cleanup`, `AddHeader`, `AddRow`, `Synchronize`, `InitSynchroDataSource`,
`DoProcessBeforeSynchro`, `InitProcessBeforeSynchro` and `ProcessLineBeforeSynchro`.

## Phpstan validation

run below command to validate your collector-base clone
```composer install; vendor/bin/phpstan analyse```

see phpstan configuration in phpstan.neon.dist. in here you can increase/decrease (severity) level

to ignore remaining errors please generate baseline again:
```composer install; vendor/bin/phpstan analyse --generate-baseline```

## About Us

This iTop module development is sponsored, led and supported by [Combodo](https://www.combodo.com).
