<?php

namespace App\Policies;

use App\Models\BlogPost;
use App\Models\User;

/**
 * Authorization rules for blog posts (story 0061).
 *
 * Posts share the Blog module's `blog.*` permissions with the two taxonomies rather than minting a
 * `blog-posts.*` slug (D-13): the catalog's granularity is deliberately coarse per module.
 *
 * Five abilities over four permission strings, the first such mapping in this repo (D-20): `restore`
 * is gated on `blog.edit`, not `blog.delete`. There is no `restore` permission to use, restore is the
 * non-destructive direction and so should not demand the destructive grant, and it keeps
 * `blog.delete` meaning exactly one thing. An actor holding `blog.delete` without `blog.edit` can
 * remove a post from view but cannot bring it back.
 *
 * None of these methods branch on `$target` today; when one does, it must evaluate a freshly
 * re-fetched row, not the caller-supplied instance (docs/security/model-instance-trust.md).
 */
class BlogPostPolicy
{
    /**
     * Named once on the class that owns the rule, per naming.md's "name a permission once" rule.
     */
    public const VIEW_PERMISSION = 'blog.view';

    public const CREATE_PERMISSION = 'blog.create';

    public const EDIT_PERMISSION = 'blog.edit';

    public const DELETE_PERMISSION = 'blog.delete';

    /**
     * Determine whether the user can view the blog post list.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(self::VIEW_PERMISSION);
    }

    /**
     * Determine whether the user can create a blog post.
     */
    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo(self::CREATE_PERMISSION);
    }

    /**
     * Determine whether the user can edit a blog post.
     */
    public function update(User $actor, BlogPost $target): bool
    {
        return $actor->hasPermissionTo(self::EDIT_PERMISSION);
    }

    /**
     * Determine whether the user can delete (soft-delete) a blog post.
     */
    public function delete(User $actor, BlogPost $target): bool
    {
        return $actor->hasPermissionTo(self::DELETE_PERMISSION);
    }

    /**
     * Determine whether the user can restore a deleted blog post. Reuses the edit permission (D-20).
     */
    public function restore(User $actor, BlogPost $target): bool
    {
        return $actor->hasPermissionTo(self::EDIT_PERMISSION);
    }
}
