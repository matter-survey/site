<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DeviceScoreService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuild the device scores cache table for faster list page queries.
 */
#[AsCommand(
    name: 'app:scores:rebuild',
    description: 'Rebuild the device scores cache table',
)]
class RebuildDeviceScoresCommand extends Command
{
    public function __construct(
        private readonly DeviceScoreService $deviceScoreService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('device', 'd', InputOption::VALUE_REQUIRED, 'Rebuild score for a specific device ID only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deviceId = $input->getOption('device');

        if (null !== $deviceId) {
            $io->info(\sprintf('Rebuilding score for device ID: %s', $deviceId));
            $this->deviceScoreService->updateDeviceScoreCache((int) $deviceId);
            $io->success('Score rebuilt successfully.');

            return Command::SUCCESS;
        }

        $io->info('Rebuilding scores for all devices...');
        $count = $this->deviceScoreService->rebuildScoreCache();
        $io->success(\sprintf('Rebuilt scores for %d devices.', $count));

        return Command::SUCCESS;
    }
}
