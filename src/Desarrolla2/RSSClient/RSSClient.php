<?php

/**
 * This file is part of the RSSClient proyect.
 * 
 * Copyright (c)
 * Daniel González <daniel.gonzalez@freelancemadrid.es> 
 * 
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.
 */

namespace Desarrolla2\RSSClient;

use Desarrolla2\RSSClient\RSSNode;
use Desarrolla2\RSSClient\RSSClientInterface;
use Desarrolla2\RSSClient\HTTPClient\HTTPClient;
use Desarrolla2\RSSClient\HTTPClient\HTTPClientInterface;
use Desarrolla2\RSSClient\Sanitizer\Sanitizer;
use Desarrolla2\RSSClient\Sanitizer\SanitizerInterface;

/**
 * 
 * Description of Client
 *
 * @author : Daniel González <daniel.gonzalez@freelancemadrid.es> 
 * @file : Client.php , UTF-8
 * @date : Oct 3, 2012 , 2:07:02 AM
 */
class RSSClient implements RSSClientInterface {

    /**
     *
     * @var \Desarrolla2\RSSClient\Sanitizer\SanitizerInterface;
     */
    protected $sanitizer;

    /**
     *
     * @var \Guzzle\Http\ClientInterface 
     */
    protected $httpClient;

    /**
     * @var array 
     */
    protected $errors = array();

    /**
     * Constructor
     * 
     * @param \Desarrolla2\RSSClient\Sanitizer\SanitizerInterface $sanitizer
     * @param string $channel
     * @param array $feeds
     */
    public function __construct($feeds = array(), $channel = 'default') {
        $this->httpClient = new HTTPClient();
        $this->sanitizer = new Sanitizer();

        if (is_array($feeds)) {
            $this->setFeeds($feeds, $channel);
        }
    }

    /**
     * Retrieve the number of nodes from a chanel
     * 
     * @param string $channel
     * @return int count $nodes
     */
    public function countNodes($channel = 'default') {
        if (!isset($this->nodes[$channel])) {
            $this->nodes[$channel] = array();
        }
        return count($this->nodes[$channel]);
    }

    /**
     * Retrieve nodes from a chanel
     * 
     * @param int $limit
     * @param string $channel
     * @return array $nodes
     * @throws \InvalidArgumentException
     */
    public function fetch($channel = 'default', $limit = 20) {
        if (!is_string($channel)) {
            throw new \InvalidArgumentException('channel not valid (' . gettype($channel) . ')');
        }
        if (!isset($this->feeds[$channel])) {
            throw new \InvalidArgumentException('channel not valid (' . $channel . ')');
        }
        $limit = (int) $limit;
        if (!$limit) {
            throw new \InvalidArgumentException('limit not valid (' . $limit . ')');
        }
        foreach ($this->feeds[$channel] as $feed) {
            $feed = $this->fetchHTTP($feed);
            if ($feed) {
                $DOMDocument = new \DOMDocument();
                $DOMDocument->strictErrorChecking = false;
                try {
                    if ($DOMDocument->loadXML($feed)) {
                        $nodes = $DOMDocument->getElementsByTagName('item');
                        foreach ($nodes as $node) {
                            $this->parseDOMNode($node, $channel);
                        }
                    }
                } catch (Exception $e) {
                    $this->addError($e->getMessage());
                }
            }
        }
        $this->sort($channel);

        return $this->getNodes($channel, $limit);
    }

  

    /**
     * Retrieve errors stack
     * 
     * @return array
     */
    public function getErrors() {
        return $this->errors;
    }

    /**
     * Retrieve if any errors ocurred
     * @return boolean
     */
    public function hasErrors() {
        return count($this->errors) ? true : false;
    }

 

    /**
     * set HTTPClient
     * 
     * @param \Desarrolla2\RSSClient\HTTPClient\HTTPClientInterface $client
     * @return type
     */
    public function setHTTPClient(HTTPClientInterface $client) {
        $this->httpClient = $client;
    }

    /**
     * Set Sanitizer
     * 
     * @param \Desarrolla2\RSSClient\Sanitizer\SanitizerInterface $sanitizer
     */
    public function setSanitizer(SanitizerInterface $sanitizer) {
        $this->sanitizer = $sanitizer;
    }

    /**
     * Add Error to stack
     * 
     * @param string $message
     */
    protected function addError($message) {
        $message = (string) $message;
        array_push($this->errors, $message);
    }

    /**
     * @param \DOMElement $node
     * @param string $channel
     */
    protected function parseDOMNode(\DOMElement $DOMnode, $channel) {
        $node = array();
        $properties = array('title', 'link', 'description', 'author',
            'category', 'comments', 'enclosure', 'guid',
            'pubDate', 'source');
        foreach ($properties as $property) {
            $node[$property] = $this->getNodeProperty($DOMnode, $property);
        }
        foreach ($node as $key => $value) {
            if ($key == 'category') {
                $node['categories'][] = $this->doClean($value);
                continue;
            }
            $node[$key] = $this->doClean($value);
        }
        $this->addNode(
                new RSSNode($node), $channel
        );
    }

    /**
     * Add node
     * 
     * @param RSSNode $node
     * @param string $channel
     */
    protected function addNode(RSSNode $node, $channel = 'default') {
        if (!isset($this->nodes[$channel])) {
            $this->nodes[$channel] = array();
        }
        if (!is_array($this->nodes[$channel])) {
            $this->nodes[$channel] = array();
        }
        array_push($this->nodes[$channel], $node);
    }

    /**
     * 
     * @param string $text
     * @return string
     */
    protected function doClean($text) {
        return $this->sanitizer->doClean($text);
    }

    /**
     * 
     * @param \DOMElement $DOMnode
     * @param string $propertyName
     * @return string
     */
    protected function getNodeProperty(\DOMElement $DOMnode, $propertyName) {
        try {
            $propertyValue = $DOMnode->getElementsByTagName($propertyName)->item(0);
            if ($propertyValue) {
                return $propertyValue->nodeValue;
            }
        } catch (Exception $e) {
            $this->addError($e->getMessage());
        }
        return '';
    }

    /**
     * Retrieves a $limit number of nodes
     * 
     * @param int $limit
     * @param string $channel
     * @return array $nodes
     */
    protected function getNodes($channel = 'default', $limit = 20) {
        if (!is_string($channel)) {
            throw new \Exception('channel not valid (' . gettype($channel) . ')');
        }
        if (in_array($channel, $this->feeds)) {
            throw new \Exception('channel not valid (' . $channel . ')');
        }
        if (!is_integer($limit)) {
            throw new \Exception('limit not valid (' . gettype($limit) . ')');
        }
        if (is_array($this->nodes[$channel])) {
            if (count($this->nodes[$channel])) {
                $response = array();
                for ($i = 0; $i < $limit; $i++) {
                    if (isset($this->nodes[$channel][$i])) {
                        array_push($response, $this->nodes[$channel][$i]);
                    }
                }
                return $response;
            }
        }
        $this->addError('Not nodes found in ' . $channel);

        return false;
    }

    /**
     * 
     * @param string $feedUrl
     * @return string
     */
    protected function fetchHTTP($feedUrl) {
        try {
            return $this->httpClient->get($feedUrl);
        } catch (Exception $e) {
            $this->addError($e->getMessage());
        }
        return '';
    }

    /**
     * Sort by buuble method
     * 
     * @param string $channel
     */
    protected function sort($channel = 'default') {
        $countNodes = $this->countNodes($channel);
        for ($i = 1; $i < $countNodes; $i++) {
            for ($j = 0; $j < $countNodes - $i; $j++) {
                if ($this->nodes[$channel][$j]->getTimestamp() < $this->nodes[$channel][$j + 1]->getTimestamp()) {
                    $k = $this->nodes[$channel][$j + 1];
                    $this->nodes[$channel][$j + 1] = $this->nodes[$channel][$j];
                    $this->nodes[$channel][$j] = $k;
                }
            }
        }
    }

}
