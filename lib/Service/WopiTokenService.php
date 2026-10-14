<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Service;

use OCA\Thinkfree\Crypt;
use OCP\IConfig;
use OCP\IRequest;

/**
 * WOPI에 사용되는 액세스 토큰을 만들고 검증하는 서비스
 */
class WopiTokenService {
    public const ECOSYSTEM_ID = "ecosystem_id";

    private const TTL = 10 * 60 * 60;

	private IConfig $config;

	public function __construct(IConfig $config) {
		$this->config = $config;
	}

	/**
	 * 토큰을 발급한다.
	 *
	 * WOPI 요청을 처리하면서 응답에 싣는 토큰(GetEcosystem, GetRootContainer,
	 * EnumerateChildren 등)은 반드시 요청 토큰의 만료 시각을 넘겨받아야 한다.
	 * 새로 10시간을 주면 파일 하나의 토큰으로 다른 파일의 토큰을 받고, 만료 전에
	 * 다시 호출해 계속 연장 가능 ( token trading )
	 *
	 * @param int|null $expires 만료 시각(unix). 기본 수명보다 길면 기본 수명으로 줄인다
	 * @return array{0: string, 1: int} the token and its expiry as a unix timestamp
	 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/security#preventing-token-trading
	 */
	public function issue(string $userId, string $resourceId, bool $canWrite, ?int $expires = null): array {
		$expires = min($expires ?? PHP_INT_MAX, time() + self::TTL);

		$token = $this->crypt()->createToken([
			'jti' => bin2hex(random_bytes(16)),
			'sub' => $userId,
			'fid' => $resourceId,
			'wrt' => $canWrite,
			'iss' => 'Connector',
			'aud' => 'WOPI',
			'exp' => $expires,
		]);

		return [$token, $expires];
	}

	/**
	 * Verifies a token against the file it was issued for.
	 *
	 * expires 는 이 토큰의 만료 시각이다. 이 토큰으로 처리한 요청에서 새 토큰을
	 * 발급할 때 issue() 에 그대로 넘긴다.
	 *
	 * @return array{userId: string, canWrite: bool, expires: int}|null null when the token is
	 *         invalid, expired or was issued for a different file
	 */
	public function verify(?string $token, string $resourceId): ?array {
		if ($token === null || $token === '') {
			return null;
		}

		try {
			$crypt = $this->crypt();

			$userId = $crypt->getJwtClaim($token, 'sub');
			if ($userId === null) {
				return null;
			}

			if ((string)$crypt->getJwtClaim($token, 'fid') !== $resourceId) {
				return null;
			}

			$expires = (int)$crypt->getJwtClaim($token, 'exp');
			if ($expires <= 0) {
				return null;
			}

			return [
				'userId' => $userId,
				'canWrite' => (bool)$crypt->getJwtClaim($token, 'wrt'),
				'expires' => $expires,
			];
		} catch (\Throwable $e) {
			return null;
		}
	}

    /**
     * fid에 ECOSYSTEM_ID가 claim되어있는 jwt를 반환
     */
    public function issueForEcosystem(mixed $userId, mixed $canWrite): array
    {
        return $this->issue($userId, self::ECOSYSTEM_ID, $canWrite);
    }

    /**
     * 토큰은 쿼리 파라미터로 오거나 bearer 토큰으로 온다.
     */
    public function extractToken(IRequest $request): ?string {
        $token = $request->getParam('access_token');

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $authorization = (string)$request->getHeader('Authorization');

        if (stripos($authorization, 'Bearer ') === 0) {
            return substr($authorization, 7);
        }

        return null;
    }

    /**
     * NextCloud 시스템 내에서 사용되는 secret을 가져와서 jwt 암호화에 사용함
     */
	private function crypt(): Crypt {
		$systemSecret = (string)$this->config->getSystemValue('secret', '');

		if ($systemSecret === '') {
			throw new \RuntimeException('Nextcloud system secret is not configured');
		}

		return new Crypt(hash('sha256', 'thinkfree-wopi:' . $systemSecret));
	}
}
