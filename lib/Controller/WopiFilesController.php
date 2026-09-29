<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Controller;

use OCA\Thinkfree\Service\ContainerService;
use OCA\Thinkfree\Service\FileService;
use OCA\Thinkfree\Util\NewFileResponder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * WOPI 엔드포인트를 구현한 컨트롤러.
 * appinfo/routes.php에 의해 라우팅된다.
 *
 * 파일을 다루는 일은 FileService 가 맡는다. 여기서는 요청을 넘기고, 돌아온
 * 결과를 상태 코드와 응답 본문으로 옮기는 것까지만 한다.
 *
 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/
 */
class WopiFilesController extends Controller {
	private FileService $fileService;
	private IUserManager $userManager;
	private LoggerInterface $logger;
    private ContainerService $containerService;
    private NewFileResponder $responder;

	public function __construct(
        string $appName,
        IRequest $request,
        FileService $fileService,
        IUserManager $userManager,
        LoggerInterface $logger,
        ContainerService $containerService,
        NewFileResponder $responder
	) {
		parent::__construct($appName, $request);
		$this->fileService = $fileService;
		$this->userManager = $userManager;
		$this->logger = $logger;
        $this->containerService = $containerService;
        $this->responder = $responder;
	}

	/**
	 * CheckFileInfo 엔드포인트 구현
     * 파일 메타데이터를 응답한다.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function checkFileInfo(string $fileId): JSONResponse {
		$session = $this->fileService->resolve($this->request, $fileId);
		if ($session === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		[$file, $userId, $canWrite] = $session;

		$user = $this->userManager->get($userId);

		return new JSONResponse([
			'BaseFileName' => $file->getName(),
			'Size' => $file->getSize(),
			'Version' => (string)$file->getEtag(),
			'OwnerId' => $userId,
			'UserId' => $userId,
			'UserFriendlyName' => $user !== null ? $user->getDisplayName() : $userId,
			'UserCanWrite' => $canWrite,
			// true 면 클라이언트가 "다른 이름으로 저장"을 메뉴에서 아예 없앤다.
			// 새 파일은 원본과 같은 폴더에 생기므로 그 폴더의 생성 권한으로 본다.
			'UserCanNotWriteRelative' => !$this->fileService->canWriteRelative($file),
			'SupportsUpdate' => $canWrite,
			'SupportsLocks' => false,
			'SupportsRename' => false,
			'LastModifiedTime' => gmdate('Y-m-d\TH:i:s.u\Z', $file->getMTime()),
            'SupportsContainers' => true,
            'SupportsEcosystem' => true
		]);
	}

	/**
	 * 파일 바이너리 스트림을 응답하는 엔드포인트
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getFile(string $fileId): Response {
		$session = $this->fileService->resolve($this->request, $fileId);
		if ($session === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		[$file] = $session;

		try {
			$response = new DataDownloadResponse(
				$file->getContent(), // 파일 바이너리
				$file->getName(),
				$file->getMimeType()
			);
			$response->addHeader('X-WOPI-ItemVersion', (string)$file->getMTime()); // TODO: ETag 사용 고려할것

			return $response;
		} catch (\Throwable $e) {
			$this->logger->error('WOPI GetFile failed for file ' . $fileId, [
				'app' => $this->appName,
				'exception' => $e,
			]);

			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * 파일 저장 요청을 처리하는 엔드포인트
     *
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function putFile(string $fileId): Response {
		$session = $this->fileService->resolve($this->request, $fileId); // proof key 및 액세스 토큰 검증
		if ($session === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		[$file, , $canWrite] = $session;

		if (!$canWrite) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->fileService->writeToFile($file);
		} catch (\Throwable $e) {
			$this->logger->error('WOPI PutFile failed for file ' . $fileId, [
				'app' => $this->appName,
				'exception' => $e,
			]);

			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$response = new Response();
		$response->addHeader('X-WOPI-ItemVersion', (string)$file->getMTime());

		return $response;
	}

	/**
     * X-WOPI-Override 헤더로 동작이 갈리는 엔드포인트.
     *
     * PUT_RELATIVE 는 "다른 이름으로 저장"이다.
     *
     * LOCK 계열은 TFO 가 자체 동시편집을 지원하므로 구현하지 않고, 어떤 요청이
     * 오든 성공으로 답한다.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function postFile(string $fileId): Response {
		$session = $this->fileService->resolve($this->request, $fileId);
		if ($session === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		[$file, $userId] = $session;
		$override = strtoupper((string)$this->request->getHeader('X-WOPI-Override'));

		$response = new Response();

		switch ($override) {
			case 'LOCK':
			case 'REFRESH_LOCK':
			case 'UNLOCK':
				$response->addHeader('X-WOPI-ItemVersion', (string)$file->getMTime());

				return $response;
			case 'GET_LOCK':
				$response->addHeader('X-WOPI-Lock', '');

				return $response;
			case 'PUT_RELATIVE':
				return $this->responder->toResponse($this->fileService->putRelative($this->request, $file), $userId);
			default:
				$this->logger->info('Unsupported WOPI override "' . $override . '"', [
					'app' => $this->appName,
				]);

				return new JSONResponse([], Http::STATUS_NOT_IMPLEMENTED);
		}
	}

    /**
     * fileId를 통해 해당 파일의 조상 폴더들을 순서대로 반환함
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
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function enumerateAncestors(string $fileId): JSONResponse {
        $session = $this->fileService->resolve($this->request, $fileId);
        if ($session === null) {
            return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
        }

        [$file, $userId] = $session;

        return new JSONResponse([
            'AncestorsWithRootFirst' => $this->containerService->getAncestors($file, $userId),
        ]);
    }
}
