<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Controller;

use OCA\Thinkfree\Middleware\Wopi\WopiAuthRequired;
use OCA\Thinkfree\Middleware\Wopi\WopiContext;
use OCA\Thinkfree\Middleware\Wopi\WopiController;
use OCA\Thinkfree\Middleware\Wopi\WopiResource;
use OCA\Thinkfree\Util\WopiUrlBuilder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;

/**
 * Ecosystem 오퍼레이션을 구현한 컨트롤러
 *
 * 요청 검증은 WopiAuthMiddleware 가 액션 전에 마치고 WopiContext 에 넣어 둔다.
 * GetEcosystem 은 파일 토큰으로, 나머지는 에코시스템 토큰으로 들어온다.
 *
 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/ecosystem/checkecosystem
 */
#[WopiController]
class WopiEcosystemController extends Controller
{
    private IRootFolder $rootFolder;
    private WopiUrlBuilder $urlBuilder;
    private WopiContext $context;

    public function __construct(
        string $appName,
        IRequest $request,
        IRootFolder $rootFolder,
        WopiUrlBuilder $urlBuilder,
        WopiContext $context
    ) {
        parent::__construct($appName, $request);
        $this->rootFolder = $rootFolder;
        $this->urlBuilder = $urlBuilder;
        $this->context = $context;
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[WopiAuthRequired(WopiResource::File)]
    public function getEcosystem(string $fileId): JSONResponse {
        return new JSONResponse([
            // 파일 토큰과 같은 시각에 만료된다
            'Url' => $this->urlBuilder->forEcosystem($this->context->getUserId(), $this->context->getExpires()),
        ]);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[WopiAuthRequired(WopiResource::Ecosystem)]
    public function checkEcosystem(): JSONResponse {
        return new JSONResponse([
            'SupportsContainers' => true,
        ]);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[WopiAuthRequired(WopiResource::Ecosystem)]
    public function getRootContainer(): JSONResponse {
        $userId = $this->context->getUserId();
        $root = $this->rootFolder->getUserFolder($userId); // 이 유저의 루트 폴더 가져오기

        return new JSONResponse([
            'ContainerPointer' => [
                // 에코시스템 토큰과 같은 시각에 만료된다
                'Url' => $this->urlBuilder->forContainer($root, $userId, $this->context->getExpires()),
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
