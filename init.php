<?php

require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Style\SymfonyStyle;

const VALIDATOR_URL = 'https://validator.prestashop.com';

(new SingleCommandApplication())
    ->setName('ValidatorDecider') // Optional
    ->setVersion('1.0.0') // Optional
    ->addArgument('github_link', InputArgument::OPTIONAL, 'The github_link')
    ->addArgument('github_branch', InputArgument::OPTIONAL, 'The github_branch')
    ->addArgument('archive', InputArgument::OPTIONAL, 'The archive of the module')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $io = new SymfonyStyle($input, $output);
        $client = new Client([
            'base_uri' => VALIDATOR_URL
        ]);

        $apiKey = getenv('VALIDATOR_API_KEY');

        if (empty($apiKey)) {
            throw new Exception('No API Key is set to authenticate the request to the validator. Please set the env var VALIDATOR_API_KEY');
        }

        $multipart = [
            [
                'name' => 'key',
                'contents' => $apiKey
            ]
        ];

        // Call validator
        try {
            if ($input->hasArgument('archive')) {
                $archive = $input->getArgument('archive');

                if (empty($archive) || !file_exists($archive) || !is_readable($archive)) {
                    throw new Exception(sprintf('File %s was not found, or cannot be read', $archive));
                }

                $multipart[] = [
                    'name' => 'archive',
                    'contents' => fopen($archive, 'r'),
                ];
            } else {
                $multipart[] = [
                    'name' => 'github_link',
                    'contents' => $input->getArgument('github_link'),
                ];
                $multipart[] = [
                    'name' => 'github_branch',
                    'contents' => $input->getArgument('github_branch'),
                ];
            }

            $response = $client->post('/api/modules', [
                'multipart' => $multipart
            ]);

            $stdResponse = json_decode($response->getBody()->getContents(), true);
        } catch (Throwable $th) {
            $io->writeln($th->getMessage());
            return Command::FAILURE;
        }

        // Format response
        $io->title('Validator Results');
        $isValid = true;

        foreach ($stdResponse as $category => $reports) {
            switch ($category) {
                case 'Details':
                    // do nothing
                    break;

                case 'Security':
                    $count = 0;
                    $table = new Table($output);
                    $table->setHeaders(['File', 'Error']);

                    foreach ($reports as $item) {
                        foreach ($item as $rule) {
                            if (is_array($rule)) {
                                foreach ($rule as $error) {
                                    foreach ($error as $errorDataStructure) {
                                        if (is_array($errorDataStructure)) {
                                            foreach ($errorDataStructure as $filesErrors) {
                                                $table->addRows([
                                                    [$filesErrors['file'] . ':' . $filesErrors['line'], $filesErrors['message']],
                                                ]);
                                                $count++;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }

                    if ($count >= 1) {
                        $io->error("$category");
                        $table->setStyle('box');
                        $table->render();
                    }
                    break;

                case 'Structure':
                    $count = 0;
                    $table = new Table($output);
                    foreach ($reports as $key => $item) {
                        $table->addRow([$key]);
                        $count++;
                    }

                    if ($count >= 1) {
                        $io->error("$category");
                        $table->setStyle('box');
                        $table->render();
                    }
                    break;

                default:
                    $count = 0;
                    $table = new Table($output);
                    $table->setHeaders(['File', 'Error']);
                    if (is_array($reports)) {
                        foreach ($reports as $key => $item) {
                            foreach ($item as $rule) {
                                if (is_array($rule)) {
                                    foreach ($rule as $errors) {
                                        foreach ($errors['content'] as $error) {
                                            $table->addRows([
                                                [$error['file'] . ':' . $error['line'], $error['message']],
                                            ]);
                                            $count++;

                                            if (false !== strpos($error['message'], 'should be removed')
                                                || false !== strpos($error['message'], 'index.php file not found')
                                            ) {
                                                $isValid = false;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }

                    if ($count >= 1) {
                        $io->error("$category");
                        $table->setStyle('box');
                        $table->render();
                    }
                    break;
            }
        }

        // TODO: use symfony/filesystem
        if (true === $isValid) {
            return Command::SUCCESS;
        } else {
            return Command::FAILURE;
        }
    })
    ->run();