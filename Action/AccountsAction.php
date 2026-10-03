<?php

namespace Omnibank\Bridge\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Bridge\Api;
use Omnibank\Bridge\Mapper;
use Omnibank\Request\FetchAccounts;
use Omnibank\Request\Request;

/** The user's accounts, every item (GET /v3/aggregation/accounts), every page. */
final class AccountsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    private const MAX_PAGES = 100;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchAccounts;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchAccounts);
        $token = $this->api->token($request->connection);
        $accounts = [];
        $path = '/v3/aggregation/accounts';
        for ($page = 0; null !== $path && $page < self::MAX_PAGES; ++$page) {
            $data = $this->api->call('GET', $path, $token);
            foreach ((array) ($data['resources'] ?? []) as $account) {
                if (\is_array($account) && isset($account['id'])) {
                    $accounts[] = Mapper::account($account);
                }
            }
            $next = $data['pagination']['next_uri'] ?? null;
            $path = \is_string($next) && '' !== $next ? $next : null;
        }

        $request->setResult($accounts);
    }
}
