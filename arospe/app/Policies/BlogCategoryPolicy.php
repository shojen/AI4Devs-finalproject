<?php

namespace App\Policies;

use App\Models\BlogCategory;
use App\Models\User;

/**
 * Authorization rules for the blog category catalog (story 0058).
 *
 * Four abilities, unlike SalesRegionPolicy's two: that catalog is fixed and seeded, so its
 * create/delete permissions have no caller. Here create, rename and delete are all real call sites
 * (the three App\Actions\Blog actions authorize themselves), so all four are defined and tested.
 *
 * Blog categories are a blog sub-resource and share the Blog module's `blog.*` permissions (D-8).
 * None of these methods branch on `$target` today; when one does, it must evaluate a freshly
 * re-fetched row, not the caller-supplied instance (docs/security/model-instance-trust.md).
 */
class BlogCategoryPolicy
{
    /**
     * Named once on the class that owns the rule, per naming.md's "name a permission once" rule.
     */
    public const VIEW_PERMISSION = 'blog.view';

    public const CREATE_PERMISSION = 'blog.create';

    public const EDIT_PERMISSION = 'blog.edit';

    public const DELETE_PERMISSION = 'blog.delete';

    /**
     * Determine whether the user can view the blog category catalog.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(self::VIEW_PERMISSION);
    }

    /**
     * Determine whether the user can create a blog category.
     */
    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo(self::CREATE_PERMISSION);
    }

    /**
     * Determine whether the user can rename a blog category.
     */
    public function update(User $actor, BlogCategory $target): bool
    {
        return $actor->hasPermissionTo(self::EDIT_PERMISSION);
    }

    /**
     * Determine whether the user can delete a blog category.
     */
    public function delete(User $actor, BlogCategory $target): bool
    {
        return $actor->hasPermissionTo(self::DELETE_PERMISSION);
    }
}
