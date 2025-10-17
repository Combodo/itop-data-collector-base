<?php

$APPROOT = dirname(__DIR__, 2);

echo $APPROOT;
$finder = PhpCsFixer\Finder::create()
	->exclude('vendor')
	->in($APPROOT)
;

$config = new PhpCsFixer\Config();
return $config->setRiskyAllowed(true)
	->setRules([
		'@PSR12'       => true,
		'indentation_type' => true,
		'no_extra_blank_lines' => true,
		'array_syntax' => ['syntax' => 'short'],
		'concat_space' => true,
	])
	->setIndent("\t")
	->setLineEnding("\n")
	->setFinder($finder)
;
