<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Middleware\Wopi;

use OCP\Files\File;
use OCP\Files\Folder;

/**
 * 검증을 마친 WOPI 요청의 정보. WopiAuthMiddleware 가 채우고 컨트롤러가 꺼내 쓴다.
 *
 * Nextcloud 의 DI 컨테이너는 요청마다 새로 만들어지고, 자동으로 만든 객체를 그 요청
 * 안에서 공유한다. 그래서 미들웨어와 컨트롤러가 주입받는 이 객체는 같은 인스턴스다.
 *
 * 비어있는 값을 꺼내면 LogicException 을 던진다.
 */
class WopiContext {
	private ?string $userId = null;
	private bool $canWrite = false;
	private int $expires = 0;
	private ?File $file = null;
	private ?Folder $folder = null;

	/**
	 * @param int $expires 요청 토큰의 만료 시각. 응답에 새 토큰을 실을 때 WopiUrlBuilder 에 넘긴다.
	 */
	public function setAuthenticated(string $userId, bool $canWrite, int $expires): void {
		$this->userId = $userId;
		$this->canWrite = $canWrite;
		$this->expires = $expires;
	}

	public function setFile(File $file): void {
		$this->file = $file;
	}

	public function setFolder(Folder $folder): void {
		$this->folder = $folder;
	}

	public function getUserId(): string {
		return $this->userId ?? throw new \LogicException('The WOPI request has not been authenticated. Is the action marked #[WopiAuthRequired]?');
	}

	/** 토큰의 쓰기 권한 */
	public function canWrite(): bool {
		$this->getUserId();

		return $this->canWrite;
	}

	/** 요청 토큰의 만료 시각 */
	public function getExpires(): int {
		$this->getUserId();

		return $this->expires;
	}

	public function getFile(): File {
		return $this->file ?? throw new \LogicException('No file was resolved for this request. Is the action marked #[WopiAuthRequired(WopiResource::File)]?');
	}

	public function getFolder(): Folder {
		return $this->folder ?? throw new \LogicException('No folder was resolved for this request. Is the action marked #[WopiAuthRequired(WopiResource::Container)]?');
	}
}
