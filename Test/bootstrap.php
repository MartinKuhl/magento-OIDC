<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for MiniOrange OIDC standalone unit tests.
 *
 * These tests run without the full Magento framework — all Magento
 * class dependencies are provided as PHPUnit mock objects.  Only
 * classes under MiniOrange\OAuth are loaded via Composer's class map.
 */

// Resolve the module root (one dir up from Test/)
$moduleRoot = dirname(__DIR__);

// Register an auto-loader that maps MiniOrange\OAuth\* to files in this module.
spl_autoload_register(static function (string $class) use ($moduleRoot): void {
    if (!str_starts_with($class, 'MiniOrange\\OAuth\\')) {
        return;
    }

    // Convert namespace to relative path:  MiniOrange\OAuth\Foo\Bar -> Foo/Bar.php
    $relative = str_replace('\\', '/', substr($class, strlen('MiniOrange\\OAuth\\')));
    $file     = $moduleRoot . '/' . $relative . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
