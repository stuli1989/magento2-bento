<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Profile;

/**
 * Category paths + names for medium resolution and top-category naming.
 * Level = number of path segments - 1 (root "1" is level 0, "1/2" level 1, top menu items level 2).
 */
final class CategoryTree
{
    private const TOP_CATEGORY_LEVEL = 3;

    /**
     * @param array<int,string> $paths id => "1/2/84/94"
     * @param array<int,string> $names id => name
     */
    public function __construct(
        private readonly array $paths,
        private readonly array $names
    ) {
    }

    /** @return int[] ancestors including self, root first */
    public function chain(int $categoryId): array
    {
        $path = $this->paths[$categoryId] ?? null;
        return $path === null ? [] : array_map('intval', explode('/', $path));
    }

    /**
     * Deepest mapped category across all of a product's categories (and their ancestors).
     * @param int[] $categoryIds
     * @param array<int,string> $map
     */
    public function mediumFor(array $categoryIds, array $map): ?string
    {
        $best = null;
        $bestDepth = -1;
        foreach ($categoryIds as $cid) {
            $chain = $this->chain((int)$cid);
            for ($depth = count($chain) - 1; $depth >= 0; $depth--) {
                $node = $chain[$depth];
                if (isset($map[$node])) {
                    if ($depth > $bestDepth) {
                        $best = $map[$node];
                        $bestDepth = $depth;
                    }
                    break;
                }
            }
        }

        return $best;
    }

    /** @return string[] distinct level-3 ancestor names */
    public function topCategoryNames(array $categoryIds): array
    {
        $names = [];
        foreach ($categoryIds as $cid) {
            $chain = $this->chain((int)$cid);
            $node = $chain[self::TOP_CATEGORY_LEVEL] ?? null;
            if ($node !== null && isset($this->names[$node]) && $this->names[$node] !== '') {
                $names[$node] = trim($this->names[$node]);
            }
        }

        return array_values($names);
    }
}
