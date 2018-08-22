<?php
namespace Deepay;

class Deepay
{
    const VERSION           = '1.0.0';
    const USER_AGENT_ORIGIN = 'Deepay PHP Library';

    public static $api_key  = '';
    public static $environment = 'live';
    public static $user_agent  = '';
    public static $curlopt_ssl_verifypeer = FALSE;
    // private static $api_url = "https://deepay.net";
    private static $api_url = "http://cryptopay.demo.mopaoshu.com";

    public static function config($authentication)
    {
        if (isset($authentication['environment']))
            self::$environment = $authentication['environment'];

        if (isset($authentication['user_agent']))
            self::$user_agent = $authentication['user_agent'];
    }

    public static function request($url, $method = 'POST', $params = array(), $authentication = array())
    {
        $environment = isset($authentication['environment']) ? $authentication['environment'] : self::$environment;
        $user_agent  = isset($authentication['user_agent']) ? $authentication['user_agent'] : (isset(self::$user_agent) ? self::$user_agent : (self::USER_AGENT_ORIGIN . ' v' . self::VERSION));
        $curlopt_ssl_verifypeer = isset($authentication['curlopt_ssl_verifypeer']) ? $authentication['curlopt_ssl_verifypeer'] : self::$curlopt_ssl_verifypeer;

        if (empty($params['sign'])) {
            \Deepay\Exception::throwException(400, array('reason' => 'Invalid', 'message' => "Invalid sign"));
        }

        # Check if right environment passed
        $environments = array('live');

        if (!in_array($environment, $environments)) {
            $availableEnvironments = join(', ', $environments);
            \Deepay\Exception::throwException(400, array('reason' => 'BadEnvironment', 'message' => "Environment does not exist. Available environments: $availableEnvironments"));
        }

        $url       = self::$api_url . $url;
        $headers   = array();

        $curl      = curl_init();
        $curl_options = array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL            => $url
        );

        if ($method == 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            array_merge($curl_options, array(CURLOPT_POST => 1));
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        curl_setopt_array($curl, $curl_options);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_USERAGENT, $user_agent);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $curlopt_ssl_verifypeer);

        $response    = json_decode(curl_exec($curl), TRUE);
        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($http_status === 200)
            return $response;
        else
            \Deepay\Exception::throwException($http_status, $response);
    }

    public function md5Sign($data, $key, $connect = '', $is_md5 = true)
    {
        ksort($data);
        $string = '';
        foreach ($data as $k => $vo) {
            if ($vo != '') {
                $string .= $k . '=' . $vo . '&';
            }

        }
        $string = rtrim($string, '&');
        $result = $string . $connect . $key;
        return $is_md5 ? md5($result) : $result;

    }
}
