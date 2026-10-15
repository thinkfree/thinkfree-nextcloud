<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Middleware\Wopi;

/**
 * 이 액션은 WOPI 인증을 먼저 통과해야 실행된다는 표시. WOPI 엔드포인트인 컨트롤러 액션에 붙인다.
 *
 * WopiAuthMiddleware 가 액션을 실행하기 전에 요청을 검증하고, 대상을 찾아
 * WopiContext 에 넣어 둔다. 검증에 실패하면 액션은 실행되지 않는다.
 * 인자는 토큰이 묶인 대상의 종류다.
 *
 *   #[WopiAuthRequired(WopiResource::File)]
 *   public function checkFileInfo(string $fileId): JSONResponse
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class WopiAuthRequired {
	public function __construct(
		public readonly WopiResource $resource,
	) {
	}
}
