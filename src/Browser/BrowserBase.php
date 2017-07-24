<?php

namespace Zumba\GastonJS\Browser;

use Zumba\GastonJS\Exception\BrowserError;
use Zumba\GastonJS\Exception\DeadClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;

/**
 * Class BrowserBase
 * @package Zumba\GastonJS\Browser
 */
class BrowserBase {
  /** @var mixed */
  protected $logger;
  /** @var  bool */
  protected $debug;
  /** @var  string */
  protected $phantomJSHost;
  /** @var  int */
  protected $clientTimeout;
  /** @var  Client */
  protected $apiClient;

  /**
   *  Creates an http client to consume the phantomjs API
   */
  protected function createApiClient() {
    // Provide a BC switch between guzzle 5 and guzzle 6.
    if (class_exists('GuzzleHttp\Psr7\Response')) {
      $options = array(
        "base_uri" => $this->getPhantomJSHost(),
        "timeout" => $this->getClientTimeout(),
      );
      $this->apiClient = new Client($options);
    }
    else {
      $options = array(
        "base_url" => $this->getPhantomJSHost(),
        "timeout" => $this->getClientTimeout(),
      );
      $this->apiClient = new Client($options);
    }
  }

  /**
   * TODO: not sure how to do the normalizeKeys stuff fix when needed
   * @param $keys
   * @return mixed
   */
  protected function normalizeKeys($keys) {
    return $keys;
  }

  /**
   * @return Client
   */
  public function getApiClient() {
    return $this->apiClient;
  }

  /**
   * @return string
   */
  public function getPhantomJSHost() {
    return $this->phantomJSHost;
  }

  /**
   * @return string
   */
  public function getClientTimeout() {
    return $this->clientTimeout;
  }

  /**
   * @return mixed
   */
  public function getLogger() {
    return $this->logger;
  }

  /**
   * Restarts the browser
   */
  public function restart() {
    //TODO: Do we really need to do this?, we are just a client
  }

  /**
   * Sends a command to the browser
   * @throws BrowserError
   * @throws \Exception
   * @return mixed
   */
  public function command() {
    $max_attempts = 5;
    $wait_seconds = 10;

    for ($i = 1; $i <= $max_attempts; $i++) {
      try {
        $args = func_get_args();
        $commandName = $args[0];
        array_shift($args);
        $messageToSend = json_encode(array('name' => $commandName, 'args' => $args));
        /** @var $commandResponse \GuzzleHttp\Psr7\Response|\GuzzleHttp\Message\Response */
        $commandResponse = $this->getApiClient()->post("/api", array("body" => $messageToSend));
        $jsonResponse = json_decode($commandResponse->getBody(), TRUE);
        // Request completed well, no more attempts needed.
        break;
      }
      catch (ServerException $e) {
        $jsonResponse = json_decode($e->getResponse()->getBody()->getContents(), true);

        if ($i == $max_attempts) {
          if (isset($jsonResponse['error'])) {
            throw $this->getErrorClass($jsonResponse);
          }

          if (empty($jsonResponse) || (isset($jsonResponse['response']) && empty($jsonResponse['response']))) {
            throw new \Exception("GastonJS command request response is empty.");
          }
        }
        else {
          // Wait some seconds for the next attempt.
          sleep($wait_seconds);
        }
      }
      catch (Exception $e) {
        if ($i == $max_attempts) {
          $error = $e->getMessage();
          throw new \Exception("GastonJS command request failed: $error.");
        }
        else {
          // Wait some seconds for the next attempt.
          sleep($wait_seconds);
        }
      }
    }

    return $jsonResponse['response'];
  }

  /**
   * @param $error
   * @return BrowserError
   */
  protected function getErrorClass($error) {
    $errorClassMap = array(
      'Poltergeist.JavascriptError'   => "Zumba\\GastonJS\\Exception\\JavascriptError",
      'Poltergeist.FrameNotFound'     => "Zumba\\GastonJS\\Exception\\FrameNotFound",
      'Poltergeist.InvalidSelector'   => "Zumba\\GastonJS\\Exception\\InvalidSelector",
      'Poltergeist.StatusFailError'   => "Zumba\\GastonJS\\Exception\\StatusFailError",
      'Poltergeist.NoSuchWindowError' => "Zumba\\GastonJS\\Exception\\NoSuchWindowError",
      'Poltergeist.ObsoleteNode'      => "Zumba\\GastonJS\\Exception\\ObsoleteNode"
    );
    if (isset($error['error']['name']) && isset($errorClassMap[$error["error"]["name"]])) {
      return new $errorClassMap[$error["error"]["name"]]($error);
    }

    return new BrowserError($error);
  }
}
