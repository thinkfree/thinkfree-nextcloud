<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Controller;

use OCA\Thinkfree\Service\DiscoveryService;
use OCA\Thinkfree\Service\WopiTokenService;
use OCA\Thinkfree\Util\WopiUrlBuilder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Turns "the logged-in user picked a document" into a launchable editor URL.
 *
 * Unlike the WOPI endpoints this runs in the user's session, and it is the only
 * place that mints an access token. The browser then opens the web office
 * directly in its own tab.
 */
class WopiEditorController extends Controller {
	private IRootFolder $rootFolder;
	private IUserSession $userSession;
	private DiscoveryService $discoveryService;
	private WopiTokenService $tokenService;
	private WopiUrlBuilder $urlBuilder;
	private IL10N $l10n;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		IRootFolder $rootFolder,
		IUserSession $userSession,
		DiscoveryService $discovery,
		WopiTokenService $tokenService,
		WopiUrlBuilder $urlBuilder,
		IL10N $l10n,
		LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
		$this->rootFolder = $rootFolder;
		$this->userSession = $userSession;
		$this->discoveryService = $discovery;
		$this->tokenService = $tokenService;
		$this->urlBuilder = $urlBuilder;
		$this->l10n = $l10n;
		$this->logger = $logger;
	}

	/**
     * wopi.js의 run 콜백이 호출하고 appinfo/routes.php에 의해 라우팅된다.
     * 브라우저가 새 탭에서 웹오피스로 POST할 주소와 액세스 토큰을 전달한다.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function launch(string $fileId): JSONResponse {
		$editorUrl = $this->buildEditorUrl($fileId); // client에게 요청할 URL을 생성

		if (isset($editorUrl['error'])) {
			return new JSONResponse(['error' => $editorUrl['error']], $editorUrl['status']);
		}

		return new JSONResponse([
			'url' => $editorUrl['url'],
			'token' => $editorUrl['token'],
			'ttl' => $editorUrl['ttl'],
		]);
	}

	/**
	 * 캐싱된 dicovery 정보를 가져와서 WOPI client에 처음 요청할 URL을 만들어서 반환한다.
     * <p>
     *     <img src="../../docs/url_generate.png">
     * </p>

	 * @return array{url: string, token: string, ttl: string, title: string}|array{error: string, status: int}
	 */
	private function buildEditorUrl(string $fileId): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [
				'error' => $this->l10n->t('You are not logged in.'),
				'status' => Http::STATUS_UNAUTHORIZED,
			];
		}

		$userId = $user->getUID(); // userId 가져오기
		$notFound = [
			'error' => $this->l10n->t('Failed to open the requested file.'),
			'status' => Http::STATUS_NOT_FOUND,
		];

		try {
			$nodes = $this->rootFolder->getUserFolder($userId)->getById((int)$fileId); // fileId로 file 가져오기
		} catch (\Throwable $e) {
			$this->logger->error('Failed to look up file ' . $fileId, [
				'app' => $this->appName,
				'exception' => $e,
			]);

			return $notFound;
		}

		$file = $nodes[0] ?? null; // Node[] 형식으로 이루어져 있으므로 꺼내기
		if (!$file instanceof File) {
			return $notFound;
		}

		$canWrite = $file->isUpdateable();
		$extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));

		$urlSrc = $this->resolveUrlSrc($extension, $canWrite); // 요청 url 가져오기
		if ($urlSrc === null) {
			$this->logger->warning(
				'No WOPI action advertised for ".' . $extension . '" by ' . $this->discoveryService->getWopiUrl(),
				['app' => $this->appName]
			);

			return [
				'error' => $this->l10n->t('This file type is not supported by the configured web office.'),
				'status' => Http::STATUS_UNSUPPORTED_MEDIA_TYPE,
			];
		}

		[$token, $expires] = $this->tokenService->issue($userId, $fileId, $canWrite); // 액세스 토큰 생성

		$wopiSrc = $this->urlBuilder->wopiSrc($fileId); // 토큰은 폼 POST 본문으로 따로 가므로 붙이지 않는다

		return [ // URL 조립하기
			'url' => $urlSrc
				. 'WOPISrc=' . rawurlencode($wopiSrc)
				. '&lang=' . rawurlencode(str_replace('_', '-', $this->l10n->getLanguageCode() ?: 'en')),
			'token' => $token,
			'ttl' => (string)($expires * 1000),
			'title' => $file->getName(),
		];
	}

	/**
     * 확장자 + action에 알맞은 url을 반환한다.
	 */
	private function resolveUrlSrc(string $extension, bool $canWrite): ?string {
		if ($canWrite) {
			$urlSrc = $this->discoveryService->getUrlSrc($extension, 'edit'); // 확장자+edit에 맞는 discovery 정보(url)를 가져옴
			if ($urlSrc !== null) {
				return $urlSrc;
			}
		}

        // 만약 write가 불가능하다면 view를 반환한다.
		return $this->discoveryService->getUrlSrc($extension, 'view'); // 확장자 + view에 맞는 discovery 정보(url)를 가져옴
	}
}
