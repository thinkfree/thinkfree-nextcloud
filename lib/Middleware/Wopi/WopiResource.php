<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Middleware\Wopi;

/**
 * WOPI 요청이 가리키는 대상의 종류. 종류에 따라 토큰이 묶인 ID 와, 미리 찾아 둘 대상이 다르다.
 */
enum WopiResource {
	/** /wopi/files/{fileId}. 토큰이 fileId 에 묶여 있고, 파일을 찾아 둔다. */
	case File;

	/** /wopi/containers/{containerId}. 토큰이 containerId 에 묶여 있고, 폴더를 찾아 둔다. */
	case Container;

	/** /wopi/ecosystem. 토큰이 WopiTokenService::ECOSYSTEM_ID 에 묶여 있고, 찾아 둘 대상이 없다. */
	case Ecosystem;
}
