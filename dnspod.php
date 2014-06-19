<?php
namespace DnsPod;
use \Exception, \ErrorException, \ReflectionClass;

/**
 * DnsPod.com interface for dynamic DNS record update.
 * Designed to comply with Synology NAS DDNS service requirements, but can be
 * used stand-alone.
 *
 * /!\ As area-specific DNS is a pain in the ass to handle due to the lack of
 *     endpoint on DnsPod API to grab an up-to-date list of handled areas
 *     and inconsistencies between the values expected by the API, those
 *     returned by it and ISO codes, DNS record will always be updated with a
 *     'Default' area.
 *
 * Usage
 * -----
 * - From PHP:
 *      - Include the script, directly or by binding DnsPod namespace to it via your favorite autoloader
 *      - Invoke the built-in static update method
 *
 *      include("dnspod.php");
 *      DnsPod\DnsPod::update("foo@bar.com", "mypassword", "foo.bar.com");
 *
 * - From command line:
 *      - If you want to invoke the script directly, give it executable rights and add the following shebang on the very
 *        first line of the file, moving the PHP opening tag to line 2:
 *        #!/usr/bin/env php
 *      - Then execute it with the required arguments. If your password contains other characters than ascii, pass it in single quotes
 *        /path/to/dnspod foo@bar.com 'mypassword' foo.bar.com
 *
 * @author    Pierre Guillaume <root@e-lixir.fr>
 * @copyright 2013 e-Lixir
 * @license   MIT <http://opensource.org/licenses/mit-license.php>
 * @requires  php5(-cli), php5-curl
 */

// Environment detection
define("CLI", php_sapi_name() == "cli" || empty($_SERVER['REMOTE_ADDR']));

/**
 * DnsPod core class.
 */
class DnsPod {
   // /////////////////////////////////////////////////////////////////////////
   // Configuration variables below can be hardcoded if you want.

   /**
    * DnsPod username.
    *
    * @access private
    * @var string
    */
   private $username = null;

   /**
    * DnsPod password.
    *
    * @access private
    * @var string
    */
   private $password = null;

   /**
    * FQDN of the (sub)domain to update.
    *
    * @access private
    * @var string
    */
   private $fqdn = null;

   /**
    * TTL of updated record.
    *
    * @access public
    * @var int
    */
   public $ttl = 300;

   // End of hardcodable vars.
   // /////////////////////////////////////////////////////////////////////////

   /**
    * Domain to update.
    *
    * @access private
    * @var string
    */
   private $domain;

   /**
    * Subdomain to update.
    *
    * @access private
    * @var string
    */
   private $sub_domain;

   /**
    * Current external IP of device.
    *
    * @access private
    * @var string
    */
   private $ip;

   /**
    * Authentication token.
    *
    * @access private
    * @var string
    */
   private $auth;

   /**
    * URL to grab current IP of device. Should only return the sender IP.
    * Default to icanhazip.com, that is efficient and free.
    *
    * @static
    * @access private
    * @var string
    */
   private static $grabber_url = "http://icanhazip.com";

   /**
    * Success return statuses.
    *
    * @static
    * @access public
    * @var array
    */
   public static $RETURNS_OK = array("good", "nochg");

   /**
    * Default options for cURL requests.
    *
    * @static
    * @access public
    * @var array
    */
   public static $DEFAULT_CURL_OPTIONS = array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSL_VERIFYPEER => 0,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HEADER => true,
      CURLOPT_USERAGENT => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.8; rv:30.0) Gecko/20100101 Firefox/30.0"
   );

   /**
    * Enable debugging?
    * Debug information will be displayed on standard output.
    *
    * @static
    * @access public
    * @var bool
    */
   public static $debug = false;

   /**
    * Return codes, as defined in /etc/ddns_provider.conf on Synology NAS.
    * The last one has been added as catch-all.
    */
   const RETURN_UPDATE_SUCCESSFUL = "good";
   const RETURN_UPDATE_SUCCESSFUL_TEXT = "Update successfully";
   const RETURN_NO_CHANGE = "nochg";
   const RETURN_NO_CHANGE_TEXT = "Update successfully but the IP address have not changed";
   const RETURN_NO_HOST = "nohost";
   const RETURN_NO_HOST_TEXT = "The hostname specified does not exist in this user account";
   const RETURN_ABUSE = "abuse";
   const RETURN_ABUSE_TEXT = "The hostname specified is blocked for update abuse";
   const RETURN_INVALID_HOST = "notfqdn";
   const RETURN_INVALID_HOST_TEXT = "The hostname specified is not a fully-qualified domain name";
   const RETURN_AUTHENTICATION_FAILED = "badauth";
   const RETURN_AUTHENTICATION_FAILED_TEXT = "Authenticate failed";
   const RETURN_MAINTENANCE = "911";
   const RETURN_MAINTENANCE_TEXT = "There is a problem or scheduled maintenance on provider side";
   const RETURN_BAD_REQUEST = "badagent";
   const RETURN_BAD_REQUEST_TEXT = "The user agent sent bad request(like HTTP method/parameters is not permitted)";
   const RETURN_RESOLV_ERROR = "badresolv";
   const RETURN_RESOLV_ERROR_TEXT = "Failed to connect to  because failed to resolve provider address";
   const RETURN_TIMEOUT = "badconn";
   const RETURN_TIMEOUT_TEXT = "Failed to connect to provider because connection timeout";
   const RETURN_ERROR = "error";
   const RETURN_ERROR_TEXT = "An error has occured";

   /**
    * URL of DnsPod API.
    */
   const URL_DNSPOD_API = "https://api.dnspod.com";

   /**
    * FQDN regexp, based on RCF 952 <http://tools.ietf.org/html/rfc952>.
    */
   const HOSTNAME_REGEXP = "`^(([a-zA-Z]|[a-zA-Z][a-zA-Z0-9\-]*[a-zA-Z0-9])\.)*([A-Za-z]|[A-Za-z][A-Za-z0-9\-]*[A-Za-z0-9])$`";

   /**
    * Create a new DnsPod instance.
    * First parameters can be left empty if they have been harcoded above.
    * If left empty, current IP will be grabbed from $grabber_url, or default icanhazip.com.
    *
    * @access public
    * @param  string $username    DnsPod username
    * @param  string $password    DnsPod password
    * @param  string $fqdn        Fully qualified domain name to update
    * @param  string $ip          Current external IP of the device
    * @param  string $grabber_url URL of a page that will return sender IP
    * @return void
    */
   public function __construct($username = null, $password = null, $fqdn = null, $ip = null, $grabber_url = null) {
      $vars = array("username", "password", "fqdn", "ip");

      // Variables initialization
      foreach($vars as $var)
         if(!is_null($$var))
            $this->$var = $$var;
      if(!is_null($grabber_url))
         static::$grabber_url = $grabber_url;

      // Sanity checks
      if(!extension_loaded("curl"))
         throw new DnsPodException("cURL extension must be installed in order for DnsPod to work");
      foreach($vars as $var)
         if(empty($this->$var) && $var != "ip")
            static::message(sprintf("Missing parameter %s", $var), "error");
      if(!preg_match(static::HOSTNAME_REGEXP, $fqdn))
         static::message(null, static::RETURN_INVALID_HOST);

      // FQDN analysis
      $hostname = explode(".", $this->fqdn);
      $this->domain = implode(".", array_splice($hostname, -2));
      $this->sub_domain = count($hostname) ? implode(".", $hostname) : "@";
   }

   /**
    * One-liner to update a DnsPod record.
    *
    * @static
    * @access public
    * @param  string $username    DnsPod username
    * @param  string $password    DnsPod password
    * @param  string $fqdn        Fully qualified domain name to update
    * @param  string $ip          Current external IP of the device
    * @param  string $grabber_url URL of a page that will return sender IP
    * @return void
    */
   public static function update($username = null, $password = null, $fqdn = null, $ip = null, $grabber_url = null) {
      $dnspod = new static($username, $password, $fqdn, $ip, $grabber_url);
      $dnspod->update_record();
   }

   /**
    * Display a message if executed in CLI environment, and throw an exception if an error code was passed.
    *
    * @static
    * @access public
    * @param  string  $message Message to display
    * @param  string  $code    String code to return
    * @return void
    */
   public static function message($message = null, $code = null) {
      if(!$message && !$code)
         return;

      // Message detection from code
      if($code && !$message) {
         $nfo = new ReflectionClass(get_called_class());
         if(!($error = array_search($code, $nfo->getConstants()))) {
            $code = "error";
            $error = "RETURN_ERROR";
         }
         $message = constant(get_called_class() . "::" . $error . "_TEXT");
      }

      // Message display
      if(CLI) {
         $fe = fopen("php://stderr", "w");
         fwrite($fe, $message . "\n");
         fclose($fe);
         if($code) {
            echo $code . "\n";
            exit(in_array($code, static::$RETURNS_OK) ? 0 : 1);
         }
      }

      // Error handling
      elseif(!in_array($code, static::$RETURNS_OK))
         throw new DnsPodException($message, 0, null, $code);
   }

   /**
    * Send an HTTP request and return an object containing response and an error code if something went wrong.
    * If response is in JSON format, it will be return decoded as an object.
    *
    * @static
    * @access private
    * @param  string  $url     URL to which send the request
    * @param  array   $data    POST data
    * @param  array   $options cURL options
    * @return object
    */
   private static function request($url, array $data = array(), array $options = array()) {
      // Request preparation, execution, and info grabbing
      $data = http_build_query($data);
      $options = static::$DEFAULT_CURL_OPTIONS + $options + array(
         CURLOPT_URL => $url
      );
      if(!empty($data))
         $options += array(
            CURLOPT_POSTFIELDS => $data
         );
      if(static::$debug)
         $options += array(CURLINFO_HEADER_OUT => true);
      $ch = curl_init();
      curl_setopt_array($ch, $options);
      $response = curl_exec($ch);
      $errno = curl_errno($ch);
      $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      if(static::$debug)
         $request = curl_getinfo($ch, CURLINFO_HEADER_OUT);
      curl_close($ch);

      // Debug information
      if(static::$debug) {
         $s = str_repeat("=", 80) . "\n" . $url . "\n" . str_repeat("=", 80) . "\n";
         $s .= str_repeat("-", 80) . "\nRequest\n" . str_repeat("-", 80) . "\n" . $request . ($data ? $data . "\n\n" : "");
         $s .= str_repeat("-", 80) . "\nResponse\n" . str_repeat("-", 80) . "\n" . $response . "\n";
         echo CLI ? $s : "<pre>" . $s . "</pre>";
      }

      // Error handling
      if($errno) {
         if(in_array($errno, array(CURLE_COULDNT_RESOLVE_PROXY, CURLE_COULDNT_RESOLVE_HOST)))
            $error = static::RETURN_RESOLV_ERROR;
         elseif($errno == CURLE_OPERATION_TIMEOUTED)
            $error = static::RETURN_TIMEOUT;
         else $error = static::RETURN_BAD_REQUEST;
      }
      elseif($status == 401)
         $error = static::RETURN_AUTHENTICATION_FAILED;
      elseif($status == 503)
         $error = static::RETURN_MAINTENANCE;
      elseif($status >= 400)
         $error = static::RETURN_BAD_REQUEST;
      else $error = null;

      // Content handling.
      $response = explode("\r\n\r\n", $response);
      $headers = array_shift($response);
      $response = trim(implode("\r\n", $response));
      return (object) compact("response", "error");
   }

   /**
    * Authenticate with the DnsPod API.
    *
    * @access private
    * @return void
    */
   private function authenticate() {
      $res = static::api("Auth", array("login_email" => $this->username, "login_password" => $this->password));
      try {
         $this->auth = $res->user_token;
      }
      catch(Exception $e) {
         static::message("Unexpected API response");
      }
   }

   /**
    * Send an HTTP request to DnsPod API and return the response.
    *
    * @access private
    * @param  string $uri     URI to which send the request
    * @param  array  $data    POST data
    * @param  array  $options cURL options
    * @return object
    */
   private function api($uri, array $data = array(), array $options = array()) {
      if(substr($uri, 0, 1) != "/")
         $uri = "/" . $uri;
      if(!$this->auth && $uri != "/Auth") {
         $this->authenticate();
      }
      if($this->auth)
         $data += array("user_token" => $this->auth);
      $data += array("format" => "json");
      $res = static::request(static::URL_DNSPOD_API . $uri, $data, $options);
      if($res->error)
         static::message(null, $res->error);
      try {
         $res = json_decode($res->response);
      }
      catch(Exception $e) {
         static::message("Unexpected API response");
      }
      if($res->status->code != 1)
         static::message($res->status->message, $res->status->code);
      else return $res;
   }

   /**
    * Manually grab the current external IP of device.
    *
    * @static
    * @access public
    * @return string
    */
   public static function ip() {
      $res = static::request(static::$grabber_url);
      if($res->error)
         static::message(null, $res->error);
      return $res->response;
   }

   /**
    * Update the DNS record.
    *
    * @access public
    * @return void
    */
   public function update_record() {
      // We grab current ip if it has not been passed to constructor
      if(is_null($this->ip))
         $this->ip = static::ip();

      // We grab current DNS records for domain
      try {
         $domain_id = $this->api("Domain.Info", array("domain" => $this->domain))->domain->id;
      }
      catch(Exception $e) {
         static::message(null, static::RETURN_NO_HOST);
      }
      $records = $this->api("Record.List", compact("domain_id") + array("sub_domain" => $this->sub_domain))->records;

      // We handle update
      $data = json_encode(array(
         "area" => "0",
         "sub_domain" => $this->sub_domain,
         "record_type" => "A",
         "value" => $this->ip,
         "mx" => "",
         "ttl" => (string) $this->ttl
      ));
      $enabler = json_encode(array("status" => "enable"));
      foreach($records as $record) {
         // We remove records matching the subdomain but of CNAME type or in a custom line
         if($record->type == "CNAME" || $record->line != "Default") {
            $this->api("Record.Remove", compact("domain_id") + array("record_id" => $record->id));
            continue;
         }

         // Otherwise we update the record if needed
         $found = true;
         if($record->value != $this->ip) {
            $this->api("Record.Modify", compact("domain_id") + array(
               "record_id" => $record->id,
               "sub_domain" => $this->sub_domain,
               "record_type" => "A",
               "record_line" => "default",
               "value" => $this->ip,
               "ttl" => $this->ttl
            ));
            static::message(null, static::RETURN_UPDATE_SUCCESSFUL);
         }
         static::message(null, static::RETURN_NO_CHANGE);
      }

      // We create it if it does not exist
      if(empty($found)) {
         $this->api("Record.Create" . compact("domain_id") + array(
            "sub_domain" => $this->sub_domain,
            "record_type" => "A",
            "record_line" => "default",
            "value" => $this->ip,
            "ttl" => $this->ttl
         ));
         static::message(null, static::RETURN_UPDATE_SUCCESSFUL);
      }
   }
}

/**
 * DnsPod exception class.
 */
class DnsPodException extends Exception {
   public function __construct($message, $code = 0, Exception $previous = null, $error = "error") {
      parent::__construct($message, $code, $previous);
      $this->error = $error;
   }
}

/**
 * CLI usage.
 */
if(CLI) {
   // Error handling
   set_exception_handler(function($e) {
      if(property_exists($e, "error"))
         $code = $e->error;
      else $code = DnsPod::RETURN_ERROR;
      DnsPod::message($e->getMessage(), $code);
   });
   set_error_handler(function($code, $error, $file, $line) {
      throw new \ErrorException($error, $code, 0, $file, $line);
   });

   // Synology DSM passes all 4 arguments in a single parameter, separated by spaces
   if($argc == 2)
      $args = explode(" ", $argv[1]);

   // Standard parameters
   else $args = array_splice($argv, 1);
   if(count($args) < 3 || count($args) > 4) {
      echo "Usage: php " . $_SERVER['SCRIPT_NAME'] . " USERNAME PASSWORD HOSTNAME [IP]\n";
      exit(1);
   }
   DnsPod::update($args[0], $args[1], $args[2], count($args) == 4 ? $args[3] : null);
}
