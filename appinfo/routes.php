<?php

declare(strict_types=1);

/**
 * Routes for the helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

return [
    'routes' => [
        // Main page route - redirects to dashboard
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#needsRole', 'url' => '/needs-role', 'verb' => 'GET'],

        // Dashboard routes
        ['name' => 'dashboard#index', 'url' => '/dashboard', 'verb' => 'GET'],
        ['name' => 'dashboard#getStats', 'url' => '/api/dashboard/stats', 'verb' => 'GET'],
        ['name' => 'dashboard#getCustomerAnalytics', 'url' => '/api/dashboard/customers/{customerId}/analytics', 'verb' => 'GET'],
        ['name' => 'dashboard#getProjectAnalytics', 'url' => '/api/dashboard/projects/{projectId}/analytics', 'verb' => 'GET'],
        ['name' => 'dashboard#getSystemAnalytics', 'url' => '/api/dashboard/system/analytics', 'verb' => 'GET'],

        // Ticket management routes
        ['name' => 'ticket#index', 'url' => '/tickets', 'verb' => 'GET'],
        ['name' => 'ticket#kanban', 'url' => '/tickets/kanban', 'verb' => 'GET'],
        ['name' => 'ticket#create', 'url' => '/tickets/create', 'verb' => 'GET'],
        ['name' => 'ticket#store', 'url' => '/tickets', 'verb' => 'POST'],
        ['name' => 'ticket#show', 'url' => '/tickets/{id}', 'verb' => 'GET'],
        ['name' => 'ticket#edit', 'url' => '/tickets/{id}/edit', 'verb' => 'GET'],
        ['name' => 'ticket#update', 'url' => '/tickets/{id}', 'verb' => 'PUT'],
        ['name' => 'ticket#updatePost', 'url' => '/tickets/{id}/update', 'verb' => 'POST'],
        ['name' => 'ticket#delete', 'url' => '/tickets/{id}', 'verb' => 'DELETE'],
        ['name' => 'ticket#changeStatus', 'url' => '/tickets/{id}/status', 'verb' => 'PUT'],
        ['name' => 'ticket#assign', 'url' => '/tickets/{id}/assign', 'verb' => 'POST'],
        ['name' => 'ticket#merge', 'url' => '/tickets/{id}/merge', 'verb' => 'POST'],
        ['name' => 'ticket#split', 'url' => '/api/tickets/{id}/split', 'verb' => 'POST'],
        ['name' => 'ticket#getLinks', 'url' => '/api/tickets/{id}/links', 'verb' => 'GET'],
        ['name' => 'ticket#addLink', 'url' => '/api/tickets/{id}/links', 'verb' => 'POST'],
        ['name' => 'ticket#removeLink', 'url' => '/api/tickets/{id}/links', 'verb' => 'DELETE'],
        ['name' => 'ticket#getWatchers', 'url' => '/api/tickets/{id}/watchers', 'verb' => 'GET'],
        ['name' => 'ticket#addWatcher', 'url' => '/api/tickets/{id}/watchers', 'verb' => 'POST'],
        ['name' => 'ticket#removeWatcher', 'url' => '/api/tickets/{id}/watchers/{userId}', 'verb' => 'DELETE'],
        ['name' => 'ticket#getProjectUsers', 'url' => '/api/tickets/projects/{projectId}/users', 'verb' => 'GET'],
        ['name' => 'ticket#searchAssignableUsers', 'url' => '/api/tickets/{id}/assignable-users', 'verb' => 'GET'],
        // Bulk assign picker — helpdesk agents/admins only (no raw UID typing).
        ['name' => 'ticket#searchBulkAssignableUsers', 'url' => '/api/tickets/assignable-users', 'verb' => 'GET'],

        // Search/filter are handled by ticket#index with query params; no separate endpoints
        // (removed ticket#search and ticket#filter - no controller methods)

        // Comment routes (update/delete not implemented; use addComment only)
        ['name' => 'ticket#addComment', 'url' => '/tickets/{id}/comments', 'verb' => 'POST'],

        // Attachment routes
        ['name' => 'ticket#uploadAttachment', 'url' => '/tickets/{id}/attachments', 'verb' => 'POST'],
        ['name' => 'ticket#downloadAttachment', 'url' => '/tickets/{ticketId}/attachments/{attachmentId}', 'verb' => 'GET'],
        ['name' => 'ticket#deleteAttachment', 'url' => '/tickets/{ticketId}/attachments/{attachmentId}', 'verb' => 'DELETE'],

        // Export routes (admin CSV export UI + API)
        ['name' => 'export#index', 'url' => '/export', 'verb' => 'GET'],
        ['name' => 'export#getOptions', 'url' => '/api/export/options', 'verb' => 'GET'],
        ['name' => 'export#searchAssignees', 'url' => '/api/export/assignees', 'verb' => 'GET'],
        ['name' => 'export#preview', 'url' => '/api/export/preview', 'verb' => 'GET'],
        ['name' => 'export#exportTickets', 'url' => '/api/export/tickets/csv', 'verb' => 'POST'],
        ['name' => 'export#exportProjects', 'url' => '/api/export/projects/csv', 'verb' => 'POST'],

        // Legacy ticket export (admin-only; prefer /export UI)
        ['name' => 'ticket#exportCsv', 'url' => '/tickets/export/csv', 'verb' => 'GET'],
        ['name' => 'ticket#exportPdf', 'url' => '/tickets/{id}/export/pdf', 'verb' => 'GET'],

        // API routes for AJAX calls
        ['name' => 'ticket#apiIndex', 'url' => '/api/tickets', 'verb' => 'GET'],
        ['name' => 'ticket#apiStore', 'url' => '/api/tickets', 'verb' => 'POST'],
        ['name' => 'ticket#searchMergeTargets', 'url' => '/api/tickets/merge-targets', 'verb' => 'GET'],
        ['name' => 'ticket#searchFilterOptions', 'url' => '/api/tickets/filter-options', 'verb' => 'GET'],
        ['name' => 'ticket#apiShow', 'url' => '/api/tickets/{id}', 'verb' => 'GET'],
        ['name' => 'ticket#apiUpdate', 'url' => '/api/tickets/{id}', 'verb' => 'PUT'],
        ['name' => 'ticket#apiDelete', 'url' => '/api/tickets/{id}', 'verb' => 'DELETE'],
        ['name' => 'ticket#bulkAction', 'url' => '/api/tickets/bulk', 'verb' => 'POST'],

        // Customer Portal routes (for guest users)
        ['name' => 'customerPortal#index', 'url' => '/portal', 'verb' => 'GET'],
        ['name' => 'customerPortal#myTickets', 'url' => '/portal/tickets', 'verb' => 'GET'],
        ['name' => 'customerPortal#projectTickets', 'url' => '/portal/projects/{projectId}/tickets', 'verb' => 'GET'],
        ['name' => 'customerPortal#createTicket', 'url' => '/portal/tickets/create', 'verb' => 'GET'],
        ['name' => 'customerPortal#storeTicket', 'url' => '/portal/tickets', 'verb' => 'POST'],
        ['name' => 'customerPortal#viewTicket', 'url' => '/portal/tickets/{id}', 'verb' => 'GET'],
        ['name' => 'customerPortal#getTicketLinks', 'url' => '/portal/api/tickets/{id}/links', 'verb' => 'GET'],
        ['name' => 'customerPortal#submitSurvey', 'url' => '/portal/tickets/{id}/survey', 'verb' => 'POST'],
        ['name' => 'customerPortal#addReply', 'url' => '/portal/tickets/{id}/reply', 'verb' => 'POST'],
        ['name' => 'customerPortal#uploadAttachment', 'url' => '/portal/tickets/{id}/attachments', 'verb' => 'POST'],
        ['name' => 'customerPortal#downloadAttachment', 'url' => '/portal/tickets/{id}/attachments/{attachmentId}', 'verb' => 'GET'],
        ['name' => 'customerPortal#checkRateLimitStatus', 'url' => '/portal/rate-limit/status', 'verb' => 'GET'],
        // Logout uses POST so it cannot be triggered via cross-site GET (logout-CSRF protection).
        ['name' => 'customerPortal#logout', 'url' => '/portal/logout', 'verb' => 'POST'],

        // Knowledge Base routes
        ['name' => 'knowledgeBase#index', 'url' => '/kb', 'verb' => 'GET'],
        ['name' => 'knowledgeBase#search', 'url' => '/kb/search', 'verb' => 'GET'],
        ['name' => 'knowledgeBase#kpIndex', 'url' => '/kp', 'verb' => 'GET'],
        ['name' => 'knowledgeBase#kpSearch', 'url' => '/kp/search', 'verb' => 'GET'],
        // Specific routes must come before parameterized routes
        ['name' => 'knowledgeBase#create', 'url' => '/kb/articles/create', 'verb' => 'GET'],
        ['name' => 'knowledgeBase#store', 'url' => '/kb/articles', 'verb' => 'POST'],
        ['name' => 'knowledgeBase#edit', 'url' => '/kb/articles/{id}/edit', 'verb' => 'GET'],
        ['name' => 'knowledgeBase#markHelpful', 'url' => '/kb/articles/{id}/helpful', 'verb' => 'POST'],
        ['name' => 'knowledgeBase#article', 'url' => '/kb/articles/{id}', 'verb' => 'GET'],
        ['name' => 'knowledgeBase#kpArticle', 'url' => '/kp/articles/{id}', 'verb' => 'GET'],
        ['name' => 'knowledgeBase#update', 'url' => '/kb/articles/{id}', 'verb' => 'PUT'],
        ['name' => 'knowledgeBase#delete', 'url' => '/kb/articles/{id}', 'verb' => 'DELETE'],

        // KB Image routes
        ['name' => 'knowledgeBase#uploadImage', 'url' => '/kb/images/upload', 'verb' => 'POST'],
        ['name' => 'knowledgeBase#getImage', 'url' => '/kb/images/{filename}', 'verb' => 'GET'],

        // KB Comment routes
        ['name' => 'knowledgeBase#addComment', 'url' => '/kb/articles/{id}/comments', 'verb' => 'POST'],
        ['name' => 'knowledgeBase#getComments', 'url' => '/kb/articles/{id}/comments', 'verb' => 'GET'],

        // KB Category management (settings)
        ['name' => 'settings#listKBCategories', 'url' => '/api/settings/kb-categories', 'verb' => 'GET'],
        ['name' => 'settings#createKBCategory', 'url' => '/api/settings/kb-categories', 'verb' => 'POST'],
        ['name' => 'settings#updateKBCategory', 'url' => '/api/settings/kb-categories/{id}', 'verb' => 'PUT'],
        ['name' => 'settings#deleteKBCategory', 'url' => '/api/settings/kb-categories/{id}', 'verb' => 'DELETE'],

        // Customer Portal KB routes
        ['name' => 'customerPortal#knowledgeBase', 'url' => '/portal/kb', 'verb' => 'GET'],
        ['name' => 'customerPortal#kbArticle', 'url' => '/portal/kb/articles/{id}', 'verb' => 'GET'],
        ['name' => 'customerPortal#kpKnowledgeBase', 'url' => '/portal/kp', 'verb' => 'GET'],
        ['name' => 'customerPortal#kpArticle', 'url' => '/portal/kp/articles/{id}', 'verb' => 'GET'],
        ['name' => 'knowledgeBase#portalMarkHelpful', 'url' => '/portal/kb/articles/{id}/helpful', 'verb' => 'POST'],
        ['name' => 'knowledgeBase#portalAddComment', 'url' => '/portal/kb/articles/{id}/comments', 'verb' => 'POST'],
        ['name' => 'knowledgeBase#portalGetComments', 'url' => '/portal/kb/articles/{id}/comments', 'verb' => 'GET'],

        // Settings routes
        // Inbound email webhook (SendGrid/Mailgun)
        ['name' => 'inboundEmail#webhook', 'url' => '/inbound-email/webhook', 'verb' => 'POST'],

        ['name' => 'settings#index', 'url' => '/settings', 'verb' => 'GET'],
        // Keep the requirement literal (route files load without the app autoloader).
        // tests/Unit/Controller/SettingsPagesContractTest pins it to SettingsSectionCatalog::routeRequirement().
        ['name' => 'settings#section', 'url' => '/settings/{section}', 'verb' => 'GET',
            'requirements' => ['section' => 'access|email|knowledge-base|kb-categories|escalation|about']],
        ['name' => 'settings#updateEmail', 'url' => '/settings/email', 'verb' => 'POST'],
        ['name' => 'settings#updateKnowledgeBase', 'url' => '/settings/knowledge-base', 'verb' => 'POST'],
        ['name' => 'settings#updateAppAccess', 'url' => '/settings/app-access', 'verb' => 'POST'],
        ['name' => 'settings#getAppAccessPreview', 'url' => '/api/settings/app-access/preview', 'verb' => 'GET'],
        ['name' => 'settings#searchNextcloudGroups', 'url' => '/api/settings/groups/search', 'verb' => 'GET'],
        ['name' => 'settings#searchNextcloudUsers', 'url' => '/api/settings/users/search', 'verb' => 'GET'],

        ['name' => 'settings#testEmail', 'url' => '/api/settings/test-email', 'verb' => 'POST'],

        // Standalone project/customer management is handled by dedicated controllers.

        // Escalation rules
        ['name' => 'settings#getEscalationRules', 'url' => '/api/settings/escalation-rules', 'verb' => 'GET'],
        ['name' => 'settings#createEscalationRule', 'url' => '/api/settings/escalation-rules', 'verb' => 'POST'],
        ['name' => 'settings#updateEscalationRule', 'url' => '/api/settings/escalation-rules/{id}', 'verb' => 'PUT'],
        ['name' => 'settings#deleteEscalationRule', 'url' => '/api/settings/escalation-rules/{id}', 'verb' => 'DELETE'],

        // Project Management routes (new simple approach)
        ['name' => 'project#index', 'url' => '/projects', 'verb' => 'GET'],
        ['name' => 'project#create', 'url' => '/projects/create', 'verb' => 'GET'],
        ['name' => 'project#edit', 'url' => '/projects/{id}/edit', 'verb' => 'GET'],
        ['name' => 'project#show', 'url' => '/projects/{id}', 'verb' => 'GET'],
        ['name' => 'project#store', 'url' => '/api/projects', 'verb' => 'POST'],
        ['name' => 'project#update', 'url' => '/api/projects/{id}', 'verb' => 'PUT'],
        ['name' => 'project#delete', 'url' => '/api/projects/{id}', 'verb' => 'DELETE'],

        // Project member routes
        ['name' => 'projectMember#addMember', 'url' => '/api/projects/{projectId}/members', 'verb' => 'POST'],
        ['name' => 'projectMember#removeMember', 'url' => '/api/projects/{projectId}/members/{userId}', 'verb' => 'DELETE'],
        ['name' => 'projectMember#updateRole', 'url' => '/api/projects/{projectId}/members/{userId}', 'verb' => 'PUT'],
        ['name' => 'projectMember#getMembers', 'url' => '/api/projects/{projectId}/members', 'verb' => 'GET'],
        ['name' => 'projectMember#getAvailableUsers', 'url' => '/api/users/available', 'verb' => 'GET'],
        ['name' => 'projectMember#bulkAdd', 'url' => '/api/projects/{projectId}/members/bulk', 'verb' => 'POST'],

        // Customer Management routes
        ['name' => 'customer#index', 'url' => '/customers', 'verb' => 'GET'],
        ['name' => 'customer#create', 'url' => '/customers/create', 'verb' => 'GET'],
        ['name' => 'customer#show', 'url' => '/customers/{id}', 'verb' => 'GET'],
        ['name' => 'customer#edit', 'url' => '/customers/{id}/edit', 'verb' => 'GET'],
        ['name' => 'customer#store', 'url' => '/api/customers', 'verb' => 'POST'],
        ['name' => 'customer#update', 'url' => '/api/customers/{id}', 'verb' => 'PUT'],
        ['name' => 'customer#delete', 'url' => '/api/customers/{id}', 'verb' => 'DELETE'],

        // Guest User Management routes
        ['name' => 'guestUser#index', 'url' => '/guests', 'verb' => 'GET'],
        ['name' => 'guestUser#create', 'url' => '/guests/create', 'verb' => 'GET'],
        ['name' => 'guestUser#edit', 'url' => '/guests/{userId}/edit', 'verb' => 'GET'],
        ['name' => 'guestUser#store', 'url' => '/api/guests', 'verb' => 'POST'],
        ['name' => 'guestUser#update', 'url' => '/api/guests/{userId}', 'verb' => 'PUT'],
        ['name' => 'guestUser#delete', 'url' => '/api/guests/{userId}', 'verb' => 'DELETE'],
        ['name' => 'guestUser#resetPassword', 'url' => '/api/guests/{userId}/reset-password', 'verb' => 'POST'],
        ['name' => 'guestUser#grantProjectAccess', 'url' => '/api/guests/{userId}/projects/{projectId}', 'verb' => 'POST'],
        ['name' => 'guestUser#revokeProjectAccess', 'url' => '/api/guests/{userId}/projects/{projectId}', 'verb' => 'DELETE'],

        // Guest self-service routes (for guest users to manage their own account)
        ['name' => 'customerPortal#changePassword', 'url' => '/portal/change-password', 'verb' => 'GET'],
        ['name' => 'customerPortal#updatePassword', 'url' => '/portal/change-password', 'verb' => 'POST'],
        ['name' => 'customerPortal#emailPreferences', 'url' => '/portal/email-preferences', 'verb' => 'GET'],
        ['name' => 'customerPortal#requestAccountDeletion', 'url' => '/portal/account/delete-request', 'verb' => 'POST'],
        ['name' => 'customerPortal#setLanguage', 'url' => '/api/guest/language', 'verb' => 'POST'],
        ['name' => 'customerPortal#dismissPortalPrivacyNotice', 'url' => '/api/guest/portal-privacy-notice', 'verb' => 'POST'],

        // Template Management routes
        ['name' => 'template#index', 'url' => '/api/templates', 'verb' => 'GET'],
        ['name' => 'template#store', 'url' => '/api/templates', 'verb' => 'POST'],
        ['name' => 'template#update', 'url' => '/api/templates/{id}', 'verb' => 'PUT'],
        ['name' => 'template#delete', 'url' => '/api/templates/{id}', 'verb' => 'DELETE'],
        ['name' => 'template#process', 'url' => '/api/templates/{id}/process', 'verb' => 'POST'],

        // Deletion analysis routes
        ['name' => 'deletion#analyzeTicket', 'url' => '/api/deletion/analyze/ticket/{id}', 'verb' => 'GET'],
        ['name' => 'deletion#analyzeCustomer', 'url' => '/api/deletion/analyze/customer/{id}', 'verb' => 'GET'],
        ['name' => 'deletion#analyzeProject', 'url' => '/api/deletion/analyze/project/{id}', 'verb' => 'GET'],
        ['name' => 'deletion#analyzeKBArticle', 'url' => '/api/deletion/analyze/kb-article/{id}', 'verb' => 'GET'],
        ['name' => 'deletion#analyzeGuestUser', 'url' => '/api/deletion/analyze/guest/{userId}', 'verb' => 'GET'],
        ['name' => 'deletion#analyzeKBCategory', 'url' => '/api/deletion/analyze/kb-category/{id}', 'verb' => 'GET'],
        ['name' => 'deletion#analyzeTemplate', 'url' => '/api/deletion/analyze/template/{id}', 'verb' => 'GET'],
        ['name' => 'deletion#analyzeAssignment', 'url' => '/api/deletion/analyze/assignment/{id}', 'verb' => 'GET'],

        // Translation loading route for JavaScript
        ['name' => 'page#getTranslations', 'url' => '/l10n/{locale}.json', 'verb' => 'GET'],

        // User email preferences (personal settings - current user only)
        ['name' => 'userPreferences#getEmailPreferences', 'url' => '/api/user/email-preferences', 'verb' => 'GET'],
        ['name' => 'userPreferences#saveEmailPreferences', 'url' => '/api/user/email-preferences', 'verb' => 'POST'],
        ['name' => 'userPreferences#dismissPortalPrivacyNotice', 'url' => '/api/user/portal-privacy-notice', 'verb' => 'POST'],

        // TKC2 mobile license (web settings — never gates browser staff/portal)
        ['name' => 'license#show', 'url' => '/api/license', 'verb' => 'GET'],
        ['name' => 'license#apply', 'url' => '/api/license', 'verb' => 'POST'],
        ['name' => 'license#remove', 'url' => '/api/license', 'verb' => 'DELETE'],
        ['name' => 'license#seats', 'url' => '/api/license/seats', 'verb' => 'GET'],
        ['name' => 'license#assignSeat', 'url' => '/api/license/seats', 'verb' => 'POST'],
        ['name' => 'license#removeSeat', 'url' => '/api/license/seats/{uid}', 'verb' => 'DELETE'],
        ['name' => 'license#searchUsers', 'url' => '/api/license/search-users', 'verb' => 'GET'],

        // Companion mobile API (S0) — Basic auth gated by ClientLicenseMiddleware
        ['name' => 'companion#bootstrap', 'url' => '/companion/api/v1/bootstrap', 'verb' => 'GET'],
        ['name' => 'companion#inbox', 'url' => '/companion/api/v1/inbox', 'verb' => 'GET'],
        ['name' => 'companion#filterOptions', 'url' => '/companion/api/v1/filter-options', 'verb' => 'GET'],
        ['name' => 'companion#queueCounts', 'url' => '/companion/api/v1/queue-counts', 'verb' => 'GET'],
        ['name' => 'companion#create', 'url' => '/companion/api/v1/tickets', 'verb' => 'POST'],
        ['name' => 'companion#bulkStatus', 'url' => '/companion/api/v1/tickets/bulk-status', 'verb' => 'POST'],
        ['name' => 'companion#show', 'url' => '/companion/api/v1/tickets/{id}', 'verb' => 'GET'],
        ['name' => 'companion#addComment', 'url' => '/companion/api/v1/tickets/{id}/comments', 'verb' => 'POST'],
        ['name' => 'companion#changeStatus', 'url' => '/companion/api/v1/tickets/{id}/status', 'verb' => 'POST'],
        ['name' => 'companion#assign', 'url' => '/companion/api/v1/tickets/{id}/assign', 'verb' => 'POST'],
        ['name' => 'companion#assignableUsers', 'url' => '/companion/api/v1/tickets/{id}/assignable-users', 'verb' => 'GET'],
        ['name' => 'companion#watch', 'url' => '/companion/api/v1/tickets/{id}/watch', 'verb' => 'POST'],
        ['name' => 'companion#downloadAttachment', 'url' => '/companion/api/v1/tickets/{id}/attachments/{attachmentId}', 'verb' => 'GET'],
        ['name' => 'companion#uploadAttachment', 'url' => '/companion/api/v1/tickets/{id}/attachments', 'verb' => 'POST'],
        ['name' => 'companion#pushRegister', 'url' => '/companion/api/v1/push/register', 'verb' => 'POST'],
        ['name' => 'companion#pushUnregister', 'url' => '/companion/api/v1/push/unregister', 'verb' => 'POST'],
    ],
];
