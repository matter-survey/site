<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:api-token:list',
    description: 'List API tokens for a user',
)]
class ListApiTokensCommand extends Command
{
    public function __construct(
        private UserRepository $userRepository,
        private ApiTokenRepository $apiTokenRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The email address of the user')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');

        // Find user
        $user = $this->userRepository->findByEmail($email);
        if (null === $user) {
            $io->error(sprintf('User with email "%s" not found.', $email));

            return Command::FAILURE;
        }

        // Get tokens
        $tokens = $this->apiTokenRepository->findByUser($user);

        if (empty($tokens)) {
            $io->info(sprintf('No API tokens found for user "%s".', $email));

            return Command::SUCCESS;
        }

        $io->title(sprintf('API Tokens for %s', $email));

        $rows = [];
        foreach ($tokens as $token) {
            $status = $token->isExpired() ? '<fg=red>Expired</>' : '<fg=green>Active</>';
            $rows[] = [
                $token->getId(),
                $token->getName(),
                substr($token->getToken(), 0, 12).'...',
                $token->getCreatedAt()->format('Y-m-d H:i'),
                $token->getLastUsedAt() ? $token->getLastUsedAt()->format('Y-m-d H:i') : 'Never',
                $token->getExpiresAt() ? $token->getExpiresAt()->format('Y-m-d H:i') : 'Never',
                $status,
            ];
        }

        $io->table(
            ['ID', 'Name', 'Token (partial)', 'Created', 'Last Used', 'Expires', 'Status'],
            $rows
        );

        return Command::SUCCESS;
    }
}
