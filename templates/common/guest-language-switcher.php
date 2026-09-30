<?php

/**
 * Guest portal language switcher (header placement).
 *
 * @var \OCP\IL10N $l
 */

$langCode = $l->getLanguageCode();
?>
<div class="tc-page-header__language" data-tc-guest-language>
	<span class="tc-page-header__language-label" id="tc-guest-language-label"><?php p($l->t('language')); ?></span>
	<div class="tc-page-header__language-options" role="radiogroup" aria-labelledby="tc-guest-language-label">
		<label class="tc-page-header__language-option">
			<input type="radio"
				name="tc-guest-language"
				value="en"
				class="tc-page-header__language-input"
				aria-label="English"
				<?php if ($langCode === 'en') {
					p(' checked');
				} ?>>
			<span class="tc-page-header__language-option-text" aria-hidden="true">EN</span>
		</label>
		<label class="tc-page-header__language-option">
			<input type="radio"
				name="tc-guest-language"
				value="de"
				class="tc-page-header__language-input"
				aria-label="Deutsch"
				<?php if ($langCode === 'de') {
					p(' checked');
				} ?>>
			<span class="tc-page-header__language-option-text" aria-hidden="true">DE</span>
		</label>
	</div>
</div>
