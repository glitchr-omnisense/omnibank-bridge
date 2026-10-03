<?php

namespace Omnibank\Bridge\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Bridge\Api;
use Omnibank\Bridge\Mapper;
use Omnibank\Model\BankTransaction;
use Omnibank\Request\FetchTransactions;
use Omnibank\Request\Request;

/**
 * The account's booked transactions (GET /v3/aggregation/transactions,
 * account_id, since and until the dates asked), every page
 * (pagination.next_uri). Future and deleted transactions are left out, and
 * those booked out of the dates asked.
 */
final class TransactionsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    private const LIMIT = 500;
    private const MAX_PAGES = 1000;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchTransactions;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchTransactions);
        $token = $this->api->token($request->connection);
        $path = '/v3/aggregation/transactions';
        $query = array_filter(['account_id' => $request->account->id, 'since' => $request->since?->format('Y-m-d'), 'until' => $request->until?->format('Y-m-d'), 'limit' => self::LIMIT], static fn ($v) => null !== $v);

        $transactions = [];
        for ($page = 0; null !== $path && $page < self::MAX_PAGES; ++$page) {
            $data = $this->api->call('GET', $path, $token, null, $query);
            foreach ((array) ($data['resources'] ?? []) as $line) {
                if (\is_array($line) && (string) ($line['account_id'] ?? $request->account->id) === $request->account->id
                    && null !== ($transaction = Mapper::transaction($line, $request->account->currency)) && $request->covers($transaction->bookedOn)) {
                    $transactions[$transaction->id] = $transaction;
                }
            }
            $next = $data['pagination']['next_uri'] ?? null;
            [$path, $query] = \is_string($next) && '' !== $next ? [$next, []] : [null, []];
        }
        $transactions = array_values($transactions);
        usort($transactions, static fn (BankTransaction $a, BankTransaction $b) => $a->bookedOn <=> $b->bookedOn);

        $request->setResult($transactions);
    }
}
