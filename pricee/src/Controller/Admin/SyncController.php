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
        $categories = array_map('intval', (array) ($request->request->all()['categories'] ?? []));
        // Batch cursor: the sync goes on after the last product of the previous batch
        $afterId = max(0, (int) $request->request->get('after_id'));

        // Only a shop of the current back-office context can be synced
        $contextShopIds = array_map('intval', \Shop::getContextListShopID());
        if (!in_array($idShop, $contextShopIds, true)) {
            return new JsonResponse([
                'success' => false,
                'synced' => 0,
                'error' => 'Invalid shop',
            ], 400);
        }

        // Only categories offered for this shop can be synced, so a category
        // of another shop's tree never selects products of the wrong scope
        if ([] !== array_diff($categories, SyncService::shopCategoryIds($idShop))) {
            return new JsonResponse([
                'success' => false,
                'synced' => 0,
                'error' => 'Invalid categories',
            ], 400);
        }

        if ([] === $categories) {
            return new JsonResponse([
                'success' => false,
                'synced' => 0,
                'error' => 'No category selected',
            ], 400);
        }

        // One batch per request, so a large selection never hits a timeout
        @set_time_limit(120);

        try {
            $result = $this->syncService->sync($idShop, $categories, $afterId);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'synced' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }

        return new JsonResponse([
            'success' => true,
            'synced' => $result['synced'],
            'failed' => $result['failed'],
            'last_id' => $result['lastId'],
            'done' => $result['done'],
            'total' => $result['total'],
        ]);
    }
}
