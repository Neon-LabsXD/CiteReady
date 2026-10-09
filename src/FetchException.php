<?php
declare(strict_types=1);

/**
 * Помилка безпечного завантаження. $reason — машинний код:
 * invalid_domain | blocked | unreachable | too_many_redirects.
 */
final class FetchException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $detail = ''
    ) {
        parent::__construct($reason . ($detail !== '' ? ': ' . $detail : ''));
    }
}
