<?php
/**
 * 호스트 페이지 (WopiEditorController::edit, view).
 *
 * 편집기 주소로 액세스 토큰을 폼 POST 한다. 토큰을 주소에 싣지 않아 브라우저 기록과
 * 서버 로그에 남지 않는다. js/editor.js 가 폼을 바로 제출하고, 스크립트가 꺼져 있으면
 * 사용자가 버튼을 누른다.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCP\Util;

Util::addScript('thinkfree', 'editor');
?>
<div class="guest-box">
<?php if (isset($_['error'])): ?>
	<h2>Thinkfree Office</h2>
	<p><?php p($_['error']); ?></p>
<?php else: ?>
	<form id="thinkfree-editor-form" method="post" action="<?php p($_['url']); ?>">
		<input type="hidden" name="access_token" value="<?php p($_['token']); ?>">
		<input type="hidden" name="access_token_ttl" value="<?php p($_['ttl']); ?>">
		<p><?php p($l->t('Opening Thinkfree Office…')); ?></p>
		<button type="submit" class="primary"><?php p($l->t('Open in Thinkfree Office')); ?></button>
	</form>
<?php endif; ?>
</div>
