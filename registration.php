<?php
 
 /**
  * The code below is used to register the
  * OAuth extension/component with the Mangeto
  * core Module. It specifies the root directory
  * of the plugin.
  */
\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'M2Oidc_OAuth',
    __DIR__
);
