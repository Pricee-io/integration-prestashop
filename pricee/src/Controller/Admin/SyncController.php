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
        $websiteUrl = $request->getSchemeAndHttpHost();
        $idLang = $request->request->get('id_lang');
        $categories = $request->request->all('categories');
        $syncedCount = 0;
        $success = false;

        if (!empty($categories)) {
            $syncedCount = $this->syncService->sync(
                $websiteUrl,
                (int) $idLang,
                $categories
            );
            $success = true;
        }

        return new JsonResponse([
            'success' => $success,
            'synced' => $syncedCount,
        ]);
    }
}
