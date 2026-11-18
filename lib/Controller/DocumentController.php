<?php
namespace OCA\Thinkfree\Controller;

use OCA\Thinkfree\Service\TemplateService;
use OCP\IRequest;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Controller;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;

class DocumentController extends Controller {
    // Nextcloud 언어 코드와 내장 템플릿 폴더(assets/doc_templates) 이름이 다른 언어
    private const TEMPLATE_FOLDER_ALIASES = [
        'zh_CN' => 'zh_cn',
        'zh_TW' => 'zh_tw',
        'zh_HK' => 'zh_tw',
        // 스페인어는 es_us 템플릿 하나를 스페인과 중남미 모두에 쓴다
        'es' => 'es_us',
        'es_MX' => 'es_us',
        'es_EC' => 'es_us',
    ];

    private $userFolder;
    private $userId;
    private $l10n;
    private TemplateService $templates;

    public function __construct(
        string $appName,
        IRequest $request,
        IRootFolder $rootFolder,
        IUserSession $userSession,
        IL10N $l10n,
        TemplateService $templates,
    ) {
        parent::__construct($appName, $request);
        $this->userId = $userSession->getUser()->getUID();
        $this->userFolder = $rootFolder->getUserFolder($this->userId);
        $this->l10n = $l10n;
        $this->templates = $templates;
    }

	// 사용자 계정에 파일을 만드는 요청이라 CSRF 토큰을 확인한다. 화면(newFileMenu.js)이 requesttoken 헤더를 보낸다
	#[NoAdminRequired]
    public function create(): JSONResponse {

        try {
            $type = $this->request->getParam('type');
            $path = $this->request->getParam('path');

            if (!$type) {
                return new JSONResponse(['error' => 'type 파라미터가 필요합니다'], 400);
            }

            // 파일객체 생성
            $fileInfo = $this->getFileInfo($type);
            if (!$fileInfo) {
                return new JSONResponse(['error' => '지원되지 않는 파일 타입: ' . $type], 400);
            }

            // 경로 존재 여부에 따른 처리
            $targetFolder = $this->userFolder;

            if ($path && $path !== '/') {
                $path = trim($path, '/');

                if (!$this->userFolder->nodeExists($path)) {
                    $targetFolder = $this->userFolder->newFolder($path);
                } else {
                    $targetFolder = $this->userFolder->get($path);
                }
            }

            $filename = $this->generateUniqueFilename($fileInfo['baseName'], $fileInfo['extension'], $targetFolder);
            $file = $targetFolder->newFile($filename);
            // 관리자가 올려 둔 템플릿이 있으면 그것으로, 없으면 앱에 들어 있는 빈 파일로 만든다
            $content = $this->templates->getContent($type) ?? $this->loadTemplateContent($fileInfo['templatePath']);
            $file->putContent($content);

			$userFolderPath = $this->userFolder->getPath();
			$relativePath = ltrim(str_replace($userFolderPath, '', $file->getPath()), '/');
			$directory = dirname($relativePath);

			return new JSONResponse([
				'basename' => $filename,
				'fileid' => $file->getId(),
				'mime' => $file->getMimeType(),
				'type' => 'file',
				'path' => $directory,
				'source' => $relativePath,
				'mtime' => $file->getMTime(),
				'size' => $file->getSize()
			]);

			return new JSONResponse($responseData);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => '파일 생성 실패'], 500);
        }
    }

    private function getFileInfo(string $type): ?array {
        $locale = $this->l10n->getLanguageCode() ?? 'en';
        $fileTypes = [
            'excel' => [
                'baseName' => $this->l10n->t('New Excel Document'),
                'extension' => '.xlsx',
                'template' => 'new.xlsx'
            ],
            'word' => [
                'baseName' => $this->l10n->t('New Word Document'),
                'extension' => '.docx',
                'template' => 'new.docx'
            ],
            'presentation' => [
                'baseName' => $this->l10n->t('New Presentation Document'),
                'extension' => '.pptx',
                'template' => 'new.pptx'
            ]
        ];

        $info = $fileTypes[$type] ?? null;
        if ($info) {
            $info['templatePath'] = $this->findTemplatePath($locale, $info['template']);
        }

        return $info;
    }

    /**
     * 사용자 언어에 맞는 내장 템플릿 경로를 찾는다.
     *
     * 언어 코드 그대로 → 대응표 → 언어 부분만(es_MX → es) → 그 대응표 순서로 폴더를 찾는다.
     * 모두 없으면 언어 코드 그대로의 경로를 돌려주고, loadTemplateContent 가 en 으로 대신한다.
     *
     * getLanguageCode() 는 이 앱에 번역 파일(l10n/{코드}.json)이 있는 언어만 돌려준다.
     * 그래서 템플릿을 추가한 언어는 번역 파일도 함께 있어야 그 템플릿이 쓰인다.
     */
    private function findTemplatePath(string $locale, string $fileName): string {
        $language = explode('_', $locale)[0];
        $candidates = array_unique(array_filter([
            $locale,
            self::TEMPLATE_FOLDER_ALIASES[$locale] ?? null,
            $language,
            self::TEMPLATE_FOLDER_ALIASES[$language] ?? null,
        ]));

        foreach ($candidates as $folder) {
            $path = __DIR__ . "/../../assets/doc_templates/{$folder}/{$fileName}";
            if (file_exists($path)) {
                return $path;
            }
        }

        return __DIR__ . "/../../assets/doc_templates/{$locale}/{$fileName}";
    }

    private function loadTemplateContent(string $templatePath): string {
        if (!file_exists($templatePath)) {
            $defaultPath = __DIR__ . "/../../assets/doc_templates/en/" . basename($templatePath);
            if (!file_exists($defaultPath)) {
                // TODO. 관련 메시지를 위한 locale 리소스 정의필요.
                throw new \Exception("템플릿 파일을 찾을 수 없습니다: {$templatePath}");
            }
            $templatePath = $defaultPath;
        }

        return file_get_contents($templatePath);
    }

	private function generateUniqueFilename(string $baseName, string $extension, \OCP\Files\Folder $targetFolder): string {
		$filename = $baseName . $extension;
		$counter = 1;
		while ($targetFolder->nodeExists($filename)) {
			$filename = $baseName . ' (' . $counter . ')' . $extension;
			$counter++;
		}
		return $filename;
	}
}
