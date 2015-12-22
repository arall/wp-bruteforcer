<?php

namespace Arall\WPBruteforcer\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Arall\WPBruteforcer\WPBruteforcer;
use Symfony\Component\Console\Helper\Table;
use DateTime;
use \Curl\Curl;

class Enumerate extends Command
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
     * Usernames to bruteforce (loaded ones).
     *
     * @var array
     */
    private $users;

    /**
     * Max nº of users to enumerate.
     *
     * @var int
     */
    private $limit;

    /**
     * Max failures.
     *
     * @var int
     */
    private $fails;

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
            ->setName('enumerate')
            ->setDescription('Enumerate WordPress authors')
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
               'Websites list path (to enumerate multiple targets)'
            )
            ->addOption(
               'limit',
               null,
               InputOption::VALUE_REQUIRED,
               'Limit (unlimited as default)'
            )
            ->addOption(
               'fails',
               null,
               InputOption::VALUE_REQUIRED,
               'Max failures before giving up (default: 5)'
            )
            ->addOption(
               'output',
               null,
               InputOption::VALUE_REQUIRED,
               'Save the obtained authors to a txt file'
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

        if (!$this->loadTargets()) {
            return false;
        }

        foreach ($this->urls as $url) {
            $url = trim($url);

            if (!$url = $this->checkUrl($url)) {
                continue;
            }

            $this->bruteforcer->setUrl($url);

            // Output current target
            $this->output->writeln(' [+] Target: <comment>' . $url . '</comment>');

            $this->enumerate();
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
        $this->limit = $this->input->getOption('limit');
        $this->fails = $this->input->getOption('fails') ?: 5;
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
     * Enumerate the website's authors.
     * Output the obtained info.
     * Saves the authors into the output data.
     */
    private function enumerate()
    {
        $users = $this->bruteforcer->enumerate($this->limit, $this->fails);
        if (!empty($users)) {
            $this->output->writeln(' [i] <comment>' . count($users) . '</comment> user(s) found');
            $table = new Table($this->output);
            $table->setHeaders(array('Id', 'Login', 'Name'))->setRows($users);
            $table->render();
        } else {
            $this->output->writeln('<error> [!] No users found</error>');
        }
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
            $this->output->writeln(' [+] Authors saved in <comment>' . $this->output_path . '</comment>');
            $this->output->writeln('');

            return true;
        }

        return false;
    }
}
