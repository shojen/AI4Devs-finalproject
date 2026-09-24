<?php

namespace App\Policies;

use App\Models\BlogTag;
use App\Models\User;

/**
 * Authorization rules for the blog tag catalog (story 0059).
 *
 * Blog tags are a blog sub-resource and share the Blog module's `blog.*` permissions rather than
 * minting a `blog-tags.*` slug (D-11): the catalog's granularity is deliberately coarse per module.
 * All four abilities have a real caller -- the four App\Actions\Blog tag actions authorize
 * themselves, and FindOrCreateBlogTag asks `viewAny` on its reuse branch and `create` on its insert
 * branch.
 *
 * None of these methods branch on `$target` today; when one does, it must evaluate a freshly
 * re-fetched row, not the caller-supplied instance (docs/security/model-instance-trust.md).
 */
class BlogTagPolicy
{
    /**
     * Named once on the class that owns the rule, per naming.md's "name a permission once" rule.
     */
    public const VIEW_PERMISSION = 'blog.view';

    public const CREATE_PERMISSION = 'blog.create';

    public const EDIT_PERMISSION = 'blog.edit';

    public const DELETE_PERMISSION = 'blog.delete';

    /**
     * Determine whether the user can view the blog tag catalog.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(self::VIEW_PERMISSION);
    }

    /**
     * Determine whether the user can create a blog tag.
     */
    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo(self::CREATE_PERMISSION);
    }

    /**
     * Determine whether the user can rename a blog tag.
     */
    public function update(User $actor, BlogTag $target): bool
    {
        return $actor->hasPermissionTo(self::EDIT_PERMISSION);
    }

    /**
     * Determine whether the user can delete a blog tag.
     */
    public function delete(User $actor, BlogTag $target): bool
    {
        return $actor->hasPermissionTo(self::DELETE_PERMISSION);
    }
}
