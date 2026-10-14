<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Util;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;

/**
 * 새 파일을 만드는 WOPI 오퍼레이션(PutRelativeFile, CreateChildFile)의 결과를 HTTP 응답으로 옮긴다.
 *
 * 두 오퍼레이션은 결과 코드별 상태 코드와 성공 응답 형식이 같다.
 */
class NewFileResponder {
	private WopiUrlBuilder $urlBuilder;

	public function __construct(WopiUrlBuilder $urlBuilder) {
		$this->urlBuilder = $urlBuilder;
	}

	/**
	 * 결과를 HTTP 응답으로 옮긴다.
	 *
	 * 어떤 코드를 쓸지는 명세가 경우마다 정해 두었다. 권한이 없을 때 403 이
	 * 아니라 404 를 쓰는 것도 그중 하나다.
	 *
	 * @param array{reason: string, file?: File, validTarget?: string} $result
	 * @param int $expires 요청 토큰의 만료 시각. 새 파일의 토큰도 같은 시각에 만료된다
	 */
	public function toResponse(array $result, string $userId, int $expires): JSONResponse {
		switch ($result['reason']) {
			case NewFileResult::REASON_BOTH_TARGETS:
				// 명세는 400 과 501 을 모두 허용한다. 400 은 "이름이 잘못됐다"로
				// 읽히기 쉬워 규격 오해임이 드러나는 501 을 쓴다.
			case NewFileResult::REASON_OVERWRITE_FORBIDDEN:
				return new JSONResponse([], Http::STATUS_NOT_IMPLEMENTED);

			case NewFileResult::REASON_NO_TARGET:
			case NewFileResult::REASON_ILLEGAL_NAME:
				return new JSONResponse([], Http::STATUS_BAD_REQUEST);

			case NewFileResult::REASON_FORBIDDEN:
				return new JSONResponse([], Http::STATUS_NOT_FOUND);

			case NewFileResult::REASON_CONFLICT:
				$conflict = new JSONResponse([], Http::STATUS_CONFLICT);
				$conflict->addHeader('X-WOPI-ValidRelativeTarget', $result['validTarget']);

				return $conflict;

			case NewFileResult::REASON_INSUFFICIENT_STORAGE:
				return new JSONResponse([], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);

			case NewFileResult::REASON_WRITE_FAILED:
				return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$created = $result['file'];

		// 새 파일의 CheckFileInfo 주소와, 그 파일에 묶인 토큰
		return new JSONResponse([
			'Name' => $created->getName(),
			'Url' => $this->urlBuilder->forFile($created, $userId, $expires),
		]);
	}
}
