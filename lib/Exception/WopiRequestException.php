<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Exception;

/**
 * WOPI 요청을 거부할 때 던진다.
 *
 * 응답 상태 코드를 예외가 들고 있다. WopiAuthMiddleware::afterException() 이
 * 이 값으로 응답을 만들므로, 컨트롤러와 서비스는 상태 코드를 몰라도 된다.
 */
abstract class WopiRequestException extends \RuntimeException {
	abstract public function getStatus(): int;
}
