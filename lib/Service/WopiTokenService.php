<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Service;

use OCA\Thinkfree\Crypt;
use OCP\IConfig;

/**
 * WOPI에 사용되는 액세스 토큰을 만들고 검증하는 서비스
 */
class WopiTokenService {
	private const TTL = 10 * 60 * 60;

	private IConfig $config;

	public function __construct(IConfig $config) {
		$this->config = $config;
	}

	/**
	 * @return array{0: string, 1: int} the token and its expiry as a unix timestamp
	 */
	public function issue(string $userId, string $fileId, bool $canWrite): array {
		$expires = time() + self::TTL;

		$token = $this->crypt()->createToken([
			'jti' => bin2hex(random_bytes(16)),
			'sub' => $userId,
			'fid' => $fileId,
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
	 * @return array{userId: string, canWrite: bool}|null null when the token is
	 *         invalid, expired or was issued for a different file
	 */
	public function verify(?string $token, string $fileId): ?array {
		if ($token === null || $token === '') {
			return null;
		}

		try {
			$crypt = $this->crypt();

			$userId = $crypt->getJwtClaim($token, 'sub');
			if ($userId === null) {
				return null;
			}

			if ((string)$crypt->getJwtClaim($token, 'fid') !== $fileId) {
				return null;
			}

			return [
				'userId' => $userId,
				'canWrite' => (bool)$crypt->getJwtClaim($token, 'wrt'),
			];
		} catch (\Throwable $e) {
			// Crypt::getJwtClaim only catches its own namespaced Exception, so
			// a malformed or expired token surfaces here rather than as null.
			return null;
		}
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
