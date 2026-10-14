<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Util;

/**
 * 새 파일을 만드는 WOPI 오퍼레이션(PutRelativeFile, CreateChildFile)의 결과 코드.
 *
 * FileNameResolver(이름 결정)와 FileService(파일 쓰기)가 이 값을 돌려주고,
 * NewFileResponder 가 상태 코드로 옮긴다. 셋이 서로를 몰라도 되도록 여기에 모은다.
 */
final class NewFileResult {
	/**
	 * 성공
	 */
	public const REASON_OK = 'ok';

	/**
	 * 같이 사용할 수 없는 헤더
	 */
	public const REASON_BOTH_TARGETS = 'both_targets';

	/**
	 * 필요 헤더 없음
	 */
	public const REASON_NO_TARGET = 'no_target';

	/**
	 * 권한 없음
	 */
	public const REASON_FORBIDDEN = 'forbidden';

	/**
	 * 사용할 수 없는 이름
	 */
	public const REASON_ILLEGAL_NAME = 'illegal_name';

	/**
	 * 중복 이름 발생
	 */
	public const REASON_CONFLICT = 'conflict';

	/**
	 * 덮어쓰기를 요청했지만 기존 파일을 수정할 권한이 없음
	 */
	public const REASON_OVERWRITE_FORBIDDEN = 'overwrite_forbidden';

	/**
	 * write 실패
	 */
	public const REASON_WRITE_FAILED = 'write_failed';

	/**
	 * 남은 용량 부족. 쓰기 전에 확인하므로 기존 파일은 그대로다.
	 */
	public const REASON_INSUFFICIENT_STORAGE = 'insufficient_storage';
}
