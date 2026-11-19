<?php

declare(strict_types=1);

namespace App\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/** JSONB_CONTAINS(field, :json) → PostgreSQL `field @> :json::jsonb`. */
final class JsonbContains extends FunctionNode
{
    private Node|string $haystack = '';
    private Node|string $needle = '';

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->haystack = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->needle = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return \sprintf(
            '(%s @> CAST(%s AS jsonb))',
            $sqlWalker->walkStringPrimary($this->haystack),
            $sqlWalker->walkStringPrimary($this->needle),
        );
    }
}
