<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Middleware\Wopi;

/**
 * WOPI 엔드포인트만 모인 컨트롤러 클래스에 붙인다.
 *
 * 이 클래스의 액션에 #[WopiAuthRequired] 가 없으면 WopiAuthMiddleware 가 요청을 막는다.
 * WOPI 라우트는 모두 #[PublicPage] 라서, 표시를 빠뜨린 액션은 검증 없이 열리기 때문이다.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class WopiController {
}
