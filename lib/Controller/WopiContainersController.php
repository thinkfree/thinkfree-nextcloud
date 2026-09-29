<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Controller;

use OCA\Thinkfree\Service\ContainerService;
use OCA\Thinkfree\Service\FileService;
use OCA\Thinkfree\Util\NewFileResponder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Container 오퍼레이션을 구현한 컨트롤러
 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/containers/checkcontainerinfo
 */
class WopiContainersController extends Controller
{
    private FileService $fileService;
    private LoggerInterface $logger;
    private ContainerService $containerService;
    private NewFileResponder $responder;

    public function __construct(
        string           $appName,
        IRequest         $request,
        FileService      $fileService,
        LoggerInterface  $logger,
        ContainerService $containerService,
        NewFileResponder $responder
    )
    {
        parent::__construct($appName, $request);
        $this->fileService = $fileService;
        $this->logger = $logger;
        $this->containerService = $containerService;
        $this->responder = $responder;
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function checkContainerInfo(string $containerId): JSONResponse
    {
        $session = $this->containerService->resolveContainer($this->request, $containerId);

        if ($session === null) {
            return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
        }

        [$folder,] = $session;

        return new JSONResponse([
            'Name' => $folder->getName(),
            'UserCanCreateChildFile' => $folder->isCreatable(),
            'UserCanCreateChildContainer' => $folder->isCreatable(),
            'UserCanDelete' => false,   // DeleteContainer 지원 X
            'UserCanRename' => false,   // RenameContainer 지원 X
            'IsAnonymousUser' => false, // 항상 로그인한 사용자의 토큰이다
        ]);
    }

    /**
     * $containerId 폴더 바로 아래의 폴더와 파일을 반환함 (하위 폴더 안까지 탐색하지 않음)
     * X-WOPI-FileExtensionFilterList 헤더가 있으면 해당 확장자의 파일만 반환함
     *
     * <pre>
     * {
     *   "ChildContainers": [
     *     { "Name": "FolderName",  "Url": "http://.../wopi/containers/{containerId1}?access_token={token1}" },
     *     { "Name": "FolderName2", "Url": "http://.../wopi/containers/{containerId2}?access_token={token2}" }
     *   ],
     *   "ChildFiles": [
     *     {
     *       "Name": "FileName.docx",
     *       "Url": "http://.../wopi/files/{fileId}?access_token={token3}",
     *       "Size": 7,
     *       "Version": "{etag}",
     *       "LastModifiedTime": "2026-09-28T12:34:56.0000000Z"
     *     }
     *   ]
     * }
     * </pre>
     *
     * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/containers/enumeratechildren
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function enumerateChildren(string $containerId): JSONResponse
    {
        $session = $this->containerService->resolveContainer($this->request, $containerId);

        if ($session === null) {
            return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
        }

        [$folder, $userId] = $session;

        $filterHeader = $this->request->getHeader('X-WOPI-FileExtensionFilterList');

        [$containers, $files] = $this->containerService->getChildren($folder, $userId, $filterHeader);

        return new JSONResponse([
            'ChildContainers' => $containers,
            "ChildFiles" => $files,
        ]);
    }

    /**
     * $containerId를 통해 해당 파일의 조상 폴더들을 순서대로 반환함
     * <pre>
     * {
     *   "AncestorsWithRootFirst": [
     *     { "Name": "root",        "Url": "http://.../wopi/containers/<id1>?access_token=<token1>" },
     *     { "Name": "grandparent", "Url": "http://.../wopi/containers/<id2>?access_token=<token2>" },
     *     { "Name": "parent",      "Url": "http://.../wopi/containers/<id3>?access_token=<token3>" }
     *   ]
     * }
     * </pre>
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function enumerateAncestors(string $containerId): JSONResponse
    {
        $session = $this->containerService->resolveContainer($this->request, $containerId);
        if ($session === null) {
            return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
        }

        [$file, $userId] = $session;

        return new JSONResponse([
            'AncestorsWithRootFirst' => $this->containerService->getAncestors($file, $userId),
        ]);
    }

    /**
     * X-WOPI-Override 헤더로 동작이 갈리는 엔드포인트.
     *
     * 현재 CREATE_CHILD_FILE만 지원함
     * CREATE_CHILD_CONTAINER, DELETE_CONTAINER, RENAME_CONTAINER 는 지원하지 않는다.
     * 지원하지 않는 동작에는 501을 반환한다.
     *
     * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/containers/createchildfile
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function postContainer(string $containerId): JSONResponse
    {
        $session = $this->containerService->resolveContainer($this->request, $containerId);

        if ($session === null) {
            return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
        }

        [$folder, $userId] = $session;
        $override = strtoupper((string)$this->request->getHeader('X-WOPI-Override'));

        switch ($override) {
            case 'CREATE_CHILD_FILE':
                return $this->responder->toResponse($this->fileService->createChildFile($this->request, $folder), $userId);
            default:
                $this->logger->info('Unsupported WOPI container override "' . $override . '"', [
                    'app' => $this->appName,
                ]);

                return new JSONResponse([], Http::STATUS_NOT_IMPLEMENTED);
        }
    }
}
