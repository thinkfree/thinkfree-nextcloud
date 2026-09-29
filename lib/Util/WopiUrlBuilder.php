<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Util;

use OCA\Thinkfree\AppInfo\Application;
use OCA\Thinkfree\Service\WopiTokenService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IURLGenerator;

/**
 * WOPI 응답에 실을 자원 URL 을 만드는 빌더
 */
class WopiUrlBuilder {
	private IURLGenerator $urlGenerator;
	private WopiTokenService $tokenService;

	public function __construct(IURLGenerator $urlGenerator, WopiTokenService $tokenService) {
		$this->urlGenerator = $urlGenerator;
		$this->tokenService = $tokenService;
	}

	/**
	 * 파일의 CheckFileInfo 주소. 토큰이 붙는다.
     * CheckFileInfo 주소가 파일 오퍼레이션의 베이스 주소로 쓰이기 때문에 사용
	 */
	public function forFile(File $file, string $userId): string {
		return $this->build(
			'wopiFiles.checkFileInfo',
			['fileId' => (string)$file->getId()],
			$userId,
			(string)$file->getId(),
			$file->isUpdateable()
		);
	}

	/**
	 * 폴더의 CheckContainerInfo 주소. 토큰이 붙는다.
     * CheckContainerInfo 주소가 컨테이너 오퍼레이션의 베이스 주소로 쓰이기 때문에 사용
	 */
	public function forContainer(Folder $folder, string $userId): string {
		return $this->build(
			'wopiContainers.checkContainerInfo',
			['containerId' => (string)$folder->getId()],
			$userId,
			(string)$folder->getId(),
			false
		);
	}

	/**
	 * Ecosystem 엔드포인트 주소. GetEcosystem 이 돌려주는 값이다.
	 *
	 * 이 엔드포인트에는 자원 ID 가 없어, 토큰은 고정 문자열에 묶인다.
	 */
	public function forEcosystem(string $userId): string {
		return $this->build(
			'wopiEcosystem.checkEcosystem',
			[],
			$userId,
			WopiTokenService::ECOSYSTEM_ID,
			false
		);
	}

	/**
	 * 파일의 WOPISrc. 토큰을 붙이지 않는다.
	 *
	 * 편집기를 열 때는 토큰이 폼 POST 본문으로 따로 가므로, 여기에 실으면
	 * 브라우저 히스토리와 서버 로그에 남는다.
	 */
	public function wopiSrc(string $fileId): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			Application::APP_ID . '.wopiFiles.checkFileInfo',
			['fileId' => $fileId]
		);
	}

	/**
	 * @param string $route appinfo/routes.php 의 이름 (앱 ID 제외)
	 * @param array<string, string> $params 라우트 파라미터
	 * @param string $resourceId 토큰을 묶을 자원. 파일 ID, 폴더 ID 또는 고정값
	 */
	private function build(string $route, array $params, string $userId, string $resourceId, bool $canWrite): string {
		[$token] = $this->tokenService->issue($userId, $resourceId, $canWrite);

		$url = $this->urlGenerator->linkToRouteAbsolute(
			Application::APP_ID . '.' . $route,
			$params
		);

		return $url . '?access_token=' . rawurlencode($token);
	}
}
