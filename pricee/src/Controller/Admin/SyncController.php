<?php

declare(strict_types=1);

namespace PriceeIO\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PriceeIO\Service\SyncService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class SyncController extends FrameworkBundleAdminController
{
    private SyncService $syncService;

    public function __construct(
        SyncService $syncService
    ) {
        $this->syncService = $syncService;
    }

    public function syncAction(Request $request): JsonResponse
    {
        $token = (string) $request->request->get('sync_token');
        if ('' === $token || !hash_equals(\Tools::getAdminTokenLite('AdminModules'), $token)) {
            return new JsonResponse([
                'success' => false,
                'synced' => 0,
                'error' => 'Invalid token',
            ], 403);
        }

        $idShop = (int) $request->request->get('id_shop');
        $idLang = $request->request->get('id_lang');
        $categories = $request->request->all()['categories'] ?? [];
        $syncedCount = 0;
        $success = false;

        // Only a shop of the current back-office context can be synced
        $contextShopIds = array_map('intval', \Shop::getContextListShopID());
        if (!in_array($idShop, $contextShopIds, true)) {
            return new JsonResponse([
                'success' => false,
                'synced' => 0,
                'error' => 'Invalid shop',
            ], 400);
        }

        if (!empty($categories)) {
            try {
                $syncedCount = $this->syncService->sync(
                    $idShop,
                    (int) $idLang,
                    $categories
                );
                $success = true;
            } catch (\Throwable $e) {
                return new JsonResponse([
                    'success' => false,
                    'synced' => 0,
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        return new JsonResponse([
            'success' => $success,
            'synced' => $syncedCount,
        ]);
    }
}
