<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\ApiTokenRepository;
use App\Repository\DeviceRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    public function __construct(
        private DeviceRepository $deviceRepository,
        private UserRepository $userRepository,
        private ApiTokenRepository $apiTokenRepository,
    ) {
    }

    #[Route('', name: 'admin_dashboard')]
    public function index(): Response
    {
        // Get some basic statistics
        $stats = [
            'total_devices' => $this->deviceRepository->countDevices(),
            'total_vendors' => $this->deviceRepository->countVendors(),
            'total_users' => count($this->userRepository->findAll()),
            'total_api_tokens' => count($this->apiTokenRepository->findAll()),
        ];

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // Get current user's API tokens
        $userTokens = $this->apiTokenRepository->findByUser($user);

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $stats,
            'user_tokens' => $userTokens,
        ]);
    }
}
