<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ApiTokenRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:api-token:revoke',
    description: 'Revoke (delete) an API token',
)]
class RevokeApiTokenCommand extends Command
{
    public function __construct(
        private ApiTokenRepository $apiTokenRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'The ID of the token to revoke')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $id = (int) $input->getArgument('id');

        // Find token
        $token = $this->apiTokenRepository->find($id);
        if (null === $token) {
            $io->error(sprintf('Token with ID "%d" not found.', $id));

            return Command::FAILURE;
        }

        $tokenName = $token->getName();
        $userEmail = $token->getUser()?->getEmail() ?? 'Unknown';

        // Confirm deletion
        if (!$io->confirm(sprintf('Are you sure you want to revoke token "%s" (ID: %d) belonging to %s?', $tokenName, $id, $userEmail), false)) {
            $io->info('Operation cancelled.');

            return Command::SUCCESS;
        }

        // Delete token
        $this->apiTokenRepository->remove($token, true);

        $io->success(sprintf('Token "%s" (ID: %d) has been revoked.', $tokenName, $id));

        return Command::SUCCESS;
    }
}
