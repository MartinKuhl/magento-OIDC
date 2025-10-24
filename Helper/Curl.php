<?php

namespace MiniOrange\OAuth\Helper;

use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * This class denotes all the cURL related functions.
 */
class Curl
{

    public static function create_customer($email, $company, $password, $phone = '', $first_name = '', $last_name = '')
    {
        $url = OAuthConstants::HOSTNAME . '/moas/rest/customer/add';
        $customerKey = OAuthConstants::DEFAULT_CUSTOMER_KEY;
        $apiKey = OAuthConstants::DEFAULT_API_KEY;
        $fields = [
            'companyName' => $company,
            'areaOfInterest' => OAuthConstants::AREA_OF_INTEREST,
            'firstname' => $first_name,
            'lastname' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'password' => $password
        ];
        $authHeader = self::createAuthHeader($customerKey, $apiKey);
        $response = self::callAPI($url, $fields, $authHeader);
        return $response;
    }

    public static function get_customer_key($email, $password)
    {
        $url = OAuthConstants::HOSTNAME . "/moas/rest/customer/key";
        $customerKey = OAuthConstants::DEFAULT_CUSTOMER_KEY;
        $apiKey = OAuthConstants::DEFAULT_API_KEY;
        $fields = [
            'email' => $email,
            'password' => $password
        ];
        $authHeader = self::createAuthHeader($customerKey, $apiKey);
        $response = self::callAPI($url, $fields, $authHeader);
        return $response;
    }

    public static function check_customer($email)
    {
        $url = OAuthConstants::HOSTNAME . "/moas/rest/customer/check-if-exists";
        $customerKey = OAuthConstants::DEFAULT_CUSTOMER_KEY;
        $apiKey = OAuthConstants::DEFAULT_API_KEY;
        $fields = [
            'email' => $email,
        ];
        $authHeader = self::createAuthHeader($customerKey, $apiKey);
        $response = self::callAPI($url, $fields, $authHeader);
        return $response;
    }

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

    public static function submit_contact_us(
        $q_email,
        $q_phone,
        $query
    ) {
        $url = OAuthConstants::HOSTNAME . "/moas/rest/customer/contact-us";
        $query = '[' . OAuthConstants::AREA_OF_INTEREST . ']: ' . $query;
        $customerKey = OAuthConstants::DEFAULT_CUSTOMER_KEY;
        $apiKey = OAuthConstants::DEFAULT_API_KEY;

        $fields = [
            'email' => $q_email,
            'phone' => $q_phone,
            'query' => $query,
            'ccEmail' => 'magentosupport@xecurify.com'
                ];

        $authHeader = self::createAuthHeader($customerKey, $apiKey);
        $response = self::callAPI($url, $fields, $authHeader);


        return true;
    }

//Tracking admin email,firstname and lastname.
public static function submit_to_magento_team(
    $timeStamp,
    $adminEmail,
    $domain,
    $miniorangeAccountEmail,
    $pluginFirstPageVisit,
    $environmentName,
    $environmentVersion,
    $freeInstalledDate,
    $identityProvider,
    $testSuccessful,
    $testFailed,
    $autoCreateLimit
    ) {
    $url = OAuthConstants::PLUGIN_PORTAL_HOSTNAME . "/api/tracking";
    $customerKey = OAuthConstants::DEFAULT_CUSTOMER_KEY;
    $apiKey = OAuthConstants::DEFAULT_API_KEY;

    // $timeStamp = time();
    $pluginName = OAuthConstants::MODULE_TITLE;
    $pluginVersion = OAuthConstants::PLUGIN_VERSION;
    $isFreeInstalled = 'Yes';
    $isTrialInstalled = '';
    $trialInstalledDate = '';
    $isPremiumInstalled = '';
    $premiumInstalledDate = '';
    $isSandboxInstalled = '';
    $sandboxInstalledDate = '';
    $pluginPlan = '';
    $serviceProvider = '';
    $backendMethod = '';
    $frontendMethod = '';
    $other = '';

    $fields = array(
        'timeStamp' => $timeStamp,
        'adminEmail' => $adminEmail,
        'domain' => $domain,
        'miniorangeAccountEmail' => $miniorangeAccountEmail,
        'pluginName' => $pluginName,
        'pluginVersion' => $pluginVersion,
        'pluginFirstPageVisit' => $pluginFirstPageVisit,
        'environmentName' => $environmentName,
        'environmentVersion' => $environmentVersion,
        'IsFreeInstalled' => $isFreeInstalled,
        'FreeInstalledDate' => $freeInstalledDate,
        'IsTrialInstalled' => $isTrialInstalled,
        'TrialInstalledDate' => $trialInstalledDate,
        'IsPremiumInstalled' => $isPremiumInstalled,
        'PremiumInstalledDate' => $premiumInstalledDate,
        'IsSandboxInstalled' => $isSandboxInstalled,
        'SandboxInstalledDate' => $sandboxInstalledDate,
        'pluginPlan' => $pluginPlan,
        'IdentityProvider' => $identityProvider,
        'ServiceProvider' => $serviceProvider,
        'testSuccessful' => $testSuccessful,
        'testFailed' => $testFailed,
        'backendMethod' => $backendMethod,
        'frontendMethod' => $frontendMethod,
        'autoCreateLimit' => $autoCreateLimit,
        'other' => $other
    );
    
     // Filter out empty fields
    $filteredFields = array_filter($fields, function ($value) {
        return $value !== null && $value !== '';
    });
    
    $field_string = json_encode($filteredFields);
    $authHeader = self::createAuthHeader($customerKey, $apiKey);
    $response = self::callAPI($url, $filteredFields, $authHeader);
    return true;
}


    public static function check_customer_ln($customerKey, $apiKey)
    {
        $url = OAuthConstants::HOSTNAME . '/moas/rest/customer/license';
        $fields = [
            'customerId' => $customerKey,
            'applicationName' => OAuthConstants::APPLICATION_NAME,
            'licenseType' => !MoUtility::micr() ? 'DEMO' : 'PREMIUM',
        ];

        $authHeader = self::createAuthHeader($customerKey, $apiKey);
        $response = self::callAPI($url, $fields, $authHeader);
        return $response;
    }

    private static function createAuthHeader($customerKey, $apiKey)
    {
        $currentTimestampInMillis = round(microtime(true) * 1000);
        $currentTimestampInMillis = number_format($currentTimestampInMillis, 0, '', '');

        $stringToHash = $customerKey . $currentTimestampInMillis . $apiKey;
        $authHeader = hash("sha512", $stringToHash);

        $header = [
            "Content-Type: application/json",
            "Customer-Key: $customerKey",
            "Timestamp: $currentTimestampInMillis",
            "Authorization: $authHeader"
        ];
        return $header;
    }

    private static function callAPI($url, $jsonData = [], $headers = ["Content-Type: application/json"])
    {
        // Custom functionality written to be in tune with Mangento2 coding standards.
        $curl = new MoCurl();
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
        return $content;
    }
}
