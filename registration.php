<?php

declare(strict_types=1);

/**
  * The code below is used to register the
  * OAuth extension/component with the Mangeto
  * core Module. It specifies the root directory
  * of the plugin.
  */
if (!class_exists(\Magento\Framework\Component\ComponentRegistrar::class)) {
    return;
}
\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'M2Oidc_OAuth',
    __DIR__
);
