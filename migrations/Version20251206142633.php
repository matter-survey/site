<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add users and api_tokens tables for authentication.
 */
final class Version20251206142633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users and api_tokens tables for authentication';
    }

    public function up(Schema $schema): void
    {
        // Create users table
        $this->addSql('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            email VARCHAR(180) NOT NULL,
            roles CLOB NOT NULL,
            password VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            last_login_at DATETIME DEFAULT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON users (email)');

        // Create api_tokens table
        $this->addSql('CREATE TABLE api_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            user_id INTEGER NOT NULL,
            token VARCHAR(68) NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            CONSTRAINT FK_2CAD560EA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE INDEX IDX_2CAD560EA76ED395 ON api_tokens (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_TOKEN ON api_tokens (token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_tokens');
        $this->addSql('DROP TABLE users');
    }
}
