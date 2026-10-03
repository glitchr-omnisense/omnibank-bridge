<?php

namespace Omnibank\Bridge\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Bridge\Api;
use Omnibank\Bridge\Mapper;
use Omnibank\Request\FetchBalances;
use Omnibank\Request\Request;

/** The account's balance as of its last refresh (GET /v3/aggregation/accounts/{id}), and its instant balance. */
final class BalancesAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchBalances;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchBalances);
        $data = $this->api->call('GET', '/v3/aggregation/accounts/'.rawurlencode($request->account->id), $this->api->token($request->connection));
        $request->setResult(Mapper::balances($data));
    }
}
