<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Blueprint;

use \Airship\Engine\Bolt\{
    Orderable,
    Slug
};
use \Airship\Cabin\Hull\Exceptions\{
    CustomPageCollisionException,
    CustomPageNotFoundException
};
use Airship\Engine\Bolt\FileCache;

require_once __DIR__.'/gear.php';

class Blog extends BlueprintGear
{
    use Orderable;
    use Slug;
    use FileCache;

    /**
     * Sanity check; don't allow a category to belong to one of its children.
     * Returns TRUE if this check is violated.
     *
     * @param int $newParent
     * @param int $categoryId
     * @return bool
     */
    public function categoryDescendsFrom(int $newParent, int $categoryId): bool
    {
        return \in_array($categoryId, $this->getCategoryParents($newParent));
    }

    /**
     * Clears the static page cache
     *
     * @return bool
     * @throws CustomPageNotFoundException
     */
    public function clearBlogCache(): bool
    {
        foreach (\Airship\list_all_files(ROOT.'/tmp/cache/static') as $f) {
            if (\preg_match('#/([0-9a-z]+)$#', $f)) {
                \unlink($f);
            }
        }
        foreach (\Airship\list_all_files(ROOT.'/tmp/cache/csp_static') as $f) {
            if (\preg_match('#/([0-9a-z]+)$#', $f)) {
                \unlink($f);
            }
        }
        return true;
    }

    /**
     * Create a new tag
     *
     * @param array $post
     * @return mixed
     */
    public function createCategory(array $post)
    {
        $slug = $this->makeGenericSlug($post['name'], 'hull_blog_categories');
        return $this->db->insert(
            'hull_blog_categories',
            [
                'name' =>
                    $post['name'],
                'parent' =>
                    $post['parent'] > 0
                        ? $post['parent']
                        : null,
                'slug' =>
                    $slug,
                'preamble' =>
                    $post['preamble']
            ]
        );
    }

    /**
     * Create (and, optionally, publish) a new blog post.
     *
     * @param array $post
     * @param bool $publish
     * @return bool
     */
    public function createPost(array $post, bool $publish = false): bool
    {
        $this->db->beginTransaction();

        // Create the post entry
        $newPostId = $this->db->insertGet(
            'hull_blog_posts',
            [
                'author' =>
                    $post['author'],
                'category' =>
                    $post['category'] ?? null,
                'description' =>
                    $post['description'],
                'format' =>
                    $post['format'],
                'shorturl' =>
                    \Airship\uniqueId(),
                'status' =>
                    $publish,
                'slug' =>
                    $this->makeBlogPostSlug($post['title'] ?? 'Untitled'),
                'title' =>
                    $post['title'] ?? 'Untitled',
            ],
            'postid'
        );

        // Insert the initial blog post version
        $this->db->insert(
            'hull_blog_post_versions',
            [
                'post' =>
                    $newPostId,
                'body' =>
                    $post['blog_post_body'],
                'format' =>
                    $post['format'],
                'live' =>
                    $publish,
                'published_by' =>
                    $publish
                        ? $this->getActiveUserId()
                        : null

            ]
        );

        // Populate tags (only allow this if the tag actually exists)
        $allTags = $this->db->column('SELECT tagid FROM hull_blog_tags');
        foreach ($post['tags'] as $tag) {
            if (!\in_array($tag, $allTags)) {
                continue;
            }
            $this->db->insert(
                'hull_blog_post_tags',
                [
                    'postid' => $newPostId,
                    'tagid' => $tag
                ]
            );
        }

        return $this->db->commit();
    }

    /**
     * Inserts a new series, and the subsequent items, in the database
     *
     * @param array $post
     * @return bool
     */
    public function createSeries(array $post): bool
    {
        $this->db->beginTransaction();

        $series = $this->db->insertGet(
            'hull_blog_series',
            [
                'name' =>
                    $post['name'],
                'author' =>
                    $post['author'],
                'slug' =>
                    $this->makeGenericSlug($post['name'], 'hull_blog_series'),
                'preamble' =>
                    $post['preamble'] ?? '',
                'format' =>
                    $post['format'] ?? 'HTML',
                'config' =>
                    $post['config']
                        ? \json_encode($post['config'])
                        : '[]'
            ],
            'seriesid'
        );
        $insert = [
            'parent' => $series
        ];

        $listorder = 0;
        foreach (\explode(',', $post['items']) as $item) {
            list ($type, $itemid) = \explode('_', $item);
            if ($type === 'series') {
                $_insert = $insert;
                $_insert['series'] = (int) $itemid;
            } elseif ($type === 'blogpost') {
                $_insert = $insert;
                $_insert['post'] = (int) $itemid;
            } else {
                continue;
            }
            $_insert['listorder'] = ++$listorder;

            $this->db->insert('hull_blog_series_items', $_insert);
        }
        return $this->db->commit();
    }

    /**
     * Create a new tag
     *
     * @param array $post
     * @return mixed
     */
    public function createTag(array $post)
    {
        $slug = $this->makeGenericSlug($post['name'], 'hull_blog_tags');
        return $this->db->insert(
            'hull_blog_tags',
            [
                'name' => $post['name'],
                'slug' => $slug
            ]
        );
    }


    /**
     * @param int $commentId
     * @return bool
     */
    public function deleteComment(int $commentId): bool
    {
        $this->db->beginTransaction();
        $this->db->delete(
            'hull_blog_comment_versions',
            [
                'comment' => $commentId
            ]
        );
        $this->db->delete(
            'hull_blog_comments',
            [
                'commentid' => $commentId
            ]
        );
        return $this->db->commit();
    }

    /**
     * Rename a tag
     *
     * @param int $tagId
     * @param array $post
     * @return bool
     */
    public function editTag(
        int $tagId,
        array $post
    ): bool
    {
        $this->db->beginTransaction();
        $this->db->update(
            'hull_blog_tags',
            [
                'name' => $post['name']
            ], [
                'tagid' => $tagId
            ]
        );
        return $this->db->commit();
    }

    /**
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function getAllSeries(int $offset, int $limit): array
    {
        $series = $this->db->run(
            \Airship\queryString('blog.series.list_all', [
                'offset' => $offset,
                'limit' => $limit
            ])
        );
        if (empty($series)) {
            return [];
        }
        return $series;
    }

    /**
     *
     *
     * @param array $seriesIds
     * @param int $depth
     * @return array
     */
    public function getAllSeriesParents(array $seriesIds, int $depth = 0): array
    {
        if ($depth > 100) {
            return [];
        }
        $ret = [];
        foreach ($seriesIds as $ser) {
            $parents = $this->db->first(
                'SELECT parent FROM hull_blog_series_items WHERE series = ?',
                $ser
            );
            if (!empty($parents)) {
                foreach ($this->getAllSeriesParents($parents, $depth + 1) as $par) {
                    $ret[] = $par;
                }
            }
            $ret []= $ser;
        }
        return $ret;
    }

    /**
     * Get information about a blog post
     *
     * @param int $postId
     * @return array
     */
    public function getBlogPostById(int $postId): array
    {
        $post = $this->db->row('SELECT * FROM hull_blog_posts WHERE postid = ?', $postId);
        if (empty($post)) {
            return [];
        }
        return $post;
    }

    /**
     * Get the latest version of
     *
     * @param int $postId
     * @return array
     */
    public function getBlogPostLatestVersion(int $postId): array
    {
        $post = $this->db->row(\Airship\queryString('blog.posts.latest_version'), $postId);
        if (empty($post)) {
            return [];
        }
        return $post;
    }

    /**
     * Get all of a category's parents
     *
     * @param int $categoryId
     * @return array
     */
    public function getCategoryParents(int $categoryId): array
    {
        $parent = $this->db->cell('SELECT parent FROM hull_blog_categories WHERE categoryid = ?', $categoryId);
        if (empty($parent)) {
            return [];
        }
        $recursion = $this->getCategoryParents($parent);
        \array_unshift($parent);
        return $recursion;
    }

    /**
     * Get a full category tree, recursively, from a given parent
     *
     * @param int $parent
     * @param string $col The "children" column name
     * @param array $seen
     * @param int $depth How deep are we?
     *
     * @return array
     */
    public function getCategoryTree($parent = null, string $col = 'children', array $seen = [], int $depth = 0): array
    {
        if ($parent > 0) {
            $ids = $this->db->escapeValueSet($seen, 'int');
            $rows = $this->db->run("SELECT * FROM hull_blog_categories WHERE categoryid NOT IN {$ids} AND parent = ? ORDER BY name ASC", $parent);
        } else {
            $rows = $this->db->run("SELECT * FROM hull_blog_categories WHERE parent IS NULL OR parent = '0' ORDER BY name ASC");
        }
        if (empty($rows)) {
            return [];
        }
        foreach ($rows as $i => $row) {
            $_seen = $seen;
            $rows[$i]['ancestors'] = $seen;
            $_seen[] = $row['categoryid'];
            $rows[$i][$col] = $this->getCategoryTree($row['categoryid'], $col, $_seen, $depth + 1);
        }
        return $rows;
    }

    /**
     * Get a category
     *
     * @param int $categoryId
     * @return array
     */
    public function getCategoryInfo(int $categoryId = 0): array
    {
        $row = $this->db->row("SELECT * FROM hull_blog_categories WHERE categoryid = ?", $categoryId);
        if (empty($row)) {
            return [];
        }
        return $row;
    }

    /**
     * Get a blog comment
     *
     * @param int $commentId
     * @param bool $includeReplyTo Also grab the parent comment?
     * @return array
     */
    public function getCommentById(int $commentId, bool $includeReplyTo = true): array
    {
        $comment = $this->db->row(
            'SELECT * FROM hull_blog_comments WHERE commentid = ?',
            $commentId
        );
        if (empty($comment)) {
            return [];
        }
        $comment['body'] = $this->db->cell(
            'SELECT message FROM hull_blog_comment_versions WHERE comment = ? ORDER BY versionid DESC LIMIT 1',
            $commentId
        );
        if (!empty($comment['author'])) {
            $comment['authorname'] = $this->db->cell('SELECT name FROM hull_blog_authors WHERE authorid = ?', $comment['author']);
        }
        if (!empty($comment['metadata'])) {
            $comment['metadata'] = \json_decode($comment['metadata'], true);
        }
        if ($includeReplyTo) {
            if (!empty($comment['replyto'])) {
                $comment['parent'] = $this->getCommentById($comment['replyto'], false);
                $comment['blog'] = $this->getBlogPostById($comment['blogpost']);
            } else {
                $comment['parent'] = null;
                $comment['blog'] = $this->getBlogPostById($comment['blogpost']);
            }
        }
        return $comment;
    }

    /**
     * Get all of the series for all of the authors the user owns
     *
     * @param int $userId
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function getSeriesForUser(int $userId, int $offset, int $limit): array
    {
        $authorIds = $this->db->first(
            'SELECT authorid FROM hull_blog_author_owners WHERE userid = ?',
            $userId
        );

        $series = $this->db->run(
            \Airship\queryString('blog.series.list_for_author', [
                'authorids' => $this->db->escapeValueSet($authorIds, 'int'),
                'offset' => $offset,
                'limit' => $limit
            ])
        );
        if (empty($series)) {
            return [];
        }
        return $series;
    }

    /**
     * Get all of the series for a particular author
     *
     * @param int $authorId
     * @param array $exclude Series IDs to exclude
     * @return array
     */
    public function getSeriesForAuthor(int $authorId, array $exclude = []): array
    {
        $series = $this->db->run(
            'SELECT * FROM hull_blog_series WHERE author = ? AND seriesid NOT IN ' .
                $this->db->escapeValueSet($exclude, 'int') .
            ' ORDER BY name ASC',
            $authorId
        );
        if (empty($series)) {
            return [];
        }
        return $series;
    }

    /**
     * Get a single series
     *
     * @param int $seriesId
     *
     * @return array
     */
    public function getSeries(int $seriesId): array
    {
        $series = $this->db->row(
            'SELECT * FROM hull_blog_series WHERE seriesid = ?',
            $seriesId
        );
        if (empty($series)) {
            return [];
        }
        return $series;
    }

    /**
     * Get a single series
     *
     * @param int $seriesId
     *
     * @return array
     */
    public function getSeriesItems(int $seriesId): array
    {
        $items = $this->db->run(
            'SELECT i.* FROM (
                    SELECT
                        item.*,
                        series.name
                    FROM
                        hull_blog_series_items item
                    LEFT JOIN
                        hull_blog_series series ON item.series = series.seriesid
                    WHERE item.parent = ? AND post IS NULL
                UNION
                    SELECT
                        item.*,
                        post.title
                    FROM
                         hull_blog_series_items item
                    LEFT JOIN
                        hull_blog_posts post ON item.post = post.postid
                    WHERE item.parent = ? AND series IS NULL
            ) i ORDER BY i.listorder ASC',
            $seriesId,
            $seriesId
        );
        if (empty($items)) {
            return [];
        }
        return $items;
    }

    /**
     * Get a full series tree, recursively, from a given parent
     *
     * @param int $current
     * @param string $col The "children" column name
     * @param array $encountered Which IDs have we seen before?
     * @param int $depth How deep are we?
     *
     * @return array
     */
    public function getSeriesTree($current = null, string $col = 'children', array $encountered = [], int $depth = 0): array
    {
        $this->db->run(
            \Airship\queryString('blog.series.tree', [
                'valueset' => $this->db->escapeValueSet($encountered, 'int')
            ]),
            $current
        );
        if (empty($rows)) {
            return [];
        }
        foreach ($rows as $i => $row) {
            $rows[$i][$col] = $this->getSeriesTree($row['seriesid'], $col, $depth + 1);
        }
        return $rows;
    }

    /**
     * @return array
     */
    public function getTags(): array
    {
        $tags =  $this->db->run('SELECT * FROM hull_blog_tags ORDER BY name ASC');
        if (empty($tags)) {
            return [];
        }
        return $tags;
    }

    /**
     * Get data on a specific tag
     *
     * @param int $tagId
     * @return array
     */
    public function getTagInfo(int $tagId): array
    {
        $tagInfo = $this->db->row('SELECT * FROM hull_blog_tags WHERE tagid = ?', $tagId);
        if (empty($tagInfo)) {
            return [];
        }
        return $tagInfo;
    }

    /**
     * Get a list of all selected blog posts
     *
     * @param int $postId
     * @return array
     */
    public function getTagsForPost(int $postId): array
    {
        return $this->db->col("SELECT tagid FROM hull_blog_post_tags WHERE postid = ?", 0, $postId);
    }

    /**
     * @param int $commentId
     * @return bool
     */
    public function hideComment(int $commentId): bool
    {
        $this->db->beginTransaction();
        $this->db->update(
            'hull_blog_comments',
            [
                'approved' => false
            ],
            [
                'commentid' => $commentId
            ]
        );
        $latestVersion = $this->db->cell(
            'SELECT MAX(versionid) FROM hull_blog_comment_versions WHERE comment = ?',
            $commentId
        );
        $this->db->update(
            'hull_blog_comment_versions',
            [
                'approved' => false
            ],
            [
                'versionid' => $latestVersion
            ]
        );
        return $this->db->commit();
    }

    /**
     * List comments
     *
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function listComments(int $offset = 0, int $limit = 20): array
    {
        $comments = $this->db->run(
            \Airship\queryString('blog.comments.list_all', [
                'offset' => $offset,
                'limit' => $limit
            ])
        );
        $bp = [];
        foreach ($comments as $i => $com) {
            if (!\array_key_exists($com['blogpost'], $bp)) {
                $bp[$com['blogpost']] = $this->getBlogPostById($com['blogpost']);
            }
            $comments[$i]['blog'] = $bp[$com['blogpost']];
        }
        if (empty($comments)) {
            return [];
        }
        return $comments;
    }

    /**
     * Get the most recent posts
     *
     * @param bool $showAll
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function listPosts(bool $showAll = false, int $offset = 0, int $limit = 20): array
    {
        if ($showAll) {
            // You're an admin, so you get to see non-public information
            $posts = $this->db->run(
                \Airship\queryString('blog.posts.list_all', [
                    'offset' => $offset,
                    'limit' => $limit
                ])
            );
        } else {
            // Only show posts that are public or owned by one of the authors this user belongs to
            $posts = $this->db->safeQuery(
                \Airship\queryString('blog.posts.list_mine', [
                    'offset' => $offset,
                    'limit' => $limit
                ]), [
                    \Airship\LensFunctions\userid()
                ]
            );
        }
        // Always return an array
        if (empty($posts)) {
            return [];
        }
        return $posts;
    }

    /**
     * Get all of the series for a particular author
     *
     * @param int $authorId
     * @param array $exclude Series IDs to exclude
     * @return array
     */
    public function listPostsForAuthor(int $authorId, array $exclude = []): array
    {
        $series = $this->db->run(
            'SELECT * FROM hull_blog_posts WHERE author = ? AND postid NOT IN ' .
            $this->db->escapeValueSet($exclude, 'int') .
            ' ORDER BY title ASC',
            $authorId
        );
        if (empty($series)) {
            return [];
        }
        return $series;
    }

    /**
     * @param int $offset
     * @param int $limit
     * @param string $sort
     * @param bool $desc
     * @return array
     */
    public function listTags(int $offset, int $limit, string $sort = 'name', bool $desc = false): array
    {
        $orderBy = $this->orderBy(
            $sort,
            $desc ? 'DESC' : 'ASC',
            ['name', 'created']
        );
        $tags = $this->db->safeQuery(
            \Airship\queryString(
                'blog.tags.list_all',
                [
                    'orderby' => $orderBy,
                    'offset' => $offset,
                    'limit' => $limit
                ]
            )
        );
        if (empty($tags)) {
            return [];
        }
        return $tags;
    }

    /**
     * @param int $seriesId
     * @return int
     */
    public function numItemsInSeries(int $seriesId): int
    {
        return $this->db->cell('SELECT count(itemid) FROM hull_blog_series_items WHERE parent = ?', $seriesId);
    }

    /**
     * Count the number of posts. trinary:
     *    NULL -> all posts
     *    TRUE -> all published posts
     *   FALSE -> all unpublished posts
     *
     * @param mixed $published
     * @return int
     */
    public function numComments($published = null): int
    {
        if ($published === null) {
            return $this->db->cell('SELECT count(commentid) FROM hull_blog_comments');
        }
        if ($published) {
            return $this->db->cell('SELECT count(commentid) FROM hull_blog_comments WHERE approved');
        }
        return $this->db->cell('SELECT count(commentid) FROM hull_blog_comments WHERE NOT approved');
    }

    /**
     * Count the number of posts. trinary:
     *    NULL -> all posts
     *    TRUE -> all published posts
     *   FALSE -> all unpublished posts
     *
     * @param mixed $published
     * @return int
     */
    public function numPosts($published = null): int
    {
        if ($published === null) {
            return $this->db->cell('SELECT count(postid) FROM hull_blog_posts');
        }
        if ($published) {
            return $this->db->cell('SELECT count(postid) FROM hull_blog_posts WHERE status');
        }
        return $this->db->cell('SELECT count(postid) FROM hull_blog_posts WHERE NOT status');
    }

    /**
     * Count the number of series in the database
     *
     * @return int
     */
    public function numSeries(): int
    {
        return $this->db->cell('SELECT count(seriesid) FROM hull_blog_series');
    }

    /**
     * Count the number of series for a user
     *
     * @param int $userId
     * @return int
     * @throws \TypeError
     */
    public function numSeriesForUser(int $userId): int
    {
        $authorIds = $this->db->first(
            'SELECT authorid FROM hull_blog_author_owners WHERE userid = ?',
            $userId
        );
        $authorSet = $this->db->escapeValueSet($authorIds, 'int');
        return $this->db->cell(
            'SELECT count(seriesid) FROM hull_blog_series WHERE author IN ' . $authorSet
        );
    }

    /**
     * Count the number of tags
     *
     * @return int
     */
    public function numTags(): int
    {
        return $this->db->cell('SELECT count(tagid) FROM hull_blog_tags');
    }

    /**
     * @param int $commentId
     * @return bool
     */
    public function publishComment(int $commentId): bool
    {
        $this->db->beginTransaction();
        $this->db->update(
            'hull_blog_comments', [
            'approved' => true
        ], [
                'commentid' => $commentId
            ]
        );
        $latestVersion = $this->db->cell(
            'SELECT MAX(versionid) FROM hull_blog_comment_versions WHERE comment = ?',
            $commentId
        );
        $this->db->update(
            'hull_blog_comment_versions',
            [
                'approved' => true
            ],
            [
                'versionid' => $latestVersion
            ]
        );
        return $this->db->commit();
    }

    /**
     * Update a blog category
     *
     * @param int $id
     * @param array $post
     * @return bool
     * @throws \TypeError
     */
    public function updateCategory(int $id, array $post): bool
    {
        $changes = [
            'name' =>
                $post['name'] ?? 'Unnamed',
            'preamble' =>
                $post['preamble'] ?? ''
        ];
        if (!$this->categoryDescendsFrom((int) $post['parent'], $id)) {
            $changes['parent'] = $post['parent'] > 0
                ? $post['parent']
                : null;
        }
        $this->db->beginTransaction();

        $this->db->update(
            'hull_blog_categories',
            $changes,
            [
                'categoryid' => $id
            ]
        );

        return $this->db->commit();
    }

    /**
     * Update a blog post
     *
     * @param array $post
     * @param array $old
     * @param bool $publish
     * @return bool
     */
    public function updatePost(array $post, array $old, bool $publish = false): bool
    {
        $this->db->beginTransaction();
        $postUpdates = [];

        // First, update the hull_blog_posts entry
        if (!empty($post['author'])) {
            if ($post['author'] !== $old['author']) {
                $postUpdates['author'] = (int) $post['author'];
            }
        }
        if ($post['description'] !== $old['description']) {
            $postUpdates['description'] = (string) $post['description'];
        }
        if ($post['format'] !== $old['format']) {
            $postUpdates['format'] = (string) $post['format'];
        }
        if ($post['category'] !== $old['category']) {
            $postUpdates['category'] = (int) $post['category'];
        }
        if ($publish && !$old['status']) {
            $postUpdates['status'] = true;
            $now = new \DateTime('now');
            $postUpdates['published'] = $now->format('Y-m-d\TH:i:s');
        }
        if ($post['title'] !== $old['title']) {
            $postUpdates['title'] = (string) $post['title'];
        }
        if (!empty($postUpdates)) {
            $this->db->update(
                'hull_blog_posts',
                $postUpdates,
                [
                    'postid' => $old['postid']
                ]
            );
        }

        // Second, create a new entry in hull_blog_post_versions
        $this->db->insert(
            'hull_blog_post_versions',
            [
                'post' =>
                    $old['postid'],
                'body' =>
                    $post['blog_post_body'],
                'format' =>
                    $post['format'],
                'live' =>
                    $publish,
                'published_by' =>
                    $publish
                        ? $this->getActiveUserId()
                        : null
            ]
        );

        // Now let's update the tag relationships
        $tag_ins = \array_diff($post['tags'], $old['tags']);
        $tag_del = \array_diff($old['tags'], $post['tags']);
        foreach ($tag_del as $del) {
            $this->db->delete(
                'hull_blog_post_tags', [
                    'postid' => $old['postid'],
                    'tagid' => $del
                ]
            );
        }
        foreach ($tag_ins as $ins) {
            $this->db->insert(
                'hull_blog_post_tags', [
                    'postid' => $old['postid'],
                    'tagid' => $ins
                ]
            );
        }
        return $this->db->commit();
    }

    /**
     * @param int $seriesId
     * @param array $oldItems
     * @param array $post
     * @return bool
     */
    public function updateSeries(
        int $seriesId,
        array $oldItems,
        array $post
    ): bool {
        $this->db->beginTransaction();
        $newItems = \explode(',', $post['items']);

        $inserts = \array_diff($newItems, $oldItems);
        $deletes = \array_diff($oldItems, $newItems);

        foreach ($deletes as $del) {
            list ($type, $itemid) = \explode('_', $del);
            switch ($type) {
                case 'series':
                    $this->db->delete(
                        'hull_blog_series_items',
                        [
                            'parent' => $seriesId,
                            'series' => $itemid
                        ]
                    );
                    break;
                case 'blogpost':
                    $this->db->delete(
                        'hull_blog_series_items',
                        [
                            'parent' => $seriesId,
                            'post' => $itemid
                        ]
                    );
                    break;
            }
        }
        $updates = [
            'name' =>
                $post['name'],
            'preamble' =>
                $post['preamble'] ?? '',
            'format' =>
                $post['format'] ?? 'HTML',
            'config' =>
                $post['config']
                    ? \json_encode($post['config'])
                    : '[]'
        ];
        if (!empty($post['author'])) {
            $updates['author'] = $post['author'];
        }

        $this->db->update(
            'hull_blog_series',
            $updates,
            [
                'seriesid' => $seriesId
            ]
        );

        $listorder = 0;
        foreach ($newItems as $new) {
            list ($type, $itemid) = \explode('_', $new);
            if (\in_array($new, $inserts)) {
                switch ($type) {
                    case 'series':
                        $this->db->insert(
                            'hull_blog_series_items',
                            [
                                'parent' => $seriesId,
                                'series' => $itemid,
                                'listorder' => ++$listorder
                            ]
                        );
                        break;
                    case 'blogpost':
                        $this->db->insert(
                            'hull_blog_series_items',
                            [
                                'parent' => $seriesId,
                                'post' => $itemid,
                                'listorder' => ++$listorder
                            ]
                        );
                        break;
                }
            } else {
                switch ($type) {
                    case 'series':
                        $this->db->update(
                            'hull_blog_series_items',
                            [
                                'listorder' => ++$listorder
                            ],
                            [
                                'parent' => $seriesId,
                                'series' => $itemid
                            ]
                        );
                        break;
                    case 'blogpost':
                        $this->db->update(
                            'hull_blog_series_items',
                            [
                                'listorder' => ++$listorder
                            ],
                            [
                                'parent' => $seriesId,
                                'post' => $itemid
                            ]
                        );
                        break;
                }
            }
        }

        return $this->db->commit();
    }

    #=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#=#

    /**
     * Make a generic slug for most tables
     *
     * @param string $title What are we basing the URL off of?
     * @param string $year
     * @param string $month
     * @return string
     */
    protected function makeBlogPostSlug(string $title, string $year = '', string $month =''): string
    {
        if (empty($year)) {
            $year = \date('Y');
        }
        if (empty($month)) {
            $month = \date('m');
        }
        $query = 'SELECT count(*) FROM view_hull_blog_list WHERE blogmonth = ? AND blogyear = ? AND slug = ?';
        $slug = $base_slug = \Airship\slugFromTitle($title);
        $i = 1;
        while ($this->db->cell($query, $month, $year, $slug) > 0) {
            $slug = $base_slug . '-' . ++$i;
        }
        return $slug;
    }
}
