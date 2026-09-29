<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Service;

use OCA\Thinkfree\AppInfo\Application;
use OCA\Thinkfree\Util\FileNameResolver;
use OCA\Thinkfree\Util\NewFileResult;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * WOPI 엔드포인트를 통해 파일을 조작하는 서비스
 */
class FileService {
	/**
	 * CreateChildFile 에서 X-WOPI-SuggestedTarget 으로 확장자만 왔을 때 붙일 이름.
	 * PutRelativeFile 과 달리 기준이 될 원본 파일이 없다.
	 */
	private const DEFAULT_CHILD_FILE_NAME = 'New Document';

	private IRootFolder $rootFolder;
	private WopiTokenService $tokenService;
	private ProofKeyService $proofKeyService;
	private LoggerInterface $logger;
	private FileNameResolver $nameResolver;

	public function __construct(
        IRootFolder      $rootFolder,
        WopiTokenService $tokenService,
        ProofKeyService  $proofKeyService,
        LoggerInterface  $logger,
        FileNameResolver $nameResolver,
	) {
		$this->rootFolder = $rootFolder;
		$this->tokenService = $tokenService;
		$this->proofKeyService = $proofKeyService;
		$this->logger = $logger;
		$this->nameResolver = $nameResolver;
	}

	/**
	 * proof 키와 토큰을 검증한 다음, 성공하면 file, userId, canWrite 를 돌려준다.
	 * File 조작 엔드포인트 요청은 모두 이 메서드를 거쳐야 한다.
	 *
	 * @return array{0: File, 1: string, 2: bool}|null
	 */
	public function resolve(IRequest $request, string $fileId): ?array {
		$token = $this->tokenService->extractToken($request);

		// 토큰보다 proof 키를 먼저 확인해서, 유효하지 않으면 실패
		if (!$this->proofKeyService->verify($request, (string)$token)) {
			return null;
		}

		$claims = $this->tokenService->verify($token, $fileId);

		if ($claims === null) {
			$this->logger->warning('Rejected WOPI request for file ' . $fileId . ': invalid access token', [
				'app' => Application::APP_ID,
			]);

			return null;
		}

		$userId = $claims['userId'];

		try {
			$nodes = $this->rootFolder->getUserFolder($userId)->getById((int)$fileId);
		} catch (\Throwable $e) {
			$this->logger->error('WOPI lookup failed for file ' . $fileId, [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);

			return null;
		}

		$file = $nodes[0] ?? null;

		if (!$file instanceof File) {
			$this->logger->warning('WOPI request for unknown file ' . $fileId, ['app' => Application::APP_ID]);

			return null;
		}

		return [$file, $userId, $claims['canWrite']];
	}

	/**
	 * 요청 본문을 파일에 쓴다.
	 *
	 * @throws \RuntimeException 읽거나 쓰지 못했을 때
	 */
	public function writeToFile(File $file): void {
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
	}

	/**
	 * PutRelativeFile — "다른 이름으로 저장".
	 *
	 * 새 파일은 원본과 같은 폴더에 만들어지고, 요청 본문이 내용이 된다.
	 * 다른 폴더에 저장하는 것은 createChildFile 이 맡는다.
	 *
	 * @return array{reason: string, file?: File, validTarget?: string} NewFileResponder::toResponse 로 넘긴다
	 *
	 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/files/putrelativefile
	 */
	public function putRelative(IRequest $request, File $file): array {
		$parent = $file->getParent(); // 현재 파일이 속한 폴더 확인
		$target = $this->nameResolver->resolve($request, $parent, $file->getName());

		if ($target['reason'] !== NewFileResult::REASON_OK) {
			return $target;
		}

		$name = $target['name'];

		try {
			$in = fopen('php://input', 'rb');

			if ($in === false) {
				throw new \RuntimeException('Cannot read request body');
			}

			// newFile 과 putContent 는 resource 를 받아 스트림으로 쓴다. 본문을
			// 문자열로 올리지 않으므로 큰 문서에서도 메모리가 일정하다.
			// 이름이 이미 있다면 resolveName 이 덮어써도 되는 파일임을 확인했다.
			if ($parent->nodeExists($name)) {
				$created = $parent->get($name);
				$created->putContent($in);
			} else {
				$created = $parent->newFile($name, $in);
			}
		} catch (\Throwable $e) {
			$this->logger->error('WOPI PutRelativeFile failed for file ' . $file->getId(), [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);

			return ['reason' => NewFileResult::REASON_WRITE_FAILED];
		}

		return ['reason' => NewFileResult::REASON_OK, 'file' => $created];
	}

	/**
	 * CreateChildFile — $folder 안에 빈 파일을 만든다.
	 *
	 * 명세상 결과 파일은 0바이트여야 한다. 내용은 클라이언트가 응답의 Url 로
	 * PutFile 을 보내 채운다. 다른 이름으로 저장할 때 탐색기에서 고른 폴더에
	 * 저장하는 데 쓰인다.
	 *
	 * @return array{reason: string, file?: File, validTarget?: string}
	 */
	public function createChildFile(IRequest $request, Folder $folder): array {
		$target = $this->nameResolver->resolve($request, $folder, self::DEFAULT_CHILD_FILE_NAME);

		if ($target['reason'] !== NewFileResult::REASON_OK) {
			return $target;
		}

		$name = $target['name'];

		try {
			// 이름이 이미 있다면 resolveName 이 덮어써도 되는 파일임을 확인했다.
			// 덮어쓸 때도 결과는 0바이트여야 하므로 내용을 비운다.
			if ($folder->nodeExists($name)) {
				$created = $folder->get($name);
				$created->putContent('');
			} else {
				$created = $folder->newFile($name);
			}
		} catch (\Throwable $e) {
			$this->logger->error('WOPI CreateChildFile failed for container ' . $folder->getId(), [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);

			return ['reason' => NewFileResult::REASON_WRITE_FAILED];
		}

		return ['reason' => NewFileResult::REASON_OK, 'file' => $created];
	}

	/**
	 * 새 파일을 만들 수 있는지. 원본이 든 폴더의 생성 권한으로 판단한다.
	 * CheckFileInfo 의 UserCanNotWriteRelative 가 이 값을 뒤집어 쓴다.
	 */
	public function canWriteRelative(File $file): bool {
		try {
			return $file->getParent()->isCreatable(); // 파일이 속한 폴더의 권한을 확인한다.
		} catch (\Throwable $e) {
			return false;
		}
	}
}
