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

namespace WillemJan\GithubNotifier\Manager;

use Psr\Log\LoggerInterface;
use WillemJan\GithubNotifier\Exception\RepositoryExistsException;

class RepositoryManager
{
    /** @var \PDO  */
    private $pdo;

    /** @var \Psr\Log\LoggerInterface  */
    private $logger;

    public function __construct(\PDO $pdo, LoggerInterface $logger)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    public function getRepositories()
    {
        $result = $this->pdo->query("SELECT r.*, COUNT(t.id) as tags FROM repositories r LEFT JOIN repository_tags t ON t.repository_id = r.id GROUP BY r.id");

        return $result->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getTags()
    {
        $result = $this->pdo->query("SELECT * FROM repository_tags");

        return $result->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function insertRepository($repository)
    {
        list($organisation, $name) = explode('/', $repository);

        try {
            $statement = $this->pdo->prepare("INSERT INTO repositories (organisation, name) VALUES (?, ?)");
            $statement->bindValue(1, $organisation);
            $statement->bindValue(2, $name);

            $statement->execute();

            return $this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            if (19 === $e->errorInfo[1]) {
                throw new RepositoryExistsException();
            }
            $this->logger->error('Error while inserting repository', array($e->getMessage(), $e->getCode()) + $e->errorInfo);
            throw new \Exception('Error while inserting reposutory');
        }
    }

    public function insertTag($repositoryId, $name, $hash)
    {
        try {
            $statement = $this->pdo->prepare("INSERT INTO repository_tags (repository_id, name, hash) VALUES(?, ?, ?)");
            $statement->bindValue(1, $repositoryId);
            $statement->bindValue(2, $name);
            $statement->bindValue(3, $hash);

            $statement->execute();
        } catch (\PDOException $e) {
            $this->logger->error('Error while inserting repository tag', array($e->getMessage(), $e->getCode()));
            throw new \Exception('Error while inserting reposutory tag');
        }
    }

    public function updateHash($tagId, $hash)
    {
        try {
            $statement = $this->pdo->prepare("UPDATE repository_tags SET hash = ? WHERE id = ?");
            $statement->bindValue(1, $hash);
            $statement->bindValue(2, $tagId);

            $statement->execute();
        } catch (\PDOException $e) {
            $this->logger->error('Error while updating hash for tag', array($e->getMessage(), $e->getCode()));
            throw new \Exception('Error while updating hash for tag');
        }
    }

    public function dropDatabase()
    {
        $this->pdo->query("DROP TABLE IF EXISTS repositories;");
        $this->pdo->query("DROP TABLE IF EXISTS repository_tags;");
    }

    public function initDatabase()
    {
        $this->pdo->query("CREATE TABLE IF NOT EXISTS repositories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            organisation VARCHAR(128) NOT NULL,
            name VARCHAR(255) NOT NULL,
            notifications TEXT,
            created DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_checked DATETIME DEFAULT NULL,
            UNIQUE (organisation, name)
        );");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS repository_tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            repository_id INTEGER NOT NULL,
            name VARCHAR(128) NOT NULL,
            hash VARCHAR(40) NOT NULL,
            deleted TINYINT DEFAULT 0,
            created DATETIME DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT tag_repository FOREIGN KEY (repository_id) REFERENCES repositories (id),
            UNIQUE (repository_id, name)
        );");
    }
}