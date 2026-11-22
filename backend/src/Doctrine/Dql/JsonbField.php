<?php

declare(strict_types=1);

namespace App\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/** JSONB_FIELD(field, 'a', 'b') → PostgreSQL `field -> 'a' -> 'b'` (null where the path is missing). */
final class JsonbField extends FunctionNode
{
    private Node|string $document = '';

    /** @var list<Node> */
    private array $path = [];

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->document = $parser->StringPrimary();
        do {
            $parser->match(TokenType::T_COMMA);
            $this->path[] = $parser->StringPrimary();
        } while ($parser->getLexer()->isNextToken(TokenType::T_COMMA));
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $sql = '('.$sqlWalker->walkStringPrimary($this->document);
        foreach ($this->path as $key) {
            $sql .= ' -> '.$sqlWalker->walkStringPrimary($key);
        }

        return $sql.')';
    }
}
