<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Service;

use OCA\Thinkfree\AppInfo\Application;
use OCA\Thinkfree\Exception\InvalidTokenException;
use OCA\Thinkfree\Exception\ProofValidationException;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * WOPI 요청을 검증한다. 파일, 폴더, 에코시스템 요청이 모두 이곳을 거친다.
 * WopiAuthMiddleware 가 #[WopiAuthRequired] 액션을 실행하기 전에 호출
 *
 *   1. 액세스 토큰   요청 대상(자원 ID)에 묶인 토큰인지, 만료되지 않았는지
 *   2. 계정          토큰의 계정이 아직 활성 상태인지
 *   3. proof 서명    웹오피스가 보낸 요청인지
 *
 */
class RequestAuthenticator {
	private WopiTokenService $tokenService;
	private ProofKeyService $proofKeyService;
	private IUserManager $userManager;
	private LoggerInterface $logger;

	public function __construct(
		WopiTokenService $tokenService,
		ProofKeyService $proofKeyService,
		IUserManager $userManager,
		LoggerInterface $logger,
	) {
		$this->tokenService = $tokenService;
		$this->proofKeyService = $proofKeyService;
		$this->userManager = $userManager;
		$this->logger = $logger;
	}

	/**
	 * 요청을 확인하고, 통과하면 토큰에 담긴 값을 돌려준다.
	 *
	 * expires 는 요청 토큰의 만료 시각이다.
	 *
	 * @param string $resourceId 파일 ID, 폴더 ID 또는 WopiTokenService::ECOSYSTEM_ID
	 * @param string $target 로그에 남길 요청 대상 (예: "file 123")
	 * @return array{userId: string, canWrite: bool, expires: int}
	 * @throws InvalidTokenException 토큰이 올바르지 않거나 만료되었을 때, 계정이 삭제되었거나 비활성일 때
	 * @throws ProofValidationException proof 서명을 검증하지 못했을 때. 이유는 ProofKeyService 가 로그에 남긴다
	 */
	public function authenticate(IRequest $request, string $resourceId, string $target): array {
		$token = $this->tokenService->extractToken($request);

		// 1. 액세스 토큰
		$claims = $this->tokenService->verify($token, $resourceId);

		if ($claims === null) {
			$this->logger->warning('Rejected WOPI request for ' . $target . ': invalid access token', [
				'app' => Application::APP_ID,
			]);

			throw new InvalidTokenException();
		}

		// 2. 계정
        // 삭제되었거나 비활성 계정인지 확인
		$user = $this->userManager->get($claims['userId']);

		if ($user === null || !$user->isEnabled()) {
			$this->logger->warning('Rejected WOPI request for ' . $target . ': account is disabled or deleted', [
				'app' => Application::APP_ID,
			]);

			throw new InvalidTokenException();
		}

		// 3. ProofKey 검증
		if (!$this->proofKeyService->verify($request, (string)$token)) {
			throw new ProofValidationException();
		}

		return $claims;
	}
}
