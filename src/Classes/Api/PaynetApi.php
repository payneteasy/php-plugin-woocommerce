<?php

namespace Payneteasy\Classes\Api;

(defined('ABSPATH') || PHP_SAPI == 'cli') or die('Restricted access');

use \Payneteasy\Classes\Common\Request,
    \Payneteasy\Classes\Exception\PayneteasyException,
    \Symfony\Component\Dotenv\Dotenv;

/**
 * Класс PaynetApi предоставляет методы для взаимодействия с API PAYNET.
 */
class PaynetApi extends Request
{
    protected $url = 'paynet/api/v2/';
    protected $live_url = '';
    protected $sandbox_url = '';
    protected $login = '';
    protected $password = '';
    protected $endpoint = '';
    protected $integration_method = ''; // direct & form
    protected $type = ''; // live & sandbox

    public function __construct($login, $password, $endpoint, $integration_method, $type) {
        $this->login = $login;
        $this->password = $password;
        $this->endpoint = $endpoint;
        $this->integration_method = $integration_method;
        $this->type = $type;
    }

    public function saleDirect($data, $integration_method, $type, $action_url, $endpoint)
    {
        return $this->execute('sale/' . $endpoint, $data, 'POST', $integration_method, $type, $action_url);
    }

    public function return($data, $integration_method, $type, $action_url, $endpoint)
    {
        return $this->execute('return/' . $endpoint, $data, 'POST', $integration_method, $type, $action_url);
    }

    public function status($data, $integration_method, $type, $action_url, $endpoint)
    {
        return $this->execute('status/' . $endpoint, $data, 'POST', $integration_method, $type, $action_url);
    }

    public function saleForm($data, $integration_method, $type, $action_url, $endpoint)
    {
        return $this->execute('sale-form/' . $endpoint, $data, 'POST', $integration_method, $type, $action_url);
    }

    protected function execute($action, $data, $method, $integration_method, $type, $action_url) {
        return $this->curlRequestHandler($action, $data, $method, $integration_method, $type, $action_url);
    }

    protected function curlRequestHandler($action, $data, $method, $integration_method, $type, $action_url)
    {
        $curl = curl_init($action_url.$this->url.$action);

        curl_setopt_array($curl, array
        (
            CURLOPT_HEADER         => 0,
            CURLOPT_USERAGENT      => 'Payneteasy-Client/1.0',
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_POST           => 1,
            CURLOPT_RETURNTRANSFER => 1
        ));

        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));

        $response = curl_exec($curl);

        if(curl_errno($curl))
        {
            $error_message  = 'Error occurred: ' . curl_error($curl);
            $error_code     = curl_errno($curl);
        }
        elseif(curl_getinfo($curl, CURLINFO_HTTP_CODE) != 200)
        {
            $error_code     = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error_message  = "Error occurred. HTTP code: '{$error_code}'";
        }

        curl_close($curl);

        if (!empty($error_message))
        {
            throw new PayneteasyException($error_message, [
                'response' => $error_code
            ]);
        }

        if(empty($response))
        {
            throw new PayneteasyException('Host response is empty', [
                'response' => $response
            ]);
        }

        $responseFields = array();

        parse_str($response, $responseFields);

        return $responseFields;

    }

    protected function parseHeadersToArray($rawHeaders)
    {
        $lines = explode("\r\n", $rawHeaders);
        $headers = [];
        foreach($lines as $line) {
            if (strpos($line, ':') === false ){
                continue;
            }
            list($key, $value) = explode(': ', $line);
            $headers[$key] = $value;
        }
        return $headers;
    }

    protected function encode($data)
    {
        if (is_string($data)) {
            return $data;
        }
        $result = json_encode($data);
        $error = json_last_error();
        if ($error != JSON_ERROR_NONE) {
            throw new PayneteasyException('JSON Error: ', [
                'response' => json_last_error_msg()
            ]);
        }
        return $result;
    }

    public static function prepareOrderId($orderId, $forUrl = false)
    {
        $orderId = str_replace(['/','#','?','|',' '], ['-'], $orderId);
        if ($forUrl) {
            $orderId = urlencode($orderId);
        }
        return $orderId;
    }
}
