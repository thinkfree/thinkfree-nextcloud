<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Controller;

use OCA\Thinkfree\Service\ProofKeyService;
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
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * WOPI 엔드포인트를 구현한 컨트롤러.
 * appinfo/routes.php에 의해 라우팅된다.
 *
 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/
 */
class WopiController extends Controller {
	private IRootFolder $rootFolder;
	private IUserManager $userManager;
	private WopiTokenService $tokenService;
	private ProofKeyService $proofKeyService;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		IRootFolder $rootFolder,
		IUserManager $userManager,
		WopiTokenService $tokenService,
		ProofKeyService $proofKeyService,
		LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
		$this->rootFolder = $rootFolder;
		$this->userManager = $userManager;
		$this->tokenService = $tokenService;
		$this->proofKeyService = $proofKeyService;
		$this->logger = $logger;
	}

	/**
	 * CheckFileInfo 엔드포인트 구현
     * 파일 메타데이터를 응답한다.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function checkFileInfo(string $fileId): JSONResponse {
		$session = $this->resolve($fileId);
		if ($session === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		[$file, $userId, $canWrite] = $session;

		$user = $this->userManager->get($userId);

		return new JSONResponse([
			'BaseFileName' => $file->getName(),
			'Size' => $file->getSize(),
			'Version' => (string)$file->getMTime(),
			'OwnerId' => $userId,
			'UserId' => $userId,
			'UserFriendlyName' => $user !== null ? $user->getDisplayName() : $userId,
			'UserCanWrite' => $canWrite,
			'UserCanNotWriteRelative' => true,
			'SupportsUpdate' => $canWrite,
			'SupportsLocks' => false,
			'SupportsRename' => false,
			'LastModifiedTime' => gmdate('Y-m-d\TH:i:s.u\Z', $file->getMTime()),
		]);
	}

	/**
	 * 파일 바이너리 스트림을 응답하는 엔드포인트
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getFile(string $fileId): Response {
		$session = $this->resolve($fileId);
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
		$session = $this->resolve($fileId); // proof key 및 액세스 토큰 검증
		if ($session === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		[$file, , $canWrite] = $session;

		if (!$canWrite) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		try {
			$in = fopen('php://input', 'rb'); // request body로 들어온 바이너리 스트림을 받는다.
			if ($in === false) {
				throw new \RuntimeException('Cannot read request body');
			}

			$out = $file->fopen('w'); // 바이너리 스트림을 쓰기 위한 file open
			if ($out === false) {
				fclose($in);
				throw new \RuntimeException('Cannot open target file for writing');
			}

			while (!feof($in)) {
				$chunk = fread($in, 8192);
				if ($chunk === false) {
					break;
				}
				fwrite($out, $chunk);
			}

			fclose($in);
			fclose($out);
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
     * LOCK과 관련된 엔드포인트, 하지만 TFO는 동시편집을 지원하므로 LOCK을 굳이 구현할 필요가 없어 미구현 상태로 둔다.
     * LOCK과 관련된 어떠한 요청이 오던, 성공했다는 응답을 보낸다.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function postFile(string $fileId): Response {
		$session = $this->resolve($fileId);
		if ($session === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		[$file] = $session;
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
			default:
				$this->logger->info('Unsupported WOPI override "' . $override . '"', [
					'app' => $this->appName,
				]);

				return new JSONResponse([], Http::STATUS_NOT_IMPLEMENTED);
		}
	}

	/**
	 * proof 키와 토큰을 검증한 다음, 성공하면 file, userId, canWrite를 사용한다.
     * File 조작 엔드포인트 요청은 모두 이 메서드를 호출하여 검증받아야 한다.
	 *
	 * @return array{0: File, 1: string, 2: bool}|null
	 */
	private function resolve(string $fileId): ?array {
		$token = $this->extractToken();

		// 토큰보다 proof 키를 먼저 확인해서, 유효하지 않으면 실패
		if (!$this->proofKeyService->verify($this->request, (string)$token)) {
			return null;
		}

        // 토큰 검증
		$claims = $this->tokenService->verify($token, $fileId);

		if ($claims === null) { // 검증이 실패할 때 null 리턴
			$this->logger->warning('Rejected WOPI request for file ' . $fileId . ': invalid access token', [
				'app' => $this->appName,
			]);

			return null;
		}

		$userId = $claims['userId'];

		try {
			$nodes = $this->rootFolder->getUserFolder($userId)->getById((int)$fileId);
		} catch (\Throwable $e) {
			$this->logger->error('WOPI lookup failed for file ' . $fileId, [
				'app' => $this->appName,
				'exception' => $e,
			]);

			return null;
		}

		$file = $nodes[0] ?? null;

		if (!$file instanceof File) {
			$this->logger->warning('WOPI request for unknown file ' . $fileId, ['app' => $this->appName]);

			return null;
		}

		return [$file, $userId, $claims['canWrite']];
	}

	/** The token may arrive as a query parameter or as a bearer token. */
	private function extractToken(): ?string {
		$token = $this->request->getParam('access_token');

		if (is_string($token) && $token !== '') {
			return $token;
		}

		$authorization = (string)$this->request->getHeader('Authorization');

		if (stripos($authorization, 'Bearer ') === 0) {
			return substr($authorization, 7);
		}

		return null;
	}
}
