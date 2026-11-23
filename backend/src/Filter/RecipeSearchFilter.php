<?php

declare(strict_types=1);

namespace App\Filter;

use ApiPlatform\Doctrine\Common\Filter\OpenApiFilterTrait;
use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\BackwardCompatibleFilterDescriptionTrait;
use ApiPlatform\Metadata\OpenApiParameterFilterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Parameter;
use App\Library\SearchText;
use Doctrine\ORM\QueryBuilder;

/**
 * `?q=pure patata`: every word must appear in the recipe's search text (see Recipe::refreshSearchText).
 * `?q=lentejas -chorizo`: a word after a dash must not start any of its words ("-ajo" also leaves
 * out "ajos", not "trabajo"; "-ajos" leaves out "ajo" too).
 */
final class RecipeSearchFilter implements FilterInterface, OpenApiParameterFilterInterface
{
    use BackwardCompatibleFilterDescriptionTrait;
    use OpenApiFilterTrait;

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $parameter = $context['parameter'] ?? null;
        if (!$parameter instanceof Parameter) {
            return;
        }
        $value = $parameter->getValue();
        if (!\is_string($value)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        foreach (\array_slice(SearchText::words($value), 0, 10) as $word) {
            $without = str_starts_with($word, '-');
            $word = ltrim($word, '-');
            if ('' === $word) {
                continue;
            }
            if ($without && mb_strlen($word) > 3 && str_ends_with($word, 's')) {
                $word = substr($word, 0, -1); // "-tomates" also leaves out "1 tomate"
            }
            $parameter = $queryNameGenerator->generateParameterName('q');
            $like = addcslashes($word, '%_\\').'%';
            if (!$without) {
                $queryBuilder->andWhere(\sprintf('%s.searchText LIKE :%s', $alias, $parameter))->setParameter($parameter, '%'.$like);
                continue;
            }
            // A word starts after a space or an opening bracket ("(o chorizo)").
            $bracketed = $queryNameGenerator->generateParameterName('q');
            $queryBuilder
                ->andWhere(\sprintf("CONCAT(' ', %1\$s.searchText) NOT LIKE :%2\$s AND %1\$s.searchText NOT LIKE :%3\$s", $alias, $parameter, $bracketed))
                ->setParameter($parameter, '% '.$like)
                ->setParameter($bracketed, '%('.$like);
        }
    }
}
