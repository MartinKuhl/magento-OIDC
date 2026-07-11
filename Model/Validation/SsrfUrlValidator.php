<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Validation;

/**
 * SSRF URL validator (H-09 / SEC-04).
 *
 * Central place for the module's outbound-URL safety rules: any URL that the
 * module fetches server-side (OIDC discovery documents, endpoints imported
 * from config files, ...) must be a well-formed public HTTPS URL and must not
 * point to loopback or RFC-1918 private network ranges.
 *
 * The host rules are identical to the historic inline checks in
 * Controller/Adminhtml/Provider/Save.php and OAuthsettings/Index.php.
 */
class SsrfUrlValidator
{
    /**
     * Loopback / wildcard hosts that are always rejected.
     *
     * @var string[]
     */
    private const PRIVATE_HOSTS = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

    /**
     * RFC-1918 private IPv4 ranges (10/8, 192.168/16, 172.16/12).
     *
     * @var string
     */
    private const PRIVATE_RANGE_PATTERN = '/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/';

    /**
     * Check that a URL is a well-formed HTTPS URL pointing to a public host.
     *
     * @param  string $url Raw URL to validate
     * @return bool True when the URL is safe to fetch server-side
     */
    public function isAllowedExternalHttpsUrl(string $url): bool
    {
        $validated = filter_var($url, FILTER_VALIDATE_URL);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        if ($validated === false || parse_url($validated, PHP_URL_SCHEME) !== 'https') {
            return false;
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $host = (string) parse_url($validated, PHP_URL_HOST);

        return !$this->isPrivateHost($host);
    }

    /**
     * Check whether a host is a loopback address or inside an RFC-1918 private range.
     *
     * @param  string $host Host name or IP address (without scheme)
     * @return bool True when the host is private/internal and must not be fetched
     */
    public function isPrivateHost(string $host): bool
    {
        return in_array($host, self::PRIVATE_HOSTS, true)
            || (bool) preg_match(self::PRIVATE_RANGE_PATTERN, $host);
    }
}
