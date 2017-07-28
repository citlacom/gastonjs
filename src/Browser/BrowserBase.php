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
    $max_attempts = 3;
    $wait_time = 2;
    $args = func_get_args();
    $commandName = $args[0];
    array_shift($args);
    $messageToSend = json_encode(array('name' => $commandName, 'args' => $args));

    for ($i = 1; $i <= $max_attempts; $i++) {
      $status = FALSE;
      $jsonResponse = FALSE;

      try {
        /** @var $commandResponse \GuzzleHttp\Psr7\Response|\GuzzleHttp\Message\Response */
        $commandResponse = $this->getApiClient()->post("/api", array("body" => $messageToSend));
        $status = $commandResponse->getStatusCode();

        // If response was sucessful, no more attempts needed.
        if ($status === 200) {
          $body = $commandResponse->getBody();
          $jsonResponse = json_decode($body, TRUE);
          $response_keys = array_keys($jsonResponse);
          if ($response_keys && in_array('response', $response_keys)) {
            break;
          }
        }
      } catch (ServerException $e) {
        $jsonResponse = json_decode($e->getResponse()->getBody()->getContents(), true);
        // In case that element was obsolete is possible that previous attempt
        // was sucessful and we are at a new page, let's keep going.
        if (isset($jsonResponse['error']['name']) && $jsonResponse['error']['name'] == 'Poltergeist.ObsoleteNode') {
          echo "Error Poltergeist.ObsoleteNode, trying a reload.\n";
          $this->reload();
          $jsonResponse = FALSE;
          break;
        }
      } catch (ConnectException $e) {
        if ($i == $max_attempts) {
          $exception = new DeadClient($e->getMessage(), $e->getCode(), $e);
        }
      } catch (\Exception $e) {
        if ($i == $max_attempts) {
          $exception = $e;
        }
      }

      echo sprintf("GastonJS request retry: #%d:\n%s\n", $i, $messageToSend);

      if ($status) {
        echo sprintf("Status: %s\n", $status);
      }

      if ($jsonResponse) {
        echo sprintf("Response: %s\n", print_r($jsonResponse, 1));
      }

      if (isset($e)) {
        $error = $e->getMessage();
        echo sprintf("Error: %s\n", $error);
      }

      // Enable the disabled input elements to allow retry press / submit actions.
      if ($commandName == 'click') {
        $js = "var inputs = document.getElementsByTagName('input');
          for (var i = 0; i < inputs.length; i++) { inputs[i].disabled = false; }";
        $this->execute($js);
        echo "Executed script to enable any disabled input element.\n";
      }

      if ($i < $max_attempts) {
        echo sprintf("Wait %d seconds to try again.\n", $wait_time);
        sleep($wait_time);
      }
    }

    if (isset($jsonResponse['error'])) {
      throw $this->getErrorClass($jsonResponse);
    }

    if (isset($exception)) {
      throw $exception;
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
