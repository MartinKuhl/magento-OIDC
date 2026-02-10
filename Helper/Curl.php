<?php

namespace MiniOrange\OAuth\Helper;

use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * This class denotes all the cURL related functions.
 */
class Curl
{

    public static function mo_send_access_token_request($postData, $url, $clientID, $clientSecret,$header, $body)
    {
        if($header == 0 && $body == 1){
            $authHeader = [
                "Content-Type: application/x-www-form-urlencoded",
                'Accept: application/json',
            ];
        }
        else{
            $authHeader = [
                "Content-Type: application/x-www-form-urlencoded",
                'Accept: application/json',
                'Authorization: Basic '.base64_encode($clientID.":".$clientSecret)
            ];
        }
        $response = self::callAPI($url, $postData, $authHeader);
        return $response;
    }

    public static function mo_send_user_info_request($url, $headers)
    {

        $response = self::callAPI($url, [], $headers);
        return $response;
    }

    private static function callAPI($url, $jsonData = [], $headers = ["Content-Type: application/json"])
    {
        // Use Magento's standard cURL adapter
        $curl = new \Magento\Framework\HTTP\Adapter\Curl();
        $curl->setConfig(['header' => false]);
        $options = [
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_ENCODING' => "",
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_AUTOREFERER' => true,
            'CURLOPT_TIMEOUT' => 0,
            'CURLOPT_MAXREDIRS' => 10
        ];


        $data = in_array("Content-Type: application/x-www-form-urlencoded", $headers)
            ? (!empty($jsonData) ? http_build_query($jsonData) : "") : (!empty($jsonData) ? json_encode($jsonData) : "");

        $method = !empty($data) ? 'POST' : 'GET';
        $curl->setConfig($options);
        $curl->write($method, $url, '1.1', $headers, $data);
        $content = $curl->read();
        $curl->close();

        if (empty($content)) {
            return json_encode([
                'error' => 'empty_response',
                'error_description' => 'No response received from the OAuth server.'
            ]);
        }

        return $content;
    }
}
