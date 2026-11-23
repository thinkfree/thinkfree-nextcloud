<?php

namespace OCA\Thinkfree\Service;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;

/**
 * 관리자가 올린 새 문서 템플릿.
 *
 * "새 워드/엑셀/프레젠테이션 문서" 는 앱에 들어 있는 빈 파일(assets/doc_templates)로 만든다.
 * 관리자가 종류별로 파일을 올려 두면 그 파일이 대신 쓰이고, 모든 사용자에게 적용된다.
 * 회사 테마를 넣은 pptx 를 올려 두면 새 프레젠테이션이 처음부터 그 테마로 만들어진다.
 *
 * 파일은 앱 데이터 폴더(appdata_xxx/thinkfree/templates)에 종류 이름으로 둔다. 사용자 파일 영역이 아니라서
 * 올린 관리자의 계정이 없어져도 남는다.
 *
 * 설정 화면에 보이도록 올린 파일의 원래 이름을 앱 설정에 함께 둔다. 관리자의 Nextcloud 파일에서 골랐으면
 * 그 파일의 ID 도 둔다. 설정 화면이 그 원본을 Thinkfree Office 로 연다. 템플릿은 올린 순간의 사본이라
 * 원본을 나중에 고쳐도 템플릿은 바뀌지 않는다.
 */
class TemplateService {
	/** 종류 → 확장자. 새 문서 메뉴(DocumentController)의 type 값과 같다 */
	public const TYPES = [
		'word' => 'docx',
		'excel' => 'xlsx',
		'presentation' => 'pptx',
	];

	/**
	 * 종류 → 본문 파트의 콘텐츠 형식. [Content_Types].xml 에 이 값이 있어야 그 종류의 문서다.
	 * docx, xlsx, pptx 는 모두 ZIP 이라 앞머리만으로는 구분되지 않는다.
	 */
	private const MAIN_CONTENT_TYPES = [
		'word' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
		'excel' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
		'presentation' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
	];

	private const CONTENT_TYPES_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/content-types';

	/** 올릴 수 있는 최대 크기 */
	public const MAX_BYTES = 20 * 1024 * 1024;

	private const FOLDER = 'templates';

	private const APP_ID = 'thinkfree';

	/** 앱 설정 키 앞부분. 뒤에 종류 이름이 붙는다 (template_name_presentation) */
	private const CONFIG_NAME = 'template_name_';
	private const CONFIG_SOURCE = 'template_source_';

	private IAppData $appData;
	private IAppConfig $appConfig;

	public function __construct(IAppDataFactory $appDataFactory, IAppConfig $appConfig) {
		$this->appData = $appDataFactory->get(self::APP_ID);
		$this->appConfig = $appConfig;
	}

	/**
	 * 올려 둔 템플릿의 내용. 없으면 null 이라 호출한 쪽이 내장 파일을 쓴다.
	 */
	public function getContent(string $type): ?string {
		$file = $this->find($type);

		return $file?->getContent();
	}

	/**
	 * 설정 화면에 보일 종류별 상태.
	 *
	 * name 은 올린 파일의 원래 이름이다. 이름을 남기기 전에 올린 템플릿은 빈 문자열이다.
	 * sourceFileId 는 Nextcloud 파일에서 골랐을 때 그 파일의 ID, 아니면 null 이다.
	 *
	 * @return array<string, array{extension: string, custom: bool, size: int, mtime: int, name: string, sourceFileId: ?int}>
	 */
	public function describe(): array {
		$result = [];

		foreach (self::TYPES as $type => $extension) {
			$file = $this->find($type);
			$source = $this->appConfig->getValueInt(self::APP_ID, self::CONFIG_SOURCE . $type);

			$result[$type] = [
				'extension' => $extension,
				'custom' => $file !== null,
				'size' => $file?->getSize() ?? 0,
				'mtime' => $file?->getMTime() ?? 0,
				'name' => $file !== null ? $this->appConfig->getValueString(self::APP_ID, self::CONFIG_NAME . $type) : '',
				'sourceFileId' => $file !== null && $source > 0 ? $source : null,
			];
		}

		return $result;
	}

	/**
	 * 템플릿을 저장한다. 이미 있으면 바꾼다.
	 *
	 * @param string $name 올린 파일의 원래 이름 (설정 화면에 보인다)
	 * @param int|null $sourceFileId 관리자의 Nextcloud 파일에서 골랐으면 그 파일의 ID
	 * @throws \InvalidArgumentException 종류가 없거나 그 형식의 파일이 아닐 때
	 */
	public function store(string $type, string $content, string $name = '', ?int $sourceFileId = null): void {
		if (!isset(self::TYPES[$type])) {
			throw new \InvalidArgumentException('Unknown template type');
		}

		if (strlen($content) > self::MAX_BYTES) {
			throw new \InvalidArgumentException('The file is too large');
		}

		// 이름만 바꾼 다른 파일이 모든 사용자의 새 문서로 퍼지지 않게, 고른 종류의 문서인지 내용으로 확인한다
		if (!$this->isDocumentOfType($type, $content)) {
			throw new \InvalidArgumentException('The file is not a valid ' . self::TYPES[$type] . ' document');
		}

		$folder = $this->folder();

		$stored = $this->fileName($type);

		if ($folder->fileExists($stored)) {
			$folder->getFile($stored)->putContent($content);
		} else {
			$folder->newFile($stored, $content);
		}

		// 경로가 붙어 와도 이름만 남긴다. 화면에 그대로 보이는 값이라 길이도 줄인다
		$this->appConfig->setValueString(self::APP_ID, self::CONFIG_NAME . $type, mb_substr(basename(str_replace('\\', '/', $name)), 0, 255));

		if ($sourceFileId !== null && $sourceFileId > 0) {
			$this->appConfig->setValueInt(self::APP_ID, self::CONFIG_SOURCE . $type, $sourceFileId);
		} else {
			$this->appConfig->deleteKey(self::APP_ID, self::CONFIG_SOURCE . $type);
		}
	}

	/**
	 * 올려 둔 템플릿을 지워 내장 파일로 돌아간다. 없으면 아무 일도 하지 않는다.
	 */
	public function remove(string $type): void {
		$this->find($type)?->delete();

		if (isset(self::TYPES[$type])) {
			$this->appConfig->deleteKey(self::APP_ID, self::CONFIG_NAME . $type);
			$this->appConfig->deleteKey(self::APP_ID, self::CONFIG_SOURCE . $type);
		}
	}

	/**
	 * ZIP 이고, [Content_Types].xml 에 그 종류의 본문 파트가 있는지.
	 * pptx 를 .docx 로 이름만 바꾸면 ZIP 이지만 본문 형식이 presentation 이라 거절된다.
	 */
	private function isDocumentOfType(string $type, string $content): bool {
		// ZIP 이 아니면 압축을 풀어 보지 않는다
		if (!str_starts_with($content, "PK\x03\x04")) {
			return false;
		}

		// ZipArchive 는 파일 경로로만 열 수 있어 임시 파일에 쓴다
		$path = tempnam(sys_get_temp_dir(), 'thinkfree-template');
		if ($path === false) {
			throw new \RuntimeException('Could not create a temporary file');
		}

		try {
			file_put_contents($path, $content);

			$zip = new \ZipArchive();
			if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
				return false;
			}

			$contentTypes = $zip->getFromName('[Content_Types].xml');
			$zip->close();
		} finally {
			@unlink($path);
		}

		if (!is_string($contentTypes) || $contentTypes === '') {
			return false;
		}

		$previous = libxml_use_internal_errors(true);
		$xml = simplexml_load_string($contentTypes, \SimpleXMLElement::class, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($xml === false) {
			return false;
		}

		// children($ns) 로 얻은 요소에 ['...'] 로 접근하면 그 네임스페이스의 속성을 찾으므로, 이름공간 없는 속성은 attributes() 로 읽는다
		foreach ($xml->children(self::CONTENT_TYPES_NAMESPACE)->Override as $override) {
			if ((string)$override->attributes()['ContentType'] === self::MAIN_CONTENT_TYPES[$type]) {
				return true;
			}
		}

		return false;
	}

	private function find(string $type): ?ISimpleFile {
		if (!isset(self::TYPES[$type])) {
			return null;
		}

		try {
			return $this->appData->getFolder(self::FOLDER)->getFile($this->fileName($type));
		} catch (NotFoundException $e) {
			return null;
		}
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException $e) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}

	private function fileName(string $type): string {
		return $type . '.' . self::TYPES[$type];
	}
}
