<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\KBCategory;
use OCA\Ticketcheck\Db\KBCategoryMapper;

class CategoryService
{
    private KBCategoryMapper $mapper;

    public function __construct(KBCategoryMapper $mapper)
    {
        $this->mapper = $mapper;
    }

    /**
     * @return KBCategory[]
     */
    public function list(): array
    {
        return $this->mapper->findAll();
    }

    public function create(string $name): KBCategory
    {
        // check existing by name (case sensitive for now)
        foreach ($this->mapper->findAll() as $existing) {
            if ($existing->getName() === $name) {
                return $existing;
            }
        }
        $category = new KBCategory();
        $category->setName($name);
        // place new category at end
        $existing = $this->mapper->findAll();
        $category->setPosition(count($existing));
        $category->setCreatedAt(new \DateTime());
        $category->setUpdatedAt(new \DateTime());
        return $this->mapper->insert($category);
    }

    /**
     * Ensure the default category "General" exists
     */
    public function ensureDefaultExists(): void
    {
        foreach ($this->mapper->findAll() as $existing) {
            if ($existing->getName() === 'General') {
                return;
            }
        }
        $this->create('General');
    }

    public function update(int $id, ?string $name = null, ?int $position = null): KBCategory
    {
        $category = $this->mapper->find($id);
        if ($name !== null) {
            $category->setName($name);
        }
        if ($position !== null) {
            $category->setPosition($position);
        }
        $category->setUpdatedAt(new \DateTime());
        return $this->mapper->update($category);
    }

    public function delete(int $id): void
    {
        $category = $this->mapper->find($id);
        $this->mapper->delete($category);
    }
}
