<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Reference;

use OCA\Thinkfree\AppInfo\Application;
use OCA\Thinkfree\Service\DiscoveryService;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\Reference;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * 호스트 페이지 링크(/apps/thinkfree/edit/{fileId}, /view/{fileId})를 링크 미리보기로 바꾼다.
 *
 * Assistant 채팅, Talk, Text 처럼 링크 미리보기를 그리는 곳에 이 링크가 있으면
 * js/reference.js 의 위젯이 그 자리에 Thinkfree Office 를 띄운다.
 * Smart Picker 에도 나타나, 파일을 골라 이 링크를 넣을 수 있다 (js/reference.js).
 *
 * 미리보기 결과는 캐시되므로 액세스 토큰은 여기 넣지 않는다. 위젯이 화면에 뜰 때 launch 로 받는다.
 */
class DocumentReferenceProvider extends ADiscoverableReferenceProvider {
	/** 위젯을 고르는 rich object 형식. js/reference.js 의 registerWidget 과 같아야 한다 */
	public const RICH_OBJECT_TYPE = 'thinkfree-document';

	private IURLGenerator $urlGenerator;
	private IRootFolder $rootFolder;
	private IUserSession $userSession;
	private DiscoveryService $discoveryService;
	private IL10N $l10n;
	private LoggerInterface $logger;

	public function __construct(
		IURLGenerator $urlGenerator,
		IRootFolder $rootFolder,
		IUserSession $userSession,
		DiscoveryService $discoveryService,
		IL10N $l10n,
		LoggerInterface $logger,
	) {
		$this->urlGenerator = $urlGenerator;
		$this->rootFolder = $rootFolder;
		$this->userSession = $userSession;
		$this->discoveryService = $discoveryService;
		$this->l10n = $l10n;
		$this->logger = $logger;
	}

	public function getId(): string {
		return self::RICH_OBJECT_TYPE;
	}

	public function getTitle(): string {
		return $this->l10n->t('Thinkfree Office document');
	}

	public function getOrder(): int {
		return 50;
	}

	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function matchReference(string $referenceText): bool {
		return $this->parseFileId($referenceText) !== null;
	}

	public function resolveReference(string $referenceText): ?IReference {
		$fileId = $this->parseFileId($referenceText);
		$user = $this->userSession->getUser();

		if ($fileId === null || $user === null) {
			return null;
		}

		$reference = new Reference($referenceText);

		try {
			$file = $this->rootFolder->getUserFolder($user->getUID())->getById((int)$fileId)[0] ?? null;
		} catch (\Throwable $e) {
			$this->logger->error('Failed to look up file ' . $fileId . ' for a link preview', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			$file = null;
		}

		// 남의 파일이거나 지원하지 않는 형식이면 파일 이름도 내보내지 않는다
		$extension = $file instanceof File ? strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)) : '';
		if (!$file instanceof File || $this->discoveryService->getUrlSrc($extension, 'view') === null) {
			$reference->setAccessible(false);
			$reference->setTitle($this->l10n->t('Thinkfree Office document'));

			return $reference;
		}

		$link = $this->urlGenerator->linkToRouteAbsolute('thinkfree.wopiEditor.edit', ['fileId' => $fileId]);

		$reference->setTitle($file->getName());
		$reference->setDescription($this->l10n->t('Open in Thinkfree Office'));
		$reference->setUrl($link);
		$reference->setRichObject(self::RICH_OBJECT_TYPE, [
			'id' => $fileId,
			'name' => $file->getName(),
			'extension' => $extension,
			'link' => $link,
		]);

		return $reference;
	}

	/**
	 * 파일마다 따로 캐시한다. 접두사가 파일 ID 라서 Nextcloud 가 파일을 지우거나 이름을 바꾸거나
	 * 공유를 바꿀 때 (FileReferenceEventListener) 이 미리보기도 함께 비워진다.
	 */
	public function getCachePrefix(string $referenceId): string {
		return $this->parseFileId($referenceId) ?? '';
	}

	/**
	 * 같은 파일이라도 사용자마다 접근 권한이 달라 사용자별로 캐시한다.
	 *
	 * Nextcloud 는 제공자 ID 없이 접두사와 이 키만으로 캐시 키를 만들고, 기본 파일 미리보기
	 * (FileReferenceProvider) 도 파일 ID·사용자 ID 를 쓴다. 앞에 앱 ID 를 붙이지 않으면
	 * 같은 파일의 /f/{id} 링크와 서로의 미리보기가 뒤바뀐다.
	 */
	public function getCacheKey(string $referenceId): ?string {
		return Application::APP_ID . '-' . ($this->userSession->getUser()?->getUID() ?? '');
	}

	/**
	 * 이 Nextcloud 의 호스트 페이지 링크면 파일 ID 를, 아니면 null 을 돌려준다.
	 * index.php 가 붙은 주소와 빠진 주소를 모두 받는다.
	 */
	private function parseFileId(string $referenceText): ?string {
		$prefixes = [
			$this->urlGenerator->getAbsoluteURL('/apps/' . Application::APP_ID . '/'),
			$this->urlGenerator->getAbsoluteURL('/index.php/apps/' . Application::APP_ID . '/'),
		];

		foreach ($prefixes as $prefix) {
			$pattern = '/^' . preg_quote($prefix, '/') . '(?:edit|view)\/(\d+)(?:[?#].*)?$/';

			if (preg_match($pattern, $referenceText, $matches) === 1) {
				return $matches[1];
			}
		}

		return null;
	}
}
