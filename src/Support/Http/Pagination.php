<?php
declare(strict_types=1);

namespace Z0x\WebmanStarter\Support\Http;

final class Pagination
{
    private function __construct(
        private readonly int $page,
        private readonly int $perPage,
        private readonly int $total,
        private readonly int $totalPages
    ) {
    }

    public static function fromTotal(int $page, int $perPage, int $total): self
    {
        $safePage = max($page, 1);
        $safePerPage = max($perPage, 1);
        $safeTotal = max($total, 0);

        return new self(
            page: $safePage,
            perPage: $safePerPage,
            total: $safeTotal,
            totalPages: (int) ceil($safeTotal / $safePerPage)
        );
    }

    /**
     * @return array{page: int, per_page: int, total: int, total_pages: int}
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'total_pages' => $this->totalPages,
        ];
    }
}
