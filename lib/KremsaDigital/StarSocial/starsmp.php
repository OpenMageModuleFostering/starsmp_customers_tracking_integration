<?php

class StarSMPSettings
{
    public $clientSecret;
    public $clientId;
    public $loyaltyId;
    public $debugLog;
}

class StarSMPAsync
{
    protected $sdk = null;
    protected $clientSecret = null;
    protected $queue = array();
    protected $connectTimeout = 3;
    protected $startupUs = 125000;

    public function __construct(StarSMPSettings $settings) {
        $this->sdk = new StarSMP($settings);
        $this->clientSecret = $settings->clientSecret;

        if (isset($_GET[$this->getAsyncKey()])) {
            $this->sdk->debug("async restore", array("url" => $this->getAsyncUrlInfo(), "data" =>file_get_contents("php://input")));

            ignore_user_abort(true);
            $this->wakeup(file_get_contents("php://input"));
            $this->flush();
            exit;
        }
        else {
        }
    }

    protected function getAsyncUrlInfo() {
        $scheme = "http://";
        $host =  $_SERVER["HTTP_HOST"];
        $port = 80;
        $uri = $_SERVER["PHP_SELF"] . "?" . $this->getAsyncKey() . "=1";
        return compact("scheme", "host", "port", "uri");
    }

    public function execute() {
        $this->sdk->debug("async init", array("url" => $this->getAsyncUrlInfo(), "data" => $this->queue));

        // php-fpm makes this easy.
        if (function_exists("fastcgi_finish_request")) {
            fastcgi_finish_request();
            $this->flush();
            return;
        }

        $info = $this->getAsyncUrlInfo();

        $sock = @fsockopen($info["host"], $info["port"], $errno, $error, $this->connectTimeout);
        if (!$sock)
            throw new Exception("Unable to initiate connection: " . $error, $errno);

        $data = $this->sleep();
        fwrite($sock,
            "POST " . $info["uri"] . " HTTP/1.1\r\n" .
            "Host: " . $info["host"] . ":" . $info["port"] . "\r\n" .
            "Connection: close\r\n" .
            "Content-Type: application/x-php-serialized\r\n" .
            "Content-Length: " . strlen($data) . "\r\n" .
            "\r\n");
        fwrite($sock, $data);

        // Give the socket a bit of time for startup.
        usleep($this->startupUs);

        fclose($sock);
    }

    protected function getAsyncKey() {
        return "async_" . sha1(sha1_file(__FILE__) . $this->sdk->getClientId() . $this->clientSecret);
    }

    public function sleep() {
        $response = serialize($this->queue);
        $this->queue = array();
        return $response;
    }

    public function wakeup($data) {
        $this->queue = unserialize($data);
    }

    public function flush() {
        foreach ($this->queue as $item) {
            $response = call_user_func_array(array($this->sdk, $item["method"]), $item["args"]);
        }
        $this->queue = array();
    }

    public function __call($method, $args) {
        $this->queue[] = compact("method", "args");
    }
}

class StarSMP
{
    const PHP_SDK_VERSION = 1.1;

    // @var int|float API call timeout in seconds.
    protected $timeout = 10;
    // @var string API endpoint (normally not changed.)
    protected $apiUrl = "https://api.starsmp.com/api/";
    // @var string OAuth2 client id.
    protected $clientId;
    // @var string OAuth2 client secret.
    protected $clientSecret;
    // @var string OAuth2 access token.
    private $accessToken;
    // @var string proxy uri, use tcp://hostname:port.
    private $proxy;
    // @var string loyalty program id, treat as opaque.
    private $loyaltyId;
    // @var string vipfan id, treat as opaque.
    private $vipfanId;
    // @var debug file location
    private $debugLog;
    // @var cookie name prefix
    private $cookiePrefix = "__star_";

    public function getClientId() {
        return $this->clientId;
    }

    public function setApiUrl($url) {
        return $this->apiUrl = $url;;
    }

    public function debug($message, $object = null) {
        if ($this->debugLog != null)
        {
            $date = date(DATE_ISO8601);
            $object = json_encode($object);
            $debug = json_encode(debug_backtrace());

            $data = "[{$date}] {$message}\n -> {$object}\n -> {$debug}\n\n";
            file_put_contents($this->debugLog, $data, FILE_APPEND);
        }
    }

    public function setProxy($proxy) {
        $this->proxy = $proxy;
    }

    public function __construct(StarSMPSettings $settings) {
        if (empty($settings->loyaltyId))
            throw new Exception("Loyalty ID missing");

        $this->loyaltyId = $settings->loyaltyId;
        $this->clientId = $settings->clientId;
        $this->clientSecret = $settings->clientSecret;
        $this->debugLog = $settings->debugLog;

        $this->debug("construct", $settings);
    }

    public function getAccessToken() {
        return $this->accessToken;
    }

    public function setAccessToken($token) {
        $this->accessToken = $token;
    }

    public function authorize() {
        $response = $this->api("auth.token", array(
            "grant_type" => "none",
            "client_id" => $this->clientId,
            "client_secret" => $this->clientSecret,
            "return" => 1,
        ));

        if (!isset($response["response"]["access_token"]))
            throw new Exception("Invalid client id or secret provided");

        $this->accessToken = $response["response"]["access_token"];
        return true;
    }

    public function optin($params, $instance = null) {
        if ($this->accessToken == null)
            $this->authorize();

        if (!isset($params["email"]) && !isset($params["fb_user_id"])) {
            throw new Exception("email or fb_user_id is required for optin");
        }

        if (!is_null($instance))
            $params += array("instance" => $instance);

        $response = $this->api("loyalty.optin", $params);
        if (!isset($response["response"]["id"]))
            throw new Exception("Failed to optin vipfan");

        $this->vipfanId = $response["response"]["id"];
        return $response["response"];
    }

    public function logout() {
        $this->vipfanId = null;
    }

    /**
     * Generate a Cookie: header line for the API call.
     *
     * This can be overridden to get them from somewhere else.
     * Default functionality is prefixed local cookies.
     * See also saveRecievedCookies().
     *
     * @return string|null
     */
    protected function generateCookieHeader() {

        $cookie_data = null;

        if (!empty($_COOKIE)) {
            $cookie_parts = array();
            foreach ($_COOKIE as $key => $val) {
                // send only cookies with our prefix
                if (strpos($key, $this->cookiePrefix) === 0) {
                    $key = str_replace($this->cookiePrefix, '', $key);
                    $cookie_parts[] = $key . "=" . $val;
                }
            }
            if (!empty($cookie_parts)) {
                $cookie_data = "Cookie: " . implode("; ", $cookie_parts);
            }
        }

        return $cookie_data;
    }

    /**
     * Do the post request
     * @param $url
     * @param array $postData
     * @return string
     */
    protected function webRequest($url, $postData = array()) {
        $this->debug("request: {$url}", $postData);

        // get cookie data and send them together with request
        $cookie_data = $this->generateCookieHeader();

        $content = http_build_query($postData, null, "&");
        $context = array(
            "http" => array(
                "method" => "POST",
                "header" =>
                    "Connection: close" . "\r\n" .
                    "Content-Length: " . strlen($content) . "\r\n" .
                    "Content-Type: application/x-www-form-urlencoded" . "\r\n" .
                    (isset($cookie_data) ? $cookie_data . "\r\n" : ""),
                "user_agent" => "smp-php-sdk-" . self::PHP_SDK_VERSION . ". php/" . PHP_VERSION,
                "content" => $content,
                "protocol_version" => "1.0",
                "ignore_errors" => true,
                "timeout" => $this->timeout,
            ),
            "ssl" => array(
            ),
        );

        if (!is_null($this->proxy))
        {
            $context["http"]["request_fulluri"] = true;
            $context["http"]["proxy"] = $this->proxy;
        }

        $result = null;

        try {
            $context = stream_context_create($context);
            $result = file_get_contents($url, false, $context);
        }
        catch (Exception $e) {
            $this->debug("error", $e);
        }

        // parse recieved cookies
        $this->saveRecievedCookies($http_response_header);

        return $result;
    }

    /**
     * Parse recieved cookies and save them with our own prefix
     * @param $headers - response headers
     */
    protected function saveRecievedCookies($headers) {
        foreach ($headers as $hdr) {
            if (!preg_match('/^Set-Cookie:\s*([^;]+);{0,1}\s*(.+)/', $hdr, $matches)) {
                continue;
            }

            // cookie name=value
            list($cookie_name, $cookie_value) = explode("=", $matches[1]);
            $cookie_name = $this->cookiePrefix . $cookie_name;

            // get allowed cookie attributes
            $cookie_attr = array(
                "expires" => 0,
                "path" => "",
                "domain" => "",
                "secure" => false,
                "httponly" => false
            );

            // overrride default attribute values
            if (isset($matches[2])) {
                $attr = explode(";", $matches[2]);
                foreach ($attr as $name_value) {
                    $attr_parts = explode("=", $name_value);
                    $attr_name = trim($attr_parts[0]);
                    if (isset($attr_parts[1])) {
                        $attr_val = trim($attr_parts[1]);
                        if ($attr_name=="expires") {
                            $attr_val = strtotime($attr_val);
                        }
                    }
                    else {
                        // for secure and httponly
                        $attr_val = true;
                    }
                    // set only allowed cookie attributes
                    if ($this->isAllowedCookieAttribute($attr_name)) {
                        $cookie_attr[$attr_name] = $attr_val;
                    }
                }
            }

            // set cookie
            if ($cookie_name!="") {
                $_COOKIE[$cookie_name] = $cookie_value;
                setcookie($cookie_name, $cookie_value, $cookie_attr['expires'], $cookie_attr['path'], $cookie_attr['domain'], $cookie_attr['secure'], $cookie_attr['httponly']);
            }
        } // end foreach
    }

    /**
     * Check if given cookie attribute is allowed to be set at our side
     * @param $attr_name - cookie attribute name
     * @return bool - true if it's allowed, otherwise false
     */
    private function isAllowedCookieAttribute($attr_name) {
        $allowed_attributes = array("httponly", "expires");
        return in_array($attr_name, $allowed_attributes);
    }

    public function exception_handler($errno, $errstr, $errfile, $errline) {
        throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
    }

    /**
     * Calls API
     * @param string $method
     * @param array $parameters
     * @return mixed
     * @throws Exception
     */
    public function api($method, $parameters) {
        $this->debug("api call: {$method}", $parameters);

        $params = $parameters + array(
            "method" => $method,
            "id_loyalty" => $this->loyaltyId,
            "access_token" => $this->accessToken,
            "id_vipfan" => $this->vipfanId
        );

        $return = $this->webRequest($this->apiUrl, $params);
        $return = json_decode($return, true);

        if (!isset($return["result"]) || $return["result"] != "success") {
            $error = isset($return["error"]) ? $return["error"] : "";
            $error = (empty($error) && isset($return["response"]) ? print_r($return["response"], true) : $error);
            $error = (empty($error) ? print_r($return, true) : $error);

            throw new Exception("SMP API Error: " . $error);
        }

        return $return;
    }

    public function setVipfanID($vipfanId) {
        $this->debug("set vipfan", $vipfanId);
        $this->vipfanId = $vipfanId;
    }

    /**
     * Creates a new vip.me link from given url
     * @param string $url
     * @param string $label Label of the link (campaign)
     * @param array $og_tags
     * @return mixed
     */
    public function createLink($url, $label = "", $og_tags = array()) {
        return $this->api("link.simple", array(
            "opengraph_data" => is_array($og_tags) ? json_encode($og_tags) : $og_tags,
            "label" => $label,
            "url" => $url
        ));
    }

    /**
     * Tracks action on given instance (url)
     * @param string $action
     * @param string $instance Page url
     * @param float $value Optional value of the tracked action
     * @return mixed
     */
    public function track($action, $instance, $value = 0) {
        if ($this->accessToken == null)
            $this->authorize();

        return $this->api("action.track", array(
            "action" => $action,
            "instance" => $instance,
            "value" => $value
        ));
    }

    static function create_signed_request($data, $secret) {
        $data['algorithm'] = 'HMAC-SHA256';
        $payload = rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        $signature = hash_hmac('sha256', $payload, $secret, $raw = true);
        $signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return $signature . '.' . $payload;
    }
}

?>