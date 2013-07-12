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

namespace WillemJan;


use Monolog\Handler\StreamHandler;
use Symfony\Component\Console\Application;
use Symfony\Component\Yaml\Yaml;
use WillemJan\GithubNotifier\Manager\RepositoryManager;

class GithubNotifier extends Application
{
    /** @var array */
    private $config;

    /** @var \PDO */
    private $pdo;

    /** @var \Pimple */
    private $container;

    /**
     * Constructor.
     *
     * @param string $name    The name of the application
     * @param string $version The version of the application
     *
     * @api
     */
    public function __construct($name = 'UNKNOWN', $version = 'UNKNOWN')
    {
        $this->init();
        parent::__construct($name, $version);
    }

    /**
     * @return \Pimple
     */
    public function getContainer()
    {
        return $this->container;
    }

    public function init()
    {
        $this->container = new \Pimple();
        $this->container['config'] = $this->getConfig();
        $this->container['pdo'] = $this->container->share(function($c) {
            switch ($c['config']['database']['pdo_driver']) {
                case 'sqlite':
                    $dsn = 'sqlite:' . __DIR__ . '/../../' . $c['config']['database']['db_path'];
                    break;
                default:
                    throw new \InvalidArgumentException(sprintf('Invalid pdo_driver %s configured', $this->config['database']['pdo_driver']));
            }

            $pdo = new \PDO($dsn);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            return $pdo;
        });
        $this->container['manager'] = $this->container->share(function($c) {
            return new RepositoryManager($c['pdo'], $c['logger']);
        });
        $this->container['logger'] = $this->container->share(function() {

            $logger = new \Monolog\Logger('system');
            $logger->pushHandler(new StreamHandler(__DIR__ . '/../../logs/system.log'));

            return $logger;
        });
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        $configFile = __DIR__ . '/../../app/config.yml';
        if (!is_readable($configFile)) {
            throw new \Exception('Config file does not exists in app folder');
        }

        return Yaml::parse(file_get_contents($configFile));
    }
}