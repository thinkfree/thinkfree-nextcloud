<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Controller;

use OCA\Thinkfree\Service\ContainerService;
use OCA\Thinkfree\Service\FileService;
use OCA\Thinkfree\Service\WopiTokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Ecosystem 오퍼레이션을 구현한 컨트롤러
 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/ecosystem/checkecosystem
 */
class WopiEcosystemController extends Controller
{
    private FileService $fileService;
    private WopiTokenService $tokenService;
    private IUserManager $userManager;
    private IURLGenerator $urlGenerator;
    private LoggerInterface $logger;
    private IRootFolder $rootFolder;
    private ContainerService $containerService;

    public function __construct(
        string $appName,
        IRequest $request,
        FileService $fileService,
        WopiTokenService $tokenService,
        IUserManager $userManager,
        IURLGenerator $urlGenerator,
        LoggerInterface $logger,
        ContainerService $containerService
    ) {
        parent::__construct($appName, $request);
        $this->fileService = $fileService;
        $this->tokenService = $tokenService;
        $this->userManager = $userManager;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
        $this->containerService = $containerService;
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getEcosystem(string $fileId): JSONResponse {
        $session = $this->fileService->resolve($this->request, $fileId);
        if ($session === null) {
            return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
        }

        [, $userId, $canWrite] = $session;

        [$token] = $this->tokenService->issueForEcosystem($userId, $canWrite);

        return new JSONResponse([
            'Url' => $this->urlGenerator->linkToRouteAbsolute($this->appName . '.wopiEcosystem.check')
                . '?access_token=' . rawurlencode($token),
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
        $userId = $this->containerService->resolveForEcosystem($this->request);

        if ($userId === null) {
            return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
        }

        $root = $this->rootFolder->getUserFolder($userId); // 이 유저의 루트 폴더 가져오기
        $containerId = (string)$root->getId(); // 이 폴더의 id를 containerId로 사용

        [$token] = $this->tokenService->issue($userId, $containerId, false);

        $url = $this->urlGenerator->linkToRouteAbsolute(
                $this->appName . '.wopiContainer.checkContainerInfo',
                ['containerId' => $containerId]
            ) . '?access_token=' . rawurlencode($token);

        return new JSONResponse([
            'ContainerPointer' => [
                'Url' => $url,
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
