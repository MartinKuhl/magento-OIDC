<?php
namespace MiniOrange\OAuth\Logger;

/**
 * Custom Logger for MiniOrange OAuth module.
 *
 * Magento's logging architecture requires extending Monolog\Logger.
 * Monolog v3 marks Logger as @final, but Magento's DI system depends
 * on this extension pattern. This is a known Magento ecosystem pattern.
 *
 * @phpstan-ignore class.extendsFinalByPhpDoc
 */
class Logger extends \Magento\Framework\Logger\Monolog
{
}
