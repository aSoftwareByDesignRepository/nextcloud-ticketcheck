<?php
/**
 * Settings sub-page: Mobile license (TKC2 seats).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$licenseStatus = $_['licenseStatus'] ?? null;
$licenseSeatsList = $_['licenseSeatsList'] ?? null;
$licenseI18n = $_['licenseI18n'] ?? null;
$licenseApiUrl = (string) ($_['licenseApiUrl'] ?? '');
$licenseClearUrl = (string) ($_['licenseClearUrl'] ?? $licenseApiUrl);
$licenseSeatsUrl = (string) ($_['licenseSeatsUrl'] ?? '');
$licenseAssignSeatUrl = (string) ($_['licenseAssignSeatUrl'] ?? $licenseSeatsUrl);
$licenseRemoveSeatBase = (string) ($_['licenseRemoveSeatBase'] ?? ($licenseSeatsUrl !== '' ? rtrim($licenseSeatsUrl, '/') . '/' : ''));
$licenseSearchUsersUrl = (string) ($_['licenseSearchUsersUrl'] ?? '');
$requesttoken = (string) ($_['requesttoken'] ?? '');

include dirname(__DIR__) . '/license-panel.php';
