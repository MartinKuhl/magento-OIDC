<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Helper;

use Magento\Framework\HTTP\Adapter\CurlFactory;
use M2Oidc\OAuth\Helper\OAuthConstants;

/**
 * HTTP client helper for OAuth/OIDC API requests.
 *
 * Instance methods are preferred. Static methods are kept for backward
 * compatibility but are deprecated.
 */
class Curl
{
    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var CurlFactory */
    private readonly CurlFactory $curlFactory;

    /**
     * Initialize cURL helper.
     *
     * @param OAuthUtility $oauthUtility
     * @param CurlFactory  $curlFactory
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        CurlFactory $curlFactory
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->curlFactory = $curlFactory;
    }

    /**
     * Send an access token request to the OAuth provider.
     *
     * @param  mixed  $postData     Token request body parameters
     * @param  string $url          Token endpoint URL
     * @param  string $clientID     OAuth client ID
     * @param  string $clientSecret OAuth client secret
     * @param  int    $header       Whether to send credentials in header (1) or not (0)
     * @param  int    $body         Whether to send credentials in body (1) or not (0)
     * @return string JSON response
     */
    public function sendAccessTokenRequest(
        $postData,
        string $url,
        string $clientID,
        string $clientSecret,
        int $header,
        int $body
    ): string {
        if ($header === 0 && $body === 1) {
            // Credentials sent in POST body (handled by caller via $postData).
            $authHeader = [
                "Content-Type: application/x-www-form-urlencoded",
                'Accept: application/json',
            ];
        } elseif ($clientSecret === '') {
            // Public client (RFC 6749 §2.1): no client secret — no Authorization header
            // is sent. Callers MUST include client_id in $postData themselves
            // (RFC 6749 §3.2.1); both AccessTokenRequest and AccessTokenRequestBody
            // support this for public clients.
            $authHeader = [
                "Content-Type: application/x-www-form-urlencoded",
                'Accept: application/json',
            ];
        } else {
            // Confidential client: authenticate via HTTP Basic (RFC 6749 §2.3.1).
            $authHeader = [
                "Content-Type: application/x-www-form-urlencoded",
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($clientID . ":" . $clientSecret)
            ];
        }
        return $this->callAPI($url, $postData, $authHeader);
    }

    /**
     * Send a user info request to the OAuth provider.
     *
     * @param  string $url     User info endpoint URL
     * @param  mixed  $headers HTTP headers (including Authorization)
     * @return string JSON response
     */
    public function sendUserInfoRequest(string $url, $headers): string
    {
        return $this->callAPI($url, [], $headers);
    }

    /**
     * Send a JSON POST notification to an outbound webhook (Slack/PagerDuty/etc.).
     *
     * Deliberately does not delegate to callAPI(): that method decides GET vs POST based
     * on whether the JSON payload is empty, which must never leak into webhook delivery
     * (a webhook call must always be a real POST). Uses a short, fixed timeout independent
     * of the per-provider http_timeout so a slow/unreachable alerting endpoint can never
     * stall the caller. Never throws — a webhook delivery failure must not crash a cron job.
     *
     * @param  string  $url     Destination webhook URL (caller must have already validated it)
     * @param  mixed[] $payload JSON-serializable payload body
     * @return array{success: bool, httpCode: int}
     */
    public function sendWebhookNotification(string $url, array $payload): array
    {
        try {
            $curl = $this->curlFactory->create();
            $curl->setConfig(['header' => false]);
            $curl->setConfig([
                'CURLOPT_FOLLOWLOCATION' => true,
                'CURLOPT_ENCODING' => "",
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_AUTOREFERER' => true,
                'CURLOPT_TIMEOUT' => OAuthConstants::WEBHOOK_TIMEOUT_DEFAULT,
                'CURLOPT_MAXREDIRS' => 10,
                'CURLOPT_SSL_VERIFYPEER' => true,
                'CURLOPT_SSL_VERIFYHOST' => 2,
            ]);

            $headers = ['Content-Type: application/json', 'Accept: application/json'];
            $body    = (string) json_encode($payload);

            $curl->write('POST', $url, '1.1', $headers, $body);
            $curl->read();
            $httpCode = (int) $curl->getInfo(CURLINFO_HTTP_CODE);
            $curl->close();

            if ($httpCode < 200 || $httpCode >= 300) {
                $this->oauthUtility->customlog(
                    "Curl: Webhook notification to {$url} returned non-success HTTP {$httpCode}"
                );
            }

            return ['success' => $httpCode >= 200 && $httpCode < 300, 'httpCode' => $httpCode];
        } catch (\Throwable $e) {
            $this->oauthUtility->customlog(
                "Curl: Webhook notification to {$url} failed: " . $e->getMessage()
            );
            return ['success' => false, 'httpCode' => 0];
        }
    }

    /**
     * Internal HTTP request method.
     *
     * @param  string $url      Request URL
     * @param  mixed  $jsonData Request body data
     * @param  mixed  $headers  HTTP headers
     * @return string Response body
     */
    private function callAPI(string $url, $jsonData = [], $headers = ["Content-Type: application/json"]): string
    {
        $curl = $this->curlFactory->create();
        $curl->setConfig(['header' => false]);
        $options = [
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_ENCODING' => "",
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_AUTOREFERER' => true,
            'CURLOPT_TIMEOUT' => (int) ($this->oauthUtility->getStoreConfig(OAuthConstants::HTTP_TIMEOUT)
                                  ?: OAuthConstants::HTTP_TIMEOUT_DEFAULT),
            'CURLOPT_MAXREDIRS' => 10,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ];

        $data = in_array("Content-Type: application/x-www-form-urlencoded", $headers)
            ? (empty($jsonData) ? "" : http_build_query($jsonData))
            : (empty($jsonData) ? "" : (string) json_encode($jsonData));

        $method = $data === '' ? 'GET' : 'POST';
        $curl->setConfig($options);

        // Single retry on empty response (transient connection / timeout error).
        // Does not retry HTTP 4xx/5xx — those are real IdP errors.
        $content  = '';
        $attempts = 0;
        do {
            if ($attempts > 0) {
                usleep(500_000); // 500 ms backoff before retry
            }
            $curl->write($method, $url, '1.1', $headers, $data);
            $content = $curl->read();
            $attempts++;
        } while ($content === '' && $attempts < 2);

        $httpCode = $curl->getInfo(CURLINFO_HTTP_CODE);
        $curl->close();

        // read() returns string — catch empty response
        if ($content === '') {
            return '{"error":"empty_response","error_description":"No response received from the OAuth server."}';
        }

        if ($httpCode >= 400) {
            $this->oauthUtility->customlog("Curl: HTTP error " . $httpCode . " from: " . $url);
            $preview = mb_substr((string) $content, 0, 500);
            $this->oauthUtility->customlog("Curl: Error response body: " . $preview);
        }

        return $content;
    }
}
