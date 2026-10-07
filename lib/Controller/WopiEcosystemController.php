<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Controller;

use OCA\Thinkfree\Service\ContainerService;
use OCA\Thinkfree\Service\FileService;
use OCA\Thinkfree\Util\WopiUrlBuilder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;

/**
 * Ecosystem 오퍼레이션을 구현한 컨트롤러
 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/ecosystem/checkecosystem
 */
class WopiEcosystemController extends Controller
{
    private FileService $fileService;
    private IRootFolder $rootFolder;
    private ContainerService $containerService;
    private WopiUrlBuilder $urlBuilder;

    public function __construct(
        string $appName,
        IRequest $request,
        FileService $fileService,
        IRootFolder $rootFolder,
        ContainerService $containerService,
        WopiUrlBuilder $urlBuilder
    ) {
        parent::__construct($appName, $request);
        $this->fileService = $fileService;
        $this->rootFolder = $rootFolder;
        $this->containerService = $containerService;
        $this->urlBuilder = $urlBuilder;
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getEcosystem(string $fileId): JSONResponse {
        $session = $this->fileService->resolve($this->request, $fileId);
        if ($session === null) {
            return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
        }

        [, $userId, , $expires] = $session;

        return new JSONResponse([
            // 파일 토큰과 같은 시각에 만료된다
            'Url' => $this->urlBuilder->forEcosystem($userId, $expires),
        ]);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function checkEcosystem(): JSONResponse {
        if ($this->containerService->resolveForEcosystem($this->request) === null) {
            return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse([
            'SupportsContainers' => true,
        ]);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getRootContainer(): JSONResponse {
        $session = $this->containerService->resolveForEcosystem($this->request);

        if ($session === null) {
            return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
        }

        [$userId, $expires] = $session;

        $root = $this->rootFolder->getUserFolder($userId); // 이 유저의 루트 폴더 가져오기

        return new JSONResponse([
            'ContainerPointer' => [
                // 에코시스템 토큰과 같은 시각에 만료된다
                'Url' => $this->urlBuilder->forContainer($root, $userId, $expires),
                'Name' => $root->getName(),
            ],
            'ContainerInfo' => [
                'Name' => $root->getName(),
                'UserCanCreateChildContainer' => $root->isCreatable(),
                'UserCanCreateChildFile' => $root->isCreatable(),
                'UserCanDelete' => false,   // 홈 폴더는 지울 수 없다
                'UserCanRename' => false,   // 홈 폴더는 이름을 바꿀 수 없다
            ],
        ]);
    }

}
