<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Middleware;

use OCA\Thinkfree\Exception\WopiRequestException;
use OCA\Thinkfree\Middleware\Wopi\WopiAuthRequired;
use OCA\Thinkfree\Middleware\Wopi\WopiContext;
use OCA\Thinkfree\Middleware\Wopi\WopiController;
use OCA\Thinkfree\Middleware\Wopi\WopiResource;
use OCA\Thinkfree\Service\ContainerService;
use OCA\Thinkfree\Service\FileService;
use OCA\Thinkfree\Service\RequestAuthenticator;
use OCA\Thinkfree\Service\WopiTokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * WOPI 요청의 검증을 컨트롤러 밖에서 한다.
 *
 * #[WopiAuthRequired] 가 붙은 액션을 실행하기 전에
 *   1. RequestAuthenticator 로 토큰 → 계정 → proof 서명을 확인하고
 *   2. 대상이 파일이나 폴더면 찾아서
 *   3. WopiContext 에 넣어 둔다. 컨트롤러는 거기서 꺼내 쓴다.
 *
 * 검증이나 조회에 실패하면 WopiRequestException 이 나고, afterException() 이 그 예외가
 * 들고 있는 상태 코드로 응답한다. 액션은 실행되지 않는다.
 *
 *   토큰 오류·만료, 계정 비활성   401  InvalidTokenException
 *   파일·폴더 없음, 종류가 다름    404  ResourceNotFoundException
 *   proof 서명 검증 실패          500  ProofValidationException
 *
 * Application::register() 에서 등록한다. 이 앱의 컨트롤러에만 적용된다.
 */
class WopiAuthMiddleware extends Middleware {
	private IRequest $request;
	private RequestAuthenticator $authenticator;
	private FileService $fileService;
	private ContainerService $containerService;
	private WopiContext $context;

	public function __construct(
		IRequest $request,
		RequestAuthenticator $authenticator,
		FileService $fileService,
		ContainerService $containerService,
		WopiContext $context,
	) {
		$this->request = $request;
		$this->authenticator = $authenticator;
		$this->fileService = $fileService;
		$this->containerService = $containerService;
		$this->context = $context;
	}

	public function beforeController(Controller $controller, string $methodName) {
		$required = $this->authRequiredOf($controller, $methodName);

		if ($required === null) {
			return;
		}

		$resource = $required->resource;
		$resourceId = $this->resourceIdOf($resource);

		$claims = $this->authenticator->authenticate($this->request, $resourceId, $this->describe($resource, $resourceId));
		$this->context->setAuthenticated($claims['userId'], $claims['canWrite'], $claims['expires']);

		if ($resource === WopiResource::File) {
			$this->context->setFile($this->fileService->findFile($claims['userId'], $resourceId));
		} elseif ($resource === WopiResource::Container) {
			$this->context->setFolder($this->containerService->findContainer($claims['userId'], $resourceId));
		}
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception) {
		if ($exception instanceof WopiRequestException) {
			return new JSONResponse([], $exception->getStatus());
		}

		throw $exception;
	}

	/**
	 * 액션에 붙은 #[WopiAuthRequired]. 붙어 있지 않으면 WOPI 인증이 필요 없는 액션이라 null 이다.
	 *
	 * #[WopiController] 클래스의 액션인데 표시가 없으면 LogicException 을 던져 막는다.
	 * WOPI 라우트는 모두 #[PublicPage] 라서, 그냥 지나가면 검증 없이 열린다.
	 */
	private function authRequiredOf(Controller $controller, string $methodName): ?WopiAuthRequired {
		$attributes = (new \ReflectionMethod($controller, $methodName))->getAttributes(WopiAuthRequired::class);

		if ($attributes !== []) {
			return $attributes[0]->newInstance();
		}

		if ((new \ReflectionClass($controller))->getAttributes(WopiController::class) !== []) {
			throw new \LogicException(get_class($controller) . '::' . $methodName . '() is in a #[WopiController] but is not marked #[WopiAuthRequired]');
		}

		return null;
	}

	/**
	 * 토큰이 묶여 있어야 하는 ID. 라우트 파라미터 이름은 appinfo/routes.php 와 같다.
	 */
	private function resourceIdOf(WopiResource $resource): string {
		return match ($resource) {
			WopiResource::File => (string)$this->request->getParam('fileId'),
			WopiResource::Container => (string)$this->request->getParam('containerId'),
			WopiResource::Ecosystem => WopiTokenService::ECOSYSTEM_ID,
		};
	}

	/** 로그에 남길 요청 대상 (예: "file 123") */
	private function describe(WopiResource $resource, string $resourceId): string {
		return match ($resource) {
			WopiResource::File => 'file ' . $resourceId,
			WopiResource::Container => 'container ' . $resourceId,
			WopiResource::Ecosystem => 'ecosystem',
		};
	}
}
