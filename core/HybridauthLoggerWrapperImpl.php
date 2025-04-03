<?php

use Hybridauth\Logger\Logger;
use Hybridauth\Logger\LoggerInterface;

class HybridauthLoggerWrapperImpl implements LoggerInterface
{
	/**
	 * @inheritdoc
	 */
	public function info($message, array $context = [])
	{
		Utils::Log(LOG_INFO, $message);
	}

	/**
	 * @inheritdoc
	 */
	public function debug($message, array $context = [])
	{
		Utils::Log(LOG_DEBUG, $message);
	}

	/**
	 * @inheritdoc
	 */
	public function error($message, array $context = [])
	{
		Utils::Log(LOG_ERR, $message);
	}

	/**
	 * @inheritdoc
	 */
	public function log($level, $message, array $context = [])
	{
		 switch ($level) {
			 case Logger::DEBUG:
				 $collectorLogLevel = LOG_DEBUG;
				 break;

			 case Logger::INFO:
				 $collectorLogLevel = LOG_INFO;
				 break;

			 case Logger::ERROR:
				 $collectorLogLevel = LOG_ERR;
				 break;

			 default:
				 return;

		 }

		$sContext = implode('|', $context);
		Utils::Log($collectorLogLevel, "$message [$sContext]");
	}
}
