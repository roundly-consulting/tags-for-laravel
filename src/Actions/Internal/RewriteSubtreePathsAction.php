<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Internal;

use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\LikeClause;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagPath;
use RoundlyConsulting\Tags\Support\TreeLock;

/**
 * After a tag moved: rewrite every descendant's path prefix and depth, in key-ordered batches,
 * under the caller's tree lock. Trashed descendants move too, so a later restore finds a path
 * that matches where the tree is now.
 *
 * @internal
 */
final readonly class RewriteSubtreePathsAction
{
    /**
     * @param  string  $oldPrefix  the tag's subtree prefix before it moved
     * @param  int  $depthDelta  how many levels the tag went down (negative: up)
     */
    public function execute(Tag $tag, string $oldPrefix, int $depthDelta): int
    {
        $newPrefix = TagPath::subtreePrefix($tag);

        if ($newPrefix === $oldPrefix && $depthDelta === 0) {
            return 0;
        }

        $rewritten = 0;
        $query = TreeLock::read(TagModel::query()->withTrashed()->where('type', $tag->type)->where('scope_key', $tag->scope_key));
        LikeClause::startsWith($query, 'path', $oldPrefix);

        $query->chunkById(200, static function ($descendants) use ($oldPrefix, $newPrefix, $depthDelta, &$rewritten): void {
            foreach ($descendants as $descendant) {
                TagModel::query()->withTrashed()->whereKey($descendant->getKey())->toBase()->update([
                    'path' => $newPrefix.substr($descendant->path, strlen($oldPrefix)),
                    'depth' => $descendant->depth + $depthDelta,
                ]);

                $rewritten++;
            }
        });

        return $rewritten;
    }
}
