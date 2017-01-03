<?php
/**
 * @file
 * Microservice Client.
 */

use \Curl\Curl;

require_once 'libraries/php-curl-class/src/Curl/CaseInsensitiveArray.php';
require_once 'libraries/php-curl-class/src/Curl/Curl.php';

/**
 * Microservice API wrapper class.
 */
class MicroserviceClient {

  // Curl Class object.
  protected $curl = FALSE;
  // Access Token for POST, PUT, SEARCH requests.
  protected $AccessToken = FALSE;
  // SecureKey required for POST, PUT, SEARCH requests.
  protected $secureKey = FALSE;
  // Service URL.
  protected $Url = FALSE;
  // Folder where Get Requests to API will be cached.
  protected $cache_folder = FALSE;
  // Debug mode. If enabled, data will be printed via backdrop_set_message.
  protected $debug = FALSE;
  // We store cache based on token. It's a path to personal folder for current token.
  protected $current_cache_folder = FALSE;
  // TRUE If request has been served from cache.
  protected $cache_expiration = FALSE;
  // TRUE to disable cache and request directly to GitHub API.
  protected $disable_cache = FALSE;
  // Organisation or User name.
  protected $owner_name = FALSE;
  // Repository name.
  protected $repo_name = FALSE;
  // For how long we cache result.
  protected $age = FALSE;
  // How much results per page.
  public $per_page = 100;

  // Signature algorithm
  public $algorithm = 'sha256';

  /**
   * Constructor.
   */
  public function __construct($settings) {
    $this->reInitCurl();
    $this->setDebug();
    if($settings['url']) {
      $this->Url = $settings['url'];
    }
    if($settings['secureKey']) {
      $this->secureKey = $settings['secureKey'];
    }
    if($settings['AccessToken']) {
      $this->AccessToken = $settings['AccessToken'];
    }
  }

  /**
   * Initializate $this->curl with Curl class and preset headers and user agent.
   */
  public function reInitCurl() {
    $this->curl = new Curl();
    $this->curl->setHeader('Content-Type', 'application/json');
    $this->curl->setUserAgent('Backdrop CMS MicroserviceClient');
    $this->curl->setHeader('Accept', '*/*');

    if($this->AccessToken) {
      $this->curl->setHeader('access_token', $this->AccessToken);
    }
  }

  /**
   * Set debug value. False by default.
   *
   * @param $debug boolean
   *   TRUE or FALSE
   */
  public function setDebug($debug = FALSE) {
    $this->debug = $debug;
  }

  /**
   * Determine if curl request has been falen with error.
   *
   * @return boolean
   *   TRUE or FALSE based on answer from GitHub API.
   */
  public function isError() {
    return $this->curl->curlError;
  }

  /**
   * Get Curl details after request.
   *
   * @return array
   *   An array of request information:
   *     - code: the last error number. @see curl_errno.
   *     - message: A clear text error message for the last cURL operation. @see curl_error.
   *     - request_headers: an array of request headers.
   *     - response_headers: an array of response headers.
   */
  public function testingGetHeaders() {
    return array (
        'code' => $this->curl->curlErrorCode,
        'message' => $this->curl->curlErrorMessage,
        'request_headers' => $this->curl->requestHeaders,
        'response_headers' => $this->curl->responseHeaders
      );
  }

  /**
   * Get Curl details if error happen.
   *
   * @return
   *   An array of request information. @see testingGetHeaders.
   *   FALSE if there is no error.
   */
  public function getErrors() {
    if ($this->isError()) {
      return $this->testingGetHeaders();
    }
    return FALSE;
  }

  /**
   * Disable Get request caching to GitHub API.
   */
  public function disableCache() {
    $this->disable_cache = TRUE;
  }

  /**
   * Prepare directory to cache requests.
   *
   * @access protected
   */
  protected function prepareCacheFolder() {
    $root_folder = 'private://microservice_cache/';

    $client_folder = $this->Url;

    $this->current_cache_folder = $root_folder . $client_folder;
    file_prepare_directory($this->current_cache_folder, FILE_CREATE_DIRECTORY);

  }

  /**
   * Set debug value. False by default.
   *
   * @param $age integer
   *   Time in seconds. We will cache result based on request time + $age.
   */
  public function setAge($age) {
    $this->age = $age;
  }

  /**
   * Get current caching age.
   *
   * @access private
   * @return  integer
   *   Time in seconds @see setAge.
   */
  private function getResponseAge() {
    global $user;
    if ($this->age) {
      return $this->age;
    }
    if ($age_header = $this->curl->responseHeaders['Cache-Control']) {
      list($type, $maxage, $smaxage) = explode(',', $age_header);
      list($name, $age) = explode('=', $maxage);
      if ($user->uid == 0) {
        // Default max age is 60. Let's cache for anonymous users for 5 min.
        $age = $age * 5;
      }
      return $age;
    }
    return 0;
  }

  /**
   * Save cache for future requests.
   *
   * @access private
   * @param $command
   *   String value. GitHub API url with already placed owner and repo if required.
   * @param $params array
   *   Values for request. We create a hash file based on params and command to make sure that cache is unique for request.
   */
  private function cacheRequest($command, $params) {
    if ($this->disable_cache) {
      return FALSE;
    }
    $serialize_object = serialize(array('command' => $command, 'params' => $params));
    $file_name = hash('sha256', $serialize_object);

    $contents['response'] = $this->curl->response;
    $contents['age'] = $this->getResponseAge();
    $contents = json_encode($contents);

    $uri = $this->current_cache_folder . '/' . $file_name;
    file_unmanaged_save_data($contents, $uri, FILE_EXISTS_REPLACE);

  }

  /**
   * Return cache if exists.
   *
   * @access private
   * @param $command
   *   String value. GitHub API url with already placed owner and repo if required.
   * @param $params array
   *   Values for request. We create a hash file based on params and command to make sure that cache is unique for request.
   * @return
   *   FALSE if there is no cache. Object if cache exists and didnot expire yet.
   */
  private function getCacheRequest($command, $params) {
    if ($this->disable_cache) {
      return FALSE;
    }
    $serialize_object = serialize(array('command' => $command, 'params' => $params));
    $file_name = hash('sha256', $serialize_object);

    $uri = $this->current_cache_folder . '/' . $file_name;

    $filename = drupal_realpath($uri);

    if (file_exists($filename)) {
      $timestamp = filemtime($filename);
      if ($contents = @json_decode(file_get_contents($filename))) {
        if (($timestamp + $contents->age) > REQUEST_TIME) {

          $this->cache_expiration = $timestamp + $contents->age;

          if ($this->debug) {
            backdrop_set_message('Cache returned!');
          }
          return $contents->response;
        } else {
          if ($this->debug) {
            backdrop_set_message('No cache returned!'. ($timestamp + $contents->age) . '>'.REQUEST_TIME);
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * Determine if request has been cached.
   *
   * @return
   *   FALSE if not and time when cache get expired in TIMESTAMP.
   */
  public function isCached() {
    return $this->cache_expiration;
  }

  /**
   * Generate signature.
   *
   * @access protected
   * @return
   *   signature.
   */
  protected function signature($data) {
    return $this->algorithm . '=' . hash_hmac($this->algorithm, json_encode($data), $this->secureKey);
  }

  /**
   * Determine if request has been cached.
   *
   * @access protected
   * @return
   *   FALSE if error. Object with answer if request success.
   */
  protected function getResponse() {
    if ($this->debug) {
      backdrop_set_message('<pre>'.print_r($this->testingGetHeaders(), true).'</pre>');
    }
    if ($this->isError()) {
      return FALSE;
    }

    return $this->curl->response;
  }

  /**
   * Perform GET request to GitHub API and return answer.
   *
   * @access protected
   * @param $command
   *   String value. GitHub API url with tokens like :owner, :repo and ect.
   * @param $params array
   *   Values for request and tokens for request url. LIke :owner, :repo, :id and etc.
   * @return
   *   FALSE if request failed. Object if success.
   */
  public function get($ObjectID, $ObjectToken ) {

    $this->curl->setHeader('Token', $ObjectToken);
    $this->curl->get($this->Url . '/' . $ObjectID);
    $response =  $this->getResponse();
    return $response;
  }

  /**
   * Perform PUT request to GitHub API and return answer.
   *
   * @access protected
   * @param $command
   *   String value. GitHub API url with tokens like :owner, :repo and ect.
   * @param $params array
   *   Values for request and tokens for request url. LIke :owner, :repo, :id and etc.
   * @return
   *   FALSE if request failed. Object if success.
   */
  public function put($ObjectID, $ObjectToken, $data ) {

    $this->curl->setHeader('Token', $ObjectToken);

    $this->curl->put($this->Url . '/' . $ObjectID, $data);
    $response =  $this->getResponse();
    $this->reInitCurl();
    return $response;
  }

  /**
   * Perform POST request to GitHub API and return answer.
   *
   * @access protected
   * @param $command
   *   String value. GitHub API url with tokens like :owner, :repo and ect.
   * @param $params array
   *   Values for request and tokens for request url. LIke :owner, :repo, :id and etc.
   * @return
   *   FALSE if request failed. Object if success.
   */
  public function post($data) {

    $this->curl->setHeader('Signature', $this->signature($data));

    $this->curl->post($this->Url, $data);
    $response =  $this->getResponse();
    $this->reInitCurl();
    return $response;
  }

   /**
   * Perform SEARCH request to GitHub API and return answer.
   *
   * @access protected
   * @param $params array
   *   Values for request and tokens for request url. LIke :owner, :repo, :id and etc.
   * @return
   *   FALSE if request failed. Object if success.
   */
  public function search($data) {

    $this->curl->setHeader('Signature', $this->signature($data));

    $this->curl->search($this->Url, $data);
    $response = $this->getResponse();
    $this->reInitCurl();
    return $response;
  }

  /**
   * Perform DELETE request to GitHub API and return answer.
   *
   * @access protected
   * @param $command
   *   String value. GitHub API url with tokens like :owner, :repo and ect.
   * @param $params array
   *   Values for request and tokens for request url. LIke :owner, :repo, :id and etc.
   * @return
   *   FALSE if request failed. Object if success.
   */
  public function delete($ObjectID, $ObjectToken) {

    $this->curl->setHeader('Token', $ObjectToken);
    $this->curl->delete($this->Url . '/' . $ObjectID);
    $response =  $this->getResponse();
    $this->reInitCurl();
    return $response;
  }

}
