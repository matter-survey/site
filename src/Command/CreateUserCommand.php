<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Create a new user account',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The email address for the new user')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant admin role to this user')
            ->addOption('api', null, InputOption::VALUE_NONE, 'Grant API access role to this user')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');

        // Check if user already exists
        if (null !== $this->userRepository->findByEmail($email)) {
            $io->error(sprintf('A user with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        // Prompt for password
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new Question('Enter password: ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setValidator(function (?string $value): string {
            if (null === $value || '' === trim($value)) {
                throw new \RuntimeException('Password cannot be empty.');
            }
            if (strlen($value) < 8) {
                throw new \RuntimeException('Password must be at least 8 characters.');
            }

            return $value;
        });

        $password = $helper->ask($input, $output, $question);

        // Confirm password
        $confirmQuestion = new Question('Confirm password: ');
        $confirmQuestion->setHidden(true);
        $confirmQuestion->setHiddenFallback(false);

        $confirmPassword = $helper->ask($input, $output, $confirmQuestion);

        if ($password !== $confirmPassword) {
            $io->error('Passwords do not match.');

            return Command::FAILURE;
        }

        // Build roles
        $roles = [];
        if ($input->getOption('admin')) {
            $roles[] = 'ROLE_ADMIN';
        }
        if ($input->getOption('api')) {
            $roles[] = 'ROLE_API';
        }

        // Create user
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);

        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        // Save user
        $this->userRepository->save($user, true);

        $io->success(sprintf('User "%s" created successfully!', $email));

        if (!empty($roles)) {
            $io->info(sprintf('Roles: %s', implode(', ', $roles)));
        }

        return Command::SUCCESS;
    }
}
