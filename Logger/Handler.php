<?php
namespace MiniOrange\OAuth\Logger;

use Monolog\Logger;

/**
 * Custom log handler for MiniOrange OAuth Plugin
 * Writes logs to var/log/mo_oauth.log
 */
class Handler extends \Magento\Framework\Logger\Handler\Base
{
    /**
     * Logging level
     *
     * @var int
     */
    protected $loggerType = Logger::DEBUG;

    /**
     * File name - relative to Magento root
     *
     * @var string
     */
    protected $fileName = '/var/log/mo_oauth.log';
}
