<?php
/**
 * Settings sub-page: Support & us.
 *
 * The SupportUsLinks object is built by SettingsController::section() and passed via $_.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$supportUsLinks = $_['supportUsLinks'] ?? null;
$supportUsLanguageCode = method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en';
$supportUsCssPrefix = 'tc';
$supportUsBtnPrimaryClass = 'helpdesk-btn helpdesk-btn--primary';
$supportUsBtnSecondaryClass = 'helpdesk-btn';
include dirname(__DIR__) . '/support-us-section.php';
