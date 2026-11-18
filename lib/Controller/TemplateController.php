<?php

namespace OCA\Thinkfree\Controller;

use OCA\Thinkfree\Service\TemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * 관리자 설정 화면에서 새 문서 템플릿을 올리고 지우는 엔드포인트.
 *
 * 템플릿은 모든 사용자의 새 문서에 쓰이므로 세 메서드 모두 관리자만 호출할 수 있다
 * (NoAdminRequired 를 붙이지 않는다). 바꾸는 요청은 CSRF 토큰도 확인한다.
 */
class TemplateController extends Controller {
	private TemplateService $templates;

	public function __construct(IRequest $request, TemplateService $templates) {
		parent::__construct('thinkfree', $request);
		$this->templates = $templates;
	}

	/**
	 * 종류별로 올려 둔 템플릿이 있는지 돌려준다.
	 */
	public function index(): DataResponse {
		return new DataResponse($this->templates->describe());
	}

	/**
	 * 템플릿 파일을 올린다 (multipart 의 file 항목, Nextcloud 파일에서 골랐으면 sourceFileId 항목도).
	 */
	public function upload(string $type): DataResponse {
		$extension = TemplateService::TYPES[$type] ?? null;
		if ($extension === null) {
			return new DataResponse(['error' => 'Unknown template type'], Http::STATUS_BAD_REQUEST);
		}

		$upload = $this->request->getUploadedFile('file');
		if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new DataResponse(['error' => 'No file was uploaded'], Http::STATUS_BAD_REQUEST);
		}

		$name = (string)($upload['name'] ?? '');
		if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $extension) {
			return new DataResponse(['error' => "Choose a .$extension file"], Http::STATUS_BAD_REQUEST);
		}

		if (($upload['size'] ?? 0) > TemplateService::MAX_BYTES) {
			return new DataResponse(['error' => 'The file is too large'], Http::STATUS_BAD_REQUEST);
		}

		$content = file_get_contents($upload['tmp_name']);
		if ($content === false) {
			return new DataResponse(['error' => 'The uploaded file could not be read'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// 관리자의 Nextcloud 파일에서 골랐으면 그 파일 ID. 설정 화면이 원본을 여는 데에만 쓴다
		$source = (int)$this->request->getParam('sourceFileId', 0);

		try {
			$this->templates->store($type, $content, $name, $source > 0 ? $source : null);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($this->templates->describe());
	}

	/**
	 * 올려 둔 템플릿을 지우고 내장 템플릿으로 돌아간다.
	 */
	public function remove(string $type): DataResponse {
		if (!isset(TemplateService::TYPES[$type])) {
			return new DataResponse(['error' => 'Unknown template type'], Http::STATUS_BAD_REQUEST);
		}

		$this->templates->remove($type);

		return new DataResponse($this->templates->describe());
	}
}
