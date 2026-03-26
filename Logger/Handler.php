<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Logger;

use Monolog\Logger;

/**
 * Custom log handler for M2Oidc OAuth Plugin
 * Writes logs to var/log/M2Oidc.log
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
    protected $fileName = '/var/log/M2Oidc.log';
}
