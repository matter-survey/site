<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:api-token:create',
    description: 'Create a new API token for a user',
)]
class CreateApiTokenCommand extends Command
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
            ->addArgument('name', InputArgument::REQUIRED, 'A name/description for this token (e.g., "Home Assistant")')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'Token expiration in days (e.g., 30, 90, 365)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        $name = $input->getArgument('name');
        $expiresDays = $input->getOption('expires');

        // Find user
        $user = $this->userRepository->findByEmail($email);
        if (null === $user) {
            $io->error(sprintf('User with email "%s" not found.', $email));

            return Command::FAILURE;
        }

        // Calculate expiration if provided
        $expiresAt = null;
        if (null !== $expiresDays) {
            $days = (int) $expiresDays;
            if ($days <= 0) {
                $io->error('Expiration days must be a positive number.');

                return Command::FAILURE;
            }
            $expiresAt = new \DateTimeImmutable(sprintf('+%d days', $days));
        }

        // Create token
        $apiToken = $this->apiTokenRepository->createForUser($user, $name, $expiresAt);

        $io->success('API token created successfully!');
        $io->newLine();

        // Display the token prominently - this is the only time it will be shown in full
        $io->block($apiToken->getToken(), 'TOKEN', 'fg=black;bg=green', ' ', true);

        $io->warning('Save this token now. It will not be shown again in full.');
        $io->newLine();

        $io->table(
            ['Property', 'Value'],
            [
                ['User', $user->getEmail()],
                ['Name', $name],
                ['Created', $apiToken->getCreatedAt()->format('Y-m-d H:i:s')],
                ['Expires', $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : 'Never'],
            ]
        );

        $io->info('Usage: curl -H "Authorization: Bearer '.$apiToken->getToken().'" https://your-site.com/api/...');

        return Command::SUCCESS;
    }
}
