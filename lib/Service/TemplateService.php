<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IUserSession;

/**
 * Template Service for managing ticket response templates
 */
class TemplateService
{

    private IDBConnection $db;
    private IUserSession $userSession;

    public function __construct(
        IDBConnection $db,
        IUserSession $userSession
    ) {
        $this->db = $db;
        $this->userSession = $userSession;
    }

    /**
     * Get all active templates
     */
    public function getAllTemplates(?string $category = null): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('helpdesk_ticket_templates')
            ->where($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->orderBy('name', 'ASC');

        if ($category) {
            $qb->andWhere($qb->expr()->eq('category', $qb->createNamedParameter($category)));
        }

        $result = $qb->executeQuery();
        $templates = $result->fetchAll();
        $result->closeCursor();

        return $templates;
    }

    /**
     * Get template by ID
     */
    public function getTemplate(int $id): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('helpdesk_ticket_templates')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $template = $result->fetch();
        $result->closeCursor();

        return $template ?: null;
    }

    /**
     * Create new template
     */
    public function createTemplate(string $name, string $content, ?string $category = null): int
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $now = new \DateTime();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('helpdesk_ticket_templates')
            ->values([
                'name' => $qb->createNamedParameter($name),
                'content' => $qb->createNamedParameter($content),
                'category' => $qb->createNamedParameter($category),
                'is_active' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                'created_by' => $qb->createNamedParameter($user->getUID()),
                'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
                'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
            ]);

        $qb->executeStatement();
        return (int)$qb->getLastInsertId();
    }

    /**
     * Update template
     */
    public function updateTemplate(int $id, string $name, string $content, ?string $category = null): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('helpdesk_ticket_templates')
            ->set('name', $qb->createNamedParameter($name))
            ->set('content', $qb->createNamedParameter($content))
            ->set('category', $qb->createNamedParameter($category))
            ->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Delete template
     */
    public function deleteTemplate(int $id): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('helpdesk_ticket_templates')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Process template with variables.
     * SECURITY: Variable keys restricted to alphanumeric + underscore; values escaped for HTML safety.
     */
    public function processTemplate(int $templateId, array $variables): string
    {
        return $this->renderTemplate($templateId, $variables, true);
    }

    /**
     * Plain-text rendering for non-HTML consumers (companion composer).
     * Ticket comments are stored raw and escaped at render (p()), so
     * substituting escaped values here would double-escape for the customer.
     */
    public function processTemplatePlain(int $templateId, array $variables): string
    {
        return $this->renderTemplate($templateId, $variables, false);
    }

    private function renderTemplate(int $templateId, array $variables, bool $escapeForHtml): string
    {
        $template = $this->getTemplate($templateId);
        if (!$template) {
            throw new \Exception('Template not found');
        }

        $content = $template['content'];

        // Single-pass replacement — a substituted VALUE that happens to look
        // like a placeholder ("{{agent_name}}" inside a customer name) must
        // stay literal; sequential str_replace would resolve it in a later
        // pass (ordering-dependent injection into the wrong slot).
        $replacements = [];
        foreach ($variables as $key => $value) {
            // Only process keys that are safe placeholder names (alphanumeric + underscore)
            if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                continue;
            }
            $replacements['{{' . $key . '}}'] = $escapeForHtml
                ? htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : (string) $value;
        }

        return strtr($content, $replacements);
    }
}

