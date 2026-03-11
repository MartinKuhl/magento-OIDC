<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Helper;

use Magento\Framework\HTTP\Adapter\CurlFactory;

/**
 * HTTP client helper for OAuth/OIDC API requestsfinal .
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
     * @param  array  $postData     Token request body parameters
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
            $authHeader = [
                "Content-Type: application/x-www-form-urlencoded",
                'Accept: application/json',
            ];
        } else {
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
     * @param  array  $headers HTTP headers (including Authorization)
     * @return string JSON response
     */
    public function sendUserInfoRequest(string $url, $headers): string
    {
        return $this->callAPI($url, [], $headers);
    }

    /**
     * Internal HTTP request method.
     *
     * @param  string $url      Request URL
     * @param  array  $jsonData Request body data
     * @param  array  $headers  HTTP headers
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
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_MAXREDIRS' => 10,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ];

        $data = in_array("Content-Type: application/x-www-form-urlencoded", $headers)
            ? (empty($jsonData) ? "" : http_build_query($jsonData))
            : (empty($jsonData) ? "" : (string) json_encode($jsonData));

        $method = $data === '' ? 'GET' : 'POST';
        $curl->setConfig($options);
        $curl->write($method, $url, '1.1', $headers, $data);
        $content = $curl->read();
        $httpCode = $curl->getInfo(CURLINFO_HTTP_CODE);
        $curl->close();

        // read() returns string — catch empty response
        if ($content === '') {
            return '{"error":"empty_response","error_description":"No response received from the OAuth server."}';
        }

        if ($httpCode >= 400) {
            $this->oauthUtility->customlog("Curl: HTTP error " . $httpCode . " from: " . $url);
            $preview = mb_substr($content, 0, 500);
            $this->oauthUtility->customlog("Curl: Error response body: " . $preview);
        }

        return $content;
    }
}
