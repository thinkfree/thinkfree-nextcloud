<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Util;

use OCA\Thinkfree\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * X-WOPI-SuggestedTarget / X-WOPI-RelativeTarget 헤더로 새 파일의 이름을 정한다.
 *
 * PutRelativeFile 과 CreateChildFile 은 이름을 정하는 규칙이 같고, 어느 폴더에
 * 무엇을 쓰느냐만 다르다. 쓰는 일은 FileService 가 맡는다.
 *
 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/files/putrelativefile
 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/containers/createchildfile
 */
class FileNameResolver {
	private LoggerInterface $logger;

	public function __construct(LoggerInterface $logger) {
		$this->logger = $logger;
	}

	/**
	 * 요청 헤더로 $parent 안에 만들 파일 이름을 정한다.
	 *
	 * @param string $baseName Suggested 모드에서 확장자만 오거나 쓸 수 없는 이름이 왔을 때 기준이 되는 이름
	 * @return array{reason: string, name?: string, validTarget?: string}
	 *         reason 이 REASON_OK 면 name 에 쓸 이름이, REASON_CONFLICT 면 validTarget 에 쓸 수 있는 이름이 담긴다.
	 *         name 이 이미 있다면 덮어써도 되는 파일임이 확인된 것이다.
	 */
	public function resolve(IRequest $request, Folder $parent, string $baseName): array {
		$suggestedTarget = $this->decodeTarget($request->getHeader('X-WOPI-SuggestedTarget'));
		$relativeTarget = $this->decodeTarget($request->getHeader('X-WOPI-RelativeTarget'));

		$hasSuggested = $suggestedTarget !== '';
		$hasRelative = $relativeTarget !== '';

		if ($hasSuggested && $hasRelative) { // 두 헤더가 모두 존재하면 실패
			$this->logger->warning('WOPI request got both target headers', ['app' => Application::APP_ID]);

			return ['reason' => NewFileResult::REASON_BOTH_TARGETS];
		}

		if (!$hasSuggested && !$hasRelative) { // 두 헤더가 모두 없어도 실패
			$this->logger->warning('WOPI request got no target header', ['app' => Application::APP_ID]);

			return ['reason' => NewFileResult::REASON_NO_TARGET];
		}

		// 폴더의 생성 권한 확인
		if (!$parent->isCreatable()) {
			return ['reason' => NewFileResult::REASON_FORBIDDEN];
		}

		if ($hasRelative) { // X-WOPI-RelativeTarget의 경우
			$name = $this->sanitizeName($relativeTarget);

			if ($name === '') {
				return ['reason' => NewFileResult::REASON_ILLEGAL_NAME];
			}

			/**
			 * 같은 파일 이름이 존재할 경우 어떻게 할 것인지 정하는 헤더
			 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/files/putrelativefile
			 */
			$overwrite = strtolower((string)$request->getHeader('X-WOPI-OverwriteRelativeTarget')) === 'true';

			if ($parent->nodeExists($name)) { // 부모 폴더 안에 해당 이름이 이미 존재한다
				$existing = $parent->get($name); // 해당 이름의 노드 가져오기

				// 덮어쓰기를 허락하지 않았거나, 같은 이름의 폴더가 있는 경우.
				// 폴더는 파일로 덮어쓸 수 없으므로 덮어쓰기를 허락했어도 충돌이다.
                // validTarget에 대체하여 사용할 수 있는 이름을 돌려준다.
				if (!$overwrite || !$existing instanceof File) {
					return [
						'reason' => NewFileResult::REASON_CONFLICT,
						'validTarget' => $parent->getNonExistingName($name), // {name} (2).docx 처럼 만들어줌
					];
				}

				// 명세: 덮어쓸 권한이 없으면 501
				if (!$existing->isUpdateable()) {
					return ['reason' => NewFileResult::REASON_OVERWRITE_FORBIDDEN];
				}
			}

			return ['reason' => NewFileResult::REASON_OK, 'name' => $name];
		}

		// 점으로 시작하면 확장자다. 기준 이름에서 확장자만 갈아 끼운다.
		$name = str_starts_with($suggestedTarget, '.')
			? pathinfo($baseName, PATHINFO_FILENAME) . $suggestedTarget
			: $suggestedTarget;

		$name = $this->sanitizeName($name); // 비정상적인 이름을 경우 빈 값을 반환

		// 이 모드는 실패를 돌려주면 안 되므로, 쓸 수 없는 이름이 오면
		// 기준 이름으로 되돌린 뒤 아래에서 충돌을 회피한다.
		if ($name === '') { // 빈 값인 경우 쓸수 없는 이름
			$name = $baseName;
		}

		// 제안일 뿐이므로 겹치면 호스트가 바꾼다. 덮어쓰면 안 된다.
		if ($parent->nodeExists($name)) {
			$name = $parent->getNonExistingName($name); // 존재하지않는 이름 만들기
		}

		return ['reason' => NewFileResult::REASON_OK, 'name' => $name];
	}

	/**
	 * 대상 이름 헤더를 디코딩한다.
	 *
	 * 명세에서 UTF-7으로 인코딩해서 보내라고 하는데...
	 * 한글은 사용할수 없으므로 실패할 경우 UTF-8로 인코딩한다
	 */
	private function decodeTarget(string $header): string {
		$header = trim($header);

		$decoded = mb_convert_encoding($header, 'UTF-8', 'UTF-7');

		return is_string($decoded) && $decoded !== '' ? $decoded : $header;
	}

	/**
	 * 경로를 지정할수 없기 때문에 파일 이름에서 경로를 제거한다.
	 */
	private function sanitizeName(string $name): string {
		$name = str_replace(['/', '\\', "\0"], '', $name);
		$name = trim($name);

		if ($name === '' || $name === '.' || $name === '..') {
			return '';
		}

		return $name;
	}
}
