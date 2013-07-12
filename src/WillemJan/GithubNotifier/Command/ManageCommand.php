<?php
/**
 * This file and its content is copyright of Beeldspraak Website Creators BV - (c) Beeldspraak 2012. All rights reserved.
 * Any redistribution or reproduction of part or all of the contents in any form is prohibited.
 * You may not, except with our express written permission, distribute or commercially exploit the content.
 *
 * @author      Beeldspraak <info@beeldspraak.com>
 * @copyright   Copyright 2012, Beeldspraak Website Creators BV
 * @link        http://beeldspraak.com
 *
 */

namespace WillemJan\GithubNotifier\Command;


use Guzzle\Http\Exception\ClientErrorResponseException;
use Guzzle\Http\StaticClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Helper\TableHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WillemJan\GithubNotifier\Exception\RepositoryExistsException;
use WillemJan\GithubNotifier\Manager\RepositoryManager;

class ManageCommand extends Command
{
    /** @var  InputInterface */
    private  $input;

    /** @var  OutputInterface */
    private  $output;

    /** @var  \Pimple */
    private $container;

    protected function configure()
    {
        $this
            ->setName('github-notifier:manage')
            ->setDescription('Manage your notifications')
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform', 'list-repositories')
            ->addOption('drop', null, InputOption::VALUE_NONE, 'Drop tables before creating them')
            ->addOption('no-validate', null, InputOption::VALUE_NONE, 'Skip validation when adding repositories')
            ->addOption('file', null, InputOption::VALUE_OPTIONAL, 'Add multiple repositories from a text file (1 repo per line)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->container = $this->getApplication()->getContainer();

        $action = $input->getArgument('action');

        switch ($action) {
            case 'init':
                $this->initApplication();
                break;
            case 'list-repositories':
                $this->showList();
                break;
            case 'add-repository':
                $this->addRepository();
                break;
            case 'import-repositories':
                $this->importRepositories();
                break;
            case 'notify':
                $this->notify();
                break;
            default:
                $this->output->writeln(sprintf('<error>Invalid action: %s</error>', $action));
        }
    }

    protected function initApplication()
    {
        if (true === $this->input->getOption('drop') && 'Y' === $this->getHelper('dialog')->ask($this->output, 'Dropping tables, are you sure (Y/n)? [n]: ')) {
            $this->container['manager']->dropDatabase();
        }

        $this->container['manager']->initDatabase();
    }

    protected function showList()
    {
        $repos = $this->container['manager']->getRepositories();
        $count = count($repos);
        $this->output->writeln(sprintf('Found <info>%d</info> repositories', $count));

        if ($count > 0) {
            /** @var TableHelper $table */
            $table = $this->getHelper('table');

            $firstRow = array_shift($repos);
            $table->setHeaders(array_keys($firstRow));
            $table->addRow($firstRow);

            $table->addRows($repos);
            $table->render($this->output);
        }
    }

    protected function addRepository()
    {
        /** @var DialogHelper $dialog */
        $dialog = $this->getHelper('dialog');

        $validator = null;
        if (!$this->input->getOption('no-validate')) {
            $validator = array($this, 'validateRepository');
        }

        $repository = $dialog->askAndValidate($this->output, 'Repository (f.e. wjzijderveld/github-notifier): ', function($answer) use($validator) {
            if (!$answer || false === strpos($answer, '/')) {
                throw new \Exception('Invalid repository');
            }

            if ($validator) {
                call_user_func($validator, $answer);
            }

            return $answer;
        });

        $this->insertRepository($repository);
    }

    public function importRepositories()
    {
        /** @var DialogHelper $dialog */
        $dialog = $this->getHelper('dialog');

        $importFile = $this->input->getOption('file');
        if (!is_readable($importFile)) {
            throw new \InvalidArgumentException(sprintf('File %s does not exists or is not readable', $importFile));
        }

        $validate = !$this->input->getOption('no-validate');

        foreach (file($importFile, FILE_IGNORE_NEW_LINES) as $repository) {
            if ($validate) {
                try {
                    $this->validateRepository($repository);
                } catch (\Exception $e) {
                    $this->output->writeln(sprintf('<error>Repository %s does not exists</error>', $repository));
                    continue;
                }
            }

            $this->insertRepository($repository);
        }
    }

    public function insertRepository($repository)
    {
        try {
            $repositoryId = $this->container['manager']->insertRepository($repository);

            $response = StaticClient::get(sprintf('https://api.github.com/repos/%s/tags', $repository));
            $tags = json_decode($response->getBody(true));
            foreach ($tags as $tag) {
                $this->container['manager']->insertTag($repositoryId, $tag->name, $tag->commit->sha);
            }

            $this->output->writeln(sprintf('<info>Created repository %s with %d initial tags</info>', $repository, count($tags)));
        } catch (RepositoryExistsException $e) {
            $this->output->writeln(sprintf('<info>Repository %s already exists</info>', $repository));
        }
    }

    public function validateRepository($repository)
    {
        try {
            StaticClient::head(sprintf('https://api.github.com/repos/%s', $repository));
        } catch (ClientErrorResponseException $e) {
            if (404 === $e->getResponse()->getStatusCode()) {
                throw new \Exception(sprintf('Repository %s does not exist on Github', $repository));
            } else {
                throw new \Exception(sprintf('Unknown error while validating repository "%s", maybe github is down?', $repository));
            }
        }
    }

    public function notify()
    {
        $repos = $this->container['manager']->getRepositories();
        $tags = array();
        $allTags = $this->container['manager']->getTags();
        foreach ($allTags as $tag) {
            $tags[$tag['repository_id']][$tag['name']] = $tag;
        }

        $repoStats = array();
        foreach ($repos as $repo) {
            $currentTags = isset($tags[$repo['id']]) ? $tags[$repo['id']] : array();
            $repoStats[$repo['id']] = array(
                'repo'      => $repo,
                'inserted'  => array(),
                'updated'   => array(),
                'deleted'   => array(),
            );
            $response = StaticClient::get(sprintf('https://api.github.com/repos/%s/%s/tags', $repo['organisation'], $repo['name']));
            $remoteTags = json_decode($response->getBody(true), true);

            foreach ($remoteTags as $remoteTag) {
                if (!isset($currentTags[$remoteTag['name']])) {
                    $this->container['manager']->insertTag($repo['id'], $remoteTag['name'], $remoteTag['commit']['sha']);
                    $repoStats[$repo['id']]['inserted'][] = array('name' => $remoteTag['name'], 'hash' => $remoteTag['commit']['sha']);
                } elseif ($currentTags[$remoteTag['name']]['hash'] !== $remoteTag['commit']['sha']) {
                    $this->container['manager']->updateHash($currentTags[$remoteTag['name']]['id'], $remoteTag['commit']['sha']);
                    $repoStats[$repo['id']]['updated'][] = array('name' => $remoteTag['name'], 'old_hash' => $currentTags[$remoteTag['name']]['hash'], 'new_hash' => $remoteTag['commit']['sha']);
                }
                unset($currentTags[$remoteTag['name']]);
            }

            if (count($currentTags)) {
                $repoStats[$repo['id']]['deleted'] = $currentTags;
            }
        }

        $this->sendNotifications($repoStats);
    }

    public function sendNotifications(array $stats)
    {

        $content = '';
        foreach ($stats as $repoStats) {
            $inserted = count($repoStats['inserted']);
            $updated = count($repoStats['updated']);
            $deleted = count($repoStats['deleted']);

            if ($inserted || $updated || $deleted) {

                $content .= '<h2>' . $repoStats['repo']['organisation'].'/'.$repoStats['repo']['name'].'</h2>';
                if ($inserted) {
                    $content .= '<strong>New tags</strong>: ';
                    foreach ($repoStats['inserted'] as $tag) {
                        $content .= $tag['name'] . ', ';
                    }
                    $content .= '<br />';
                }

                if ($updated) {
                    $content .= '<strong>Updated tags</strong>: ';
                    foreach ($repoStats['updated'] as $tag) {
                        $content .= $tag['name'] . ': ' . $tag['old_hash'] . ' &gt; ' . $tag['new_hash'].', ';
                    }
                    $content .= '<br />';
                }

                if ($deleted) {
                    $content .= '<strong>Deleted tags</strong>: ';
                    foreach ($repoStats['deleted'] as $tag) {
                        $content .= $tag['name'] . ', ';
                    }
                    $content .= '<br />';
                }

                $content .= '<p>&nbsp;</p>';
            }
        }

        if ('' === $content) {
            $this->output->writeln('<info>Nothing to notify</info>');
            return;
        }

        $body = <<<HTML
Hello there!

There are one or more new tags available for your watched repositories:
{$content}

<small>This is a automated message</small>
HTML;
        ;

        $message = \Swift_Message::newInstance();
        $message
            ->setSubject('Github Notifier: There new tags available')
            ->setFrom('github@codelabs.nl', 'Codelabs.nl') // TODO: Configurable
            ->setTo($this->container['config']['email']['email'], $this->container['config']['email']['name'])
            ->setBody($body, 'text/html')
        ;

        $mailer = new \Swift_Mailer(new \Swift_MailTransport());
        $mailer->send($message);
    }
}