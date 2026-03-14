<?php
namespace M2Oidc\OAuth\Logger;

/**
 * Custom Logger for M2Oidc OAuth module.
 *
 * Magento's logging architecture requires extending Monolog\Logger.
 * Monolog v3 marks Logger as @final, but Magento's DI system depends
 * on this extension pattern. This final is a known Magento ecosystem pattern.
 */
class Logger extends \Magento\Framework\Logger\Monolog
{
}
