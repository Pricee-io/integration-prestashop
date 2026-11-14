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

    /**
     * CSRF token of the sync form, for an employee. The admin token cannot be
     * used: since PrestaShop 9 it is a Symfony CSRF token whose value changes
     * at each call, so the page's and this controller's never match. This one
     * is the same on every version, and cannot be computed without the
     * shop's secret key.
     */
    public static function token(int $idEmployee): string
    {
        return hash_hmac('sha256', 'pricee-sync|'.$idEmployee, _COOKIE_KEY_);
    }

    public function syncAction(Request $request): JsonResponse
    {
        $token = (string) $request->request->get('sync_token');
        $employee = \Context::getContext()->employee;
        $idEmployee = $employee instanceof \Employee ? (int) $employee->id : 0;
        if ('' === $token || $idEmployee <= 0 || !hash_equals(self::token($idEmployee), $token)) {
            return new JsonResponse([
                'success' => false,
                'synced' => 0,
                'error' => 'Invalid token',
            ], 403);
        }

        // Same right as the module page the sync is started from
        $employee = \Context::getContext()->employee;
        $idModule = (int) \Module::getModuleIdByName('pricee');
        if (!$employee instanceof \Employee || !\Module::getPermissionStatic($idModule, 'configure', $employee)) {
            return new JsonResponse([
                'success' => false,
                'synced' => 0,
                'error' => 'Access denied',
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
