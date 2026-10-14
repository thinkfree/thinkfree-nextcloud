<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Exception;

use OCP\AppFramework\Http;

/**
 * 토큰은 맞는데 요청한 파일이나 폴더를 찾지 못했을 때 던진다. ID 가 가리키는 것이
 * 파일이 아니라 폴더인 경우처럼 종류가 다를 때도 같다. 404 로 응답한다.
 */
class ResourceNotFoundException extends WopiRequestException {
	public function getStatus(): int {
		return Http::STATUS_NOT_FOUND;
	}
}
