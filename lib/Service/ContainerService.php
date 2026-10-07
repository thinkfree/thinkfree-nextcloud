<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Service;

use OCA\Thinkfree\AppInfo\Application;
use OCA\Thinkfree\Util\WopiUrlBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * WOPI 엔드포인트를 통해 파일을 조작하는 서비스
 */
class ContainerService {

	private IRootFolder $rootFolder;
	private LoggerInterface $logger;
    private WopiUrlBuilder $urlBuilder;
    private RequestAuthenticator $authenticator;

	public function __construct(
		IRootFolder $rootFolder,
		LoggerInterface $logger,
        WopiUrlBuilder $urlBuilder,
        RequestAuthenticator $authenticator
	) {
		$this->rootFolder = $rootFolder;
		$this->logger = $logger;
        $this->urlBuilder = $urlBuilder;
        $this->authenticator = $authenticator;
	}

    /**
     *
     * 두 번째 값은 요청 토큰의 만료 시각이다. 응답에 새 토큰을 실을 때 WopiUrlBuilder 에 넘긴다.
     *
     * @return array{0: string, 1: int}|null [userId, expires]
     */
    public function resolveForEcosystem(IRequest $request): ?array {
        $claims = $this->authenticator->authenticate($request, WopiTokenService::ECOSYSTEM_ID, 'ecosystem');

        if ($claims === null) {
            return null;
        }

        return [$claims['userId'], $claims['expires']];
    }

    /**
     *
     * 세 번째 값은 요청 토큰의 만료 시각이다. 응답에 새 토큰을 실을 때 WopiUrlBuilder 에 넘긴다.
     *
     * @return array{0: Folder, 1: string, 2: int}|null [Folder, userId, expires]
     */
    public function resolveContainer(IRequest $request, string $containerId): ?array
    {
        $claims = $this->authenticator->authenticate($request, $containerId, 'container ' . $containerId);

        if ($claims === null) {
            return null;
        }

        $userId = $claims['userId'];

        try {
            $nodes = $this->rootFolder->getUserFolder($userId)->getById((int)$containerId);
        } catch (\Throwable $e) {
            $this->logger->error('WOPI lookup failed for container ' . $containerId, [
                'app' => Application::APP_ID,
                'exception' => $e,
            ]);

            return null;
        }

        $node = $nodes[0] ?? null;

        if (!$node instanceof Folder) {
            $this->logger->error('this is not Folder :' . $containerId, ['app' => Application::APP_ID]);

            return null;
        }

        return [$node, $claims['userId'], $claims['expires']];
    }

    /**
     * 인자로 받은 폴더의 자식 폴더와 파일을 조회하여 반환
     * 해당 폴더 아래의 모든 계층을 탐색하지는 않는다
     * 오직 해당 폴더 내의 폴더/파일만 탐색함
     *
     * 자식마다 붙이는 토큰은 요청 토큰($expires)과 같은 시각에 만료된다.
     *
     * @return [containers, files]
     */
    public function getChildren(Folder $folder, string $userid, int $expires, ?string $fileExtensionFilterListHeader) : array
    {
        $children = $folder->getDirectoryListing();

        $containers = $this->getChildContainers($children, $userid, $expires);
        $files = $this->getChildFiles($children, $userid, $expires, $this->parseExtensionFilter($fileExtensionFilterListHeader));

        return [$containers, $files];
    }

    /**
     * Node(파일이나 폴더)를 받아서 유저의 루트 폴더부터 현재까지의 경로를 순서대로 반환
     * Node 자신은 넣지 않음
     *
     * 조상 폴더마다 붙이는 토큰은 요청 토큰($expires)과 같은 시각에 만료된다.
     */
    public function getAncestors(Node $node, string $userId, int $expires): array
    {
        $rootPath = $this->rootFolder->getUserFolder($userId)->getPath();
        $ancestors = [];
        $current = $node;

        while ($current->getPath() !== $rootPath) {
            $current = $current->getParent();

            if (!str_starts_with($current->getPath(), $rootPath)) {
                break;
            }

            $ancestors[] = [
                'Name' => $current->getName(),
                'Url' => $this->urlBuilder->forContainer($current, $userId, $expires)
            ];

        }

        return array_reverse($ancestors);
    }

    /**
     * X-WOPI-FileExtensionFilterList 를 소문자 확장자 목록으로 바꾼다.
     *
     * @return string[] 확장자 배열
     */
    private function parseExtensionFilter(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }

        // 쉼표로 자른다.  '.docx,.XLSX' -> ['.docx', '.XLSX']
        $extensions = explode(',', $header);

        // 앞의 점을 떼고 소문자로 맞춘다/
        $normalized = array_map(
            static fn (string $e): string => strtolower(ltrim(trim($e), '.')),
            $extensions
        );

        // 빈 원소를 버린다. 후행 쉼표나 연속된 쉼표가 오면 생긴다.
        $nonEmpty = array_filter($normalized);

        return array_values($nonEmpty);
    }

    private function getChildContainers(array $children, string $userId, int $expires) : array
    {
        $containers = [];

        foreach ($children as $container) {
            if (!$container instanceof Folder) {
                continue;
            }

            $containers[] = [
                "Name" => $container->getName(),
                "Url" => $this->urlBuilder->forContainer($container, $userId, $expires)
            ];
        }

        return $containers;
    }

    /**
     * filter가 비어있지 않다면, 해당 필터를 화이트리스트로 사용해서
     * 일치하는 확장자 파일 메타데이터만 리턴한다
     */
    private function getChildFiles(array $children, string $userId, int $expires, array $filter) : array
    {
        $files = [];

        foreach ($children as $file) {
            if (!$file instanceof File) {
                continue;
            }

            $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));

            if ($filter !== [] && !in_array($extension, $filter, true)) {
                continue;
            }

            $files[] = [
                "Name" => $file->getName(),
                "Size" => $file->getSize(),
                "Version" => (string)$file->getEtag(),
                "Url" => $this->urlBuilder->forFile($file, $userId, $expires),
                "LastModifiedTime" => gmdate('Y-m-d\TH:i:s.u\Z', $file->getMTime()),
            ];
        }

        return $files;
    }

}
