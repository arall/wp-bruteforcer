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

class Benchmark extends Command
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
            ->setName('benchmark')
            ->setDescription('Perform a benchmark')
            ->addOption(
                'url',
                null,
                InputOption::VALUE_REQUIRED,
                'Website url (http://www.wordpress.org/)'
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

        if (!$this->url = $this->checkUrl($this->url)) {
            die();
        }

        $this->bruteforcer->setUrl($this->url);

        $tries = 1000;
        $error = false;
        do {
            $this->output->writeln(' [+] Trying <comment>' . $tries . '</comment> tries per request...');

            $elapsed = $this->bruteforcer->benchmark($tries);
            if (!$elapsed) {
                $error = true;
            } else {
                $this->output->writeln(' Response time <info>' . $elapsed . ' second(s)</info>');
                $tries += 1000;
            }
        } while (!$error || $tries >= 10000);

        $this->output->writeln(' [+] Max allowed tries ~ <info>' . ($tries / 2) . '</info>');
    }

    /**
     * Load all the user input (arguments and options).
     */
    private function loadInput()
    {
        $this->url = $this->input->getOption('url');
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
}
