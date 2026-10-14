<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Exception;

use OCP\AppFramework\Http;

/**
 * 액세스 토큰이 올바르지 않거나 만료되었을 때, 또는 토큰의 계정이 삭제되었거나
 * 비활성일 때 던진다. 401 로 응답한다.
 */
class InvalidTokenException extends WopiRequestException {
	public function getStatus(): int {
		return Http::STATUS_UNAUTHORIZED;
	}
}
