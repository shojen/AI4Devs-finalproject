<?php

namespace App\Actions\Blog;

use App\Models\BlogPost;

class SyncBlogPostTags
{
    public function __construct(
        private readonly FindOrCreateBlogTag $findOrCreateBlogTag,
    ) {}

    /**
     * Replace a post's tags with exactly the submitted set. The ONLY writer of `blog_post_tag`
     * anywhere in app/, shared by CreateBlogPost and UpdateBlogPost so the logic exists once.
     *
     * Every name goes through FindOrCreateBlogTag: an existing tag (matching regardless of case or
     * accents) is reused, an unknown name is created -- and each of those inherits that action's own
     * per-branch authorization (`blog.view`/`blog.create` to reuse, `blog.create` to mint). Its
     * refusal is deliberately not swallowed: silently dropping the unmatched name would be a second,
     * undocumented reading of the editor's input.
     *
     * The post itself is not re-authorized: the caller did, one statement earlier. Callers wrap this
     * in their own transaction, so a refusal here leaves neither the post's columns nor any pivot row.
     *
     * ⚠️ `sync()` is a full replace: a name absent from `$tagNames` is DETACHED from this post (the
     * tag row itself survives). That is an editor's decision only while the tag field shows every tag
     * the post holds -- if a UI ever renders a filtered or paginated tag field, an omission stops
     * being a decision and this becomes a silent revoke (story 0061, D-17). The caller must always
     * pass the COMPLETE set.
     *
     * @param  list<string>  $tagNames
     */
    public function __invoke(BlogPost $blogPost, array $tagNames): void
    {
        $tagIds = [];

        foreach ($tagNames as $name) {
            $tagIds[] = ($this->findOrCreateBlogTag)($name)->id;
        }

        $blogPost->tags()->sync(array_values(array_unique($tagIds)));
    }
}
