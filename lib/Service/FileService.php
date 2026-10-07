<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Service;

use OCA\Thinkfree\AppInfo\Application;
use OCA\Thinkfree\Util\FileNameResolver;
use OCA\Thinkfree\Util\NewFileResult;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotPermittedException;
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
	private LoggerInterface $logger;
	private RequestAuthenticator $authenticator;
	private FileNameResolver $nameResolver;

	public function __construct(
		IRootFolder $rootFolder,
		LoggerInterface $logger,
		RequestAuthenticator $authenticator,
		FileNameResolver $nameResolver,
	) {
		$this->rootFolder = $rootFolder;
		$this->logger = $logger;
		$this->authenticator = $authenticator;
		$this->nameResolver = $nameResolver;
	}

	/**
	 * 요청을 확인한 다음, 성공하면 file, userId, canWrite, expires 를 돌려준다.
	 *
	 * File 조작 엔드포인트 요청은 모두 이 메서드를 거쳐야 한다.
	 *
	 * @return array{0: File, 1: string, 2: bool, 3: int}|null
	 */
	public function resolve(IRequest $request, string $fileId): ?array {
		$claims = $this->authenticator->authenticate($request, $fileId, 'file ' . $fileId);

		if ($claims === null) {
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

		return [$file, $userId, $claims['canWrite'], $claims['expires']];
	}

	/**
	 * 요청 본문을 파일에 쓴다.
	 *
	 * 본문을 버퍼에 끝까지 받은 뒤에 원본에 쓴다. 원본에 바로 쓰면 업로드가 도중에
	 * 끊겼을 때 원본이 이미 비워져 앞부분만 남는다. 원본 파일 객체에 putContent 로
	 * 쓰므로 파일 ID(공유, 버전, WOPI 토큰이 묶인 값)는 그대로다.
	 *
	 * @param int|null $expectedSize Content-Length. 모르면 null 이고, 크기를 확인하지 않는다
	 * @throws NotEnoughSpaceException 남은 용량이 부족할 때. 원본은 그대로다
	 * @throws \RuntimeException 읽거나 쓰지 못했거나, 받은 크기가 Content-Length 와 다를 때
	 */
	public function writeToFile(File $file, ?int $expectedSize): void {
		$buffer = $this->receiveToBuffer($file, $expectedSize);

		try {
			// 스트림을 넘기면 NextCloud 가 읽기·쓰기 실패와 short write 를 확인해 예외를 던진다.
			$file->putContent($buffer); // 버퍼에서 $file로 데이터를 write 한다.
		} finally {
			// putContent 가 성공하면 스트림을 직접 닫으므로 남아 있을 때만 닫는다
			if (is_resource($buffer)) {
				fclose($buffer);
			}
		}
	}

	/**
	 * 요청 헤더의 Content-Length. 없거나 숫자가 아니면 크기를 모르는 것으로 보고 null 이다.
	 */
	public static function contentLength(IRequest $request): ?int {
		$header = $request->getHeader('Content-Length');

		return ctype_digit($header) ? (int)$header : null;
	}

	/**
	 * 요청 본문을 버퍼에 끝까지 받고, $target 에 쓸 수 있는지 확인한 뒤 버퍼를 돌려준다.
	 *
	 * @param Node $target 쓰일 곳. 기존 파일에 쓰면 그 파일, 새로 만들면 그 부모 폴더
	 * @param int|null $expectedSize Content-Length. 모르면 null 이고, 크기를 확인하지 않는다
	 * @return resource 데이터를 갖고있는 버퍼
	 * @throws NotEnoughSpaceException 받은 본문보다 남은 용량이 작을 때
	 * @throws \RuntimeException 본문을 읽지 못했거나, 받은 크기가 Content-Length 와 다를 때
	 */
	private function receiveToBuffer(Node $target, ?int $expectedSize) {
		$in = $this->openRequestBody(); // request body로 들어온 바이너리 스트림을 받는다.
		$buffer = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b'); // 2MB까지만 메모리에 받고, 그 크기를 넘으면 임시파일로 이동한다.

		try {
			if ($in === false || $buffer === false) {
				throw new \RuntimeException('Cannot read request body');
			}

			// 버퍼 (메모리 또는 임시파일)에 임시로 저장해둔다.
			$received = stream_copy_to_stream($in, $buffer);
			if ($received === false) {
				throw new \RuntimeException('Failed to read request body');
			}

			// Content-Length 만큼 본문을 다 받았는지 확인한다.
			if ($expectedSize !== null && $received !== $expectedSize) {
				throw new \RuntimeException('Request body truncated: expected ' . $expectedSize . ', received ' . $received);
			}

			// 할당량 스트림은 남은 용량까지만 쓰고 나머지를 버린다. 모자라면 쓰기 전에 멈춘다.
			// 실제로 받은 크기로 확인하므로 Content-Length 가 없는 요청도 확인된다.
			// 파일 하나만 공유받은 경우 쓰기에는 소유자의 할당량이 적용되므로, 대상이 들어 있는
			// 저장소에서 남은 용량을 구한다. 음수면 무제한이거나 아직 계산되지 않은 것이다.
			$free = $target->getStorage()->free_space($target->getInternalPath());
			if ($free >= 0 && $received > $free) {
				throw new NotEnoughSpaceException('Not enough storage: need ' . $received . ', free ' . $free);
			}

			// 현재 버퍼의 포인터는 끝까지 이동한 상태이므로 버퍼의 데이터를
			// 쓰기 위해서는 rewind가 필요하다.
			rewind($buffer); // 버퍼의 포인터를 0으로 이동시킨다.
		} catch (\Throwable $e) {
			if (is_resource($buffer)) {
				fclose($buffer);
			}

			throw $e;
		} finally {
			if (is_resource($in)) {
				fclose($in);
			}
		}

		return $buffer;
	}

	/**
	 * 요청 본문 스트림을 연다.
	 *
     * 단위 테스트에서는 이 메서드를 오버라이드해서 사용한다
	 *
	 * @return resource|false 열지 못하면 false
	 */
	protected function openRequestBody() {
		return fopen('php://input', 'rb');
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
		$resolved = $this->nameResolver->resolve($request, $parent, $file->getName());

		if ($resolved['reason'] !== NewFileResult::REASON_OK) {
			return $resolved;
		}

		$name = $resolved['name'];

		// 덮어쓰면 그 파일에, 새로 만들면 부모 폴더에 쓰인다. 용량은 쓰일 곳의 저장소로 확인한다.
		// 이름이 이미 있다면 FileNameResolver 가 덮어써도 되는 파일임을 확인했다.
		$exists = $parent->nodeExists($name);
		$destination = $exists ? $parent->get($name) : $parent;

		// 쓰기 전에 본문을 끝까지 받고 용량을 확인한다. 바로 쓰다가 실패하면 새 파일은
		// 앞부분만 남고(같은 이름으로 다시 시도하면 409), 덮어쓴 파일은 잘린 내용으로 바뀐다.
		try {
			$buffer = $this->receiveToBuffer($destination, self::contentLength($request));
		} catch (NotEnoughSpaceException $e) {
			return ['reason' => NewFileResult::REASON_INSUFFICIENT_STORAGE];
		} catch (\Throwable $e) {
			$this->logger->error('WOPI PutRelativeFile failed for file ' . $file->getId(), [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);

			return ['reason' => NewFileResult::REASON_WRITE_FAILED];
		}

		try {
			// newFile 과 putContent 는 resource 를 받아 스트림으로 쓴다. 본문을
			// 문자열로 올리지 않으므로 큰 문서에서도 메모리가 일정하다.
			if ($exists) {
				$destination->putContent($buffer);
				$created = $destination;
			} else {
				$created = $parent->newFile($name, $buffer);
			}
		} catch (\Throwable $e) {
			// 이 요청이 새로 만든 파일이면 지운다. 남기면 폴더에 잘린 파일이 보이고, 같은 이름으로
			// 다시 시도하면 409 가 난다. newFile() 이 예외를 던지면 반환값이 없으므로 이름으로 찾는다.
			// 덮어쓴 경우는 원래 있던 사용자 파일이므로 지우지 않는다.
			if (!$exists && $parent->nodeExists($name)) {
				try {
					$parent->get($name)->delete();
				} catch (\Throwable $deleteError) {
					$this->logger->warning('Could not remove the partially written file ' . $name, [
						'app' => Application::APP_ID,
						'exception' => $deleteError,
					]);
				}
			}

			$this->logger->error('WOPI PutRelativeFile failed for file ' . $file->getId(), [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);

			return ['reason' => NewFileResult::REASON_WRITE_FAILED];
		} finally {
			// newFile·putContent 가 성공하면 스트림을 직접 닫으므로 남아 있을 때만 닫는다
			if (is_resource($buffer)) {
				fclose($buffer);
			}
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
		if (!$folder->isUpdateable()) {
			return ['reason' => NewFileResult::REASON_FORBIDDEN];
		}

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
	 * DeleteFile — 파일을 지운다. 지운 파일은 NextCloud 휴지통으로 간다.
	 *
	 * @param bool $canWrite 요청 토큰의 쓰기 권한
	 * @throws NotPermittedException 지울 권한이 없을 때
	 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/files/deletefile
	 */
	public function deleteFile(File $file, bool $canWrite): void {
		if (!$canWrite || !$file->isDeletable()) {
			throw new NotPermittedException('Not allowed to delete file ' . $file->getId());
		}

		$file->delete();
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
